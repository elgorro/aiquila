# Batch processing

Anthropic's Message Batches API takes many message requests at once, runs them
asynchronously, and charges roughly **half** the token price on both sides. What
it costs in return is latency: a batch is allowed to take up to **24 hours**, and
there is no way to ask for less.

That trade is worth taking wherever nobody is waiting for the answer — a
scheduled job over a folder of documents, an Assistant task already running in a
background worker — and worth refusing everywhere else.

This applies to the **Anthropic provider only** (`ClaudeSDKService`). No other
provider has an equivalent, so the paths below branch on the provider type
rather than putting a batch method on `LLMProviderInterface`.

## What goes through a batch

| Path | Entry point | Shape |
|---|---|---|
| Assistant "Summarize" task | `SummaryProvider` → `summarizeViaBatch()` | one request, blocking poll |
| Cowork `docs:summarize` / `docs:translate` / `docs:ask` / `docs:extract` | `BatchDocumentRunner` via `AbstractBatchTextTaskType` | N requests, submit now, collect later |
| Manual runs over files and folders | `BatchDocumentRunner` via `BatchRunService` | the same, started once from Files or the API |

Everything else — chat, the agentic tool loop, the other Assistant task types —
is synchronous. A person is waiting.

## The transport

Four methods on `ClaudeSDKService`, deliberately thin so a caller that cannot
hold a worker open can drive a batch across process boundaries:

| Method | Does |
|---|---|
| `submitBatch(array $requests, ?string $userId)` | Sends N requests, returns the batch id |
| `getBatchStatus(string $batchId, ?string $userId)` | `status`, `ended`, and per-outcome counts |
| `fetchBatchResults(string $batchId, ?string $userId)` | Every result of an ended batch, keyed by `custom_id` |
| `cancelBatch(string $batchId, ?string $userId)` | Best-effort stop; requests already in flight still bill |

Each entry passed to `submitBatch()` is `{custom_id, messages, options?}`, where
`options` is the same per-request option array `ask()` and `chat()` accept — so
a caller can pin a model or an effort level per request. `custom_id` must match
`[a-zA-Z0-9_-]{1,64}` and be unique within the batch; both are checked before
anything is sent, so a bad id names itself instead of failing the whole batch.
`MAX_BATCH_REQUESTS` caps a batch at 500 as a cost guard, well under what the
API would accept.

The actual SDK calls sit behind `callBatchCreate()`, `callBatchRetrieve()`,
`callBatchResults()` and `callBatchCancel()`, which are `protected` so tests can
stand in for them without a network.

## Results are unordered

**The API returns results in no particular order.** `fetchBatchResults()` is
therefore keyed by `custom_id`, never indexed by position, and every caller must
look results up by the id it submitted.

This is the single load-bearing rule in the design. Reading results positionally
does not fail loudly — it silently pairs one request with another request's
answer, so a folder of documents gets summaries belonging to their neighbours.
`occ aiquila:benchmark-batch` prints how many results arrived out of order on a
real run, so the rule is demonstrable rather than asserted.

## Per-request outcomes

At one request per batch an error is just an error. At N it matters *which*
kind, because they mean different things to whoever has to act on the run. Every
failure from `fetchBatchResults()` carries an `error_type`:

| `error_type` | Means | Worth retrying? |
|---|---|---|
| `refused` | The safety classifiers declined the request | No |
| `errored` | The API rejected it — too long, malformed, and so on | After fixing the input |
| `expired` | The batch ran out of time before reaching it | Yes |
| `canceled` | The batch was cancelled | Yes |
| *absent from the map* | No result came back for that `custom_id` at all | Yes |

A refusal is the awkward one: it arrives as a **succeeded** result whose message
stopped with `refusal`, not as an error. Without unpacking that it would read
back as an empty response, so `convertBatchResult()` turns it into a failure with
the refusal category attached.

Refusals are also terminal here. The Messages API can fall back to another model
server-side; **the Batch API cannot**, and returns no fallback credit either. If
a refused request has to be re-issued, that is the caller's decision and it has
to happen synchronously.

## What batches do not support

- **Fast mode.** `toBatchParams()` drops `speed` deliberately; sending it would
  have the API reject the batch, and batch work is not latency-sensitive anyway.
- **Server-side refusal fallbacks**, per above.
- **A deadline shorter than 24 hours.** There is no priority batch.

## Extended output

The `output-300k-2026-03-24` beta raises the output ceiling on batch requests
from 128,000 to 300,000 tokens. It is off by default and turned on with the
**Extended output on batch requests** admin setting (`batch_output_300k`).

Two things have to line up, and the switch does both:

1. The beta header goes on the create call. It rides on the GA endpoint as an
   extra header rather than going through `$client->beta->messages->batches` —
   the beta only changes what the API accepts, and staying on GA keeps one set
   of result types and one place that unpacks them.
2. The output cap is re-clamped against the higher ceiling. `getMaxTokens()`
   clamps against the model's ordinary 128,000, so the header alone would change
   nothing.

Supported on Opus 5, Opus 4.8/4.7/4.6, Sonnet 5 and Sonnet 4.6. On any other
model the switch is ignored — no header, no lifted ceiling, no error — because
sending the header where it is not accepted risks a 400 on the whole batch.

It lifts a cap rather than setting one. An instance left on the default
**Max output tokens** sees no difference until that is raised too. Synchronous
requests are unaffected either way.

## Blocking or resuming

The rule: **a TaskProcessing worker may block on a one-request batch; a cron
worker may not block on anything.**

`summarizeViaBatch()` blocks — it polls every 5 s for up to about 20 minutes.
That is affordable because it is one request (typically back in seconds, the cap
is only a backstop) and because Nextcloud's TaskProcessing already owns a
long-running task lifecycle with a progress channel.

The cowork tasks cannot. Nextcloud's cron worker runs background jobs one after
another in a single process, so a task holding it open for hours would starve
every other job on the instance. They submit and return instead:

| Tick | What happens |
|---|---|
| The scheduled run | Collect files → one `submitBatch()` → persist state → return `pending` |
| Every 5 minutes after | `CoworkerBatchPollJob` → `resumePending()` → `getBatchStatus()` |
| Once ended | `fetchBatchResults()`, write one output file per success, close the run |

The resume state lives in `aiquila_coworker_runs.state` as JSON: the batch id,
the map from `custom_id` to file id, and when it was submitted. A run whose batch
has not ended 26 hours after submission is failed — Anthropic expires a batch at
24, so past that nothing will ever finish it.

A coworker with an open `pending` run will not start another. Two batches over
the same folder would bill it twice and race two runs onto the same output files.

## The document pipeline

`BatchDocumentRunner` (`lib/Batch/`) is the pipeline: walk the files, build one
request per document, submit them as a batch or work through them synchronously,
and later collect the results into output files. It knows nothing about
coworkers. Everything about one run comes in a `BatchContext`:

| Field | Carries |
|---|---|
| `userId` | whose files are read and written, and who is billed |
| `operation` | a `BatchOperation`: the per-file prompt, output suffix, done-tag and summary verb |
| `options` | the shared options (`recursive`, `force`, `maxBytesPerFile`, `outputFolder`, `mimeTypes`) plus the operation's own |
| `requestOptions` | a closure building the per-request provider options for whichever provider the run ends up on |
| `providerId` | the provider to ask for; null is the user's default |
| `outputFolder` | where outputs go; empty writes each beside its source |
| `logContext`, `usageType` | server log context and the usage-stat type |

It has three steps, and the caller owns the run row in between:

| Method | Does |
|---|---|
| `collect($ctx, $root, $skipped)` | the files under a node that still need work, capped; due files whose output's name is taken go to `$skipped` |
| `process($ctx, $run, $files, $progress)` | submits one batch (`pending` + state) or runs the synchronous loop to the end |
| `resume($ctx, $run, $state, $progress)` | polls the batch, and collects the results once it has ended |

The operations are `SummarizeOperation` and `TranslateOperation`. A coworker
task type is a thin adapter: `AbstractBatchTextTaskType` builds the context from
the coworker row (its provider, its output path, its model, effort and thinking,
and the admin's instance instructions), and `DocsSummarizeFolderTaskType` /
`DocsTranslateFolderTaskType` only name their operation. On resume, the context
is built from the options stored in the run's state, so a coworker edited while
its batch was in flight still writes where the run said it would.

## The bulk cowork tasks

`docs:summarize`, `docs:translate` and `docs:ask` walk the coworker's input
folder and write one output file per document — `<file>.summary.md`,
`<file>.<language>.md`, `<file>.<outputSuffix>.md` — into the configured output
folder, or beside the source when none is set. `<file>` is the source's whole
name (`report.pdf.summary.md`), so sources that differ only in their extension
never share an output. Earlier releases left the extension out
(`report.summary.md`). Such an output stays in use, and is updated in place, while
it carries the `aiquila:output` tag and can belong to one source only. With several documents of that base name in the
folder it counts for none of them and is left alone, and each gets an output of
its own. The source is tagged
`aiquila:summarized` / `aiquila:translated` / `aiquila:asked-<hash>` so
processed files can be filtered in the Files UI.

`docs:ask` (`AskOperation`) asks a question the user wrote of every document.
Its options are `prompt` (required, at most 4,000 characters) and
`outputSuffix` (required: 1–32 lowercase letters, digits or hyphens, and not a
file type such as `pdf` or another operation's `summary`, so the output is always
one `.md` file beside its source). The admin's instance instructions are still
the system prompt.

Its done-tag is **per question**: `aiquila:asked-` and the first 8 hex digits of
a SHA-256 of the prompt and the suffix. It implements `PromptScopedOperation`, so
a source counts as done only while its output is fresh *and* it carries the
current question's tag. Editing the prompt therefore answers every document
again, where a fresh output alone would leave them. Two coworkers that ask the
same question share the tag. The fixed operations keep the fresh-output rule.

### Structured extraction (`docs:extract`)

`ExtractOperation` pulls the same fields out of every document into one table.
It is a `TableOperation`: instead of a file per source, the runner holds one row
per document and, once the run has its answers, upserts them into
`<tableName>.csv` (default `extracted.csv`) in the output folder, or in each
source's folder when none is set. So there is one table per folder.

- **Fields** are a list, not raw JSON Schema: `{name, type, description}`, 1 to
  30 of them. Names are lowercase `[a-z][a-z0-9_]{0,39}`, unique, and not
  `file_id`, `file` or `extracted_at`. Types are `string`, `number`, `integer`,
  `boolean` and `date`. The JSON Schema is built from the list, every property
  nullable and required, with `additionalProperties: false`.
- **Structured outputs:** on the Anthropic provider, for models documented to
  support JSON outputs (`ClaudeModels::supportsStructuredOutputs()`), the schema
  goes out as `output_config.format`, in batches too. Every other model and
  provider gets the same prompt asking for JSON only, and so does every request
  while the **Structured outputs for data extraction** admin setting
  (`structured_outputs`, on by default, one per Claude card) is off. It is there for
  organisations with customer-managed encryption keys: Anthropic
  [turns structured outputs off](https://platform.claude.com/docs/en/manage-claude/cmek-coverage-claude-platform)
  for them on Fable and Mythos models, and the API offers no way to detect it. On every path the answer is
  then checked in code (`parseRow()`): the outermost object is taken, a value is
  coerced to its type (`"12.50"` → `12.5`, a real `YYYY-MM-DD` date), and a missing
  field is empty. An answer that holds no object, or a value of the wrong type,
  fails that file as *invalid*. It gets no row and is tried again on the next run.
- **The table** is RFC 4180 CSV (CRLF, fields quoted when needed) with the
  columns `file_id`, `file`, the fields, and `extracted_at` (UTC). Rows are keyed
  by file id: a rerun replaces a document's row and keeps the others. After a
  schema change, older rows keep their values for the columns that remain, and
  new columns stay empty until the document is extracted again.
- **Formula injection:** the documents are untrusted, and the table is meant to
  be opened in a spreadsheet. A text cell starting with `=`, `+`, `-`, `@`, a tab
  or a carriage return gets a leading `'`. Numbers are written as numbers.
- **Incremental runs:** a source is done while its row is at least as new as the
  source *and* it carries the schema's done-tag, `aiquila:extracted-<hash>` (a
  hash of the field list and the table name). An edited document is extracted
  again, and a changed field list extracts every document again.
- **Partial failures:** if the table cannot be written, its rows are reported as
  *unsaved*, and their sources stay untagged, so the next run does them again.

Files, rather than tags or comments, because a summary or a translation is prose
that a system tag cannot hold and a Nextcloud comment truncates.

Writing output into the tree being read makes four things load-bearing, all
decided during the walk rather than after it:

- A source whose output is at least as new as itself is skipped, so a nightly
  coworker does not re-bill the whole folder every night. `force` overrides it.
- An output is never a source. Every output file carries the hidden system tag
  `aiquila:output`, and the walk skips files that have it, checked in one query
  per folder. Otherwise each run would summarise the last run's summaries and
  grow another `.summary.md.summary.md` generation every night, and translating a
  folder would translate its summaries too. A file whose name ends in the
  task's own output suffix is skipped as well, which covers outputs written
  before the tag existed.
- Only outputs are replaced. A file that has the output's name but not the
  `aiquila:output` tag is the user's: its source is left out before anything is
  sent and reported as skipped, `force` or not. The write checks again and fails
  the item as *unsaved*, since a batch may take a day to end. For `docs:extract`
  the same goes for the table, where a table's header counts as well as the tag.
  An untagged file under the name without the extension (`report.summary.md`)
  may be an output from before the tag existed. It is never written to; it counts
  as done while it is fresh and its source carries the done-tag.
- The configured output folder is skipped during the walk, since it is normally
  nested inside the input path.

These checks run *inside* the walk because the walk stops at the item cap.
Applied afterwards, the first 200 already-finished files would fill the quota
every night and everything past them would never be reached.

A run is capped at `MAX_ITEMS_PER_RUN` (200) files, and a file larger than
`maxBytesPerFile` (1 MiB by default) is skipped rather than truncated — half a
contract summarised as if it were the whole thing is worse than no summary.

**Text, PDFs and Office documents.** The default mime types are the `text/`
family, `application/json`, `application/pdf` and the Office types
(`OFFICE_MIME_TYPES`: Word, Excel, PowerPoint and their OpenDocument
counterparts, documents and templates). Text goes into the prompt, and content
that is not valid UTF-8 is skipped: a batch goes out as one request for the whole
folder, outside any per-file guard, so one binary file would take the entire run
down rather than just itself.

A PDF travels as its own `document` block, followed by the operation's
`buildDocumentPrompt()` (a translation comes back as Markdown, not as a PDF):

- **Anthropic provider only.** No other provider builds the block. There, a
  coworker's walk leaves PDFs out, so they do not take the run's quota every
  night, and a manual run lists each one as skipped with the reason.
- **Batch, Files API allowed:** each PDF is uploaded with an expiry of 30 hours
  and referenced by `file_id`, so the batch stays small whatever the PDFs weigh.
  The run deletes its uploads once it has collected the results, when the
  submission fails, when the batch is lost, and when the run is cancelled. The
  expiry covers a run that never comes back. A PDF whose upload fails is
  skipped on its own.
- **Batch, Files API off** (switched off, or zero data retention): the PDF goes
  inline as base64. A batch holds at most 256 MB, so inline PDFs are capped at
  192 MB of base64 per batch, and the rest is left for the next run.
- **Immediate:** one request per PDF, inline.
- **Limits:** a PDF over 32 MB is skipped (`maxBytesPerFile` applies to text
  only), as is a file named `.pdf` that does not start with `%PDF-`. The API reads
  at most 600 pages (100 under a 1M-token context) and 128 MB per request. A PDF
  over that fails as its own request, reported as *errored*.
- **Cost:** each page is billed as text (typically 1,500–3,000 tokens) plus an
  image of the page (at most 1,568 tokens). A PDF costs far more than the same
  text would.

An Office document is converted to PDF and then takes the PDF path, so the
model sees its layout, tables and charts too:

- **Converter:** Nextcloud's file conversion API (`IConversionManager`), which
  Nextcloud Office (`richdocuments`, backed by Collabora) provides for every type
  above. Without a provider, or for a type no provider turns into PDF, a walk
  leaves the file out and a manual run lists it as skipped with the reason
  (*install Nextcloud Office*). AIquila ships no converter of its own: an
  in-process OOXML/ODF parser would add a dependency and attack surface, and
  plain text would lose the tables.
- **Where the PDF goes:** the conversion API always writes its result as a file.
  It goes to `appdata_<instance>/aiquila/conversions/`, is read back and deleted
  straight away, and never appears in the user's Files. Leftovers of a worker
  that died are removed after an hour.
- **Limits:** the source and the PDF are each held to the 32 MB PDF limit, and
  Nextcloud's own `max_file_conversion_filesize` (100 MB by default) applies
  first. A conversion that fails, for example because Collabora is unreachable,
  skips that file with the converter's message.

On a non-Anthropic provider the same tasks fall back to a synchronous per-file
loop at full price, and the run summary says which path it took.

## Manual runs

A user can run an operation once over files and folders they pick, with no
coworker: the **Process with AIquila…** action in Files, or the API below. It
reuses the pipeline through `BatchRunService`.

**Run model.** A manual run is a row on `aiquila_coworker_runs` with
`coworker_id` 0 and `started_by` `selection`. `task_type` names its operation
and `outputs` lists the files it wrote. It shares the run log, the resume state
and the cancel semantics with coworker runs. `CoworkerBatchPollJob` sends a
pending manual run to `BatchRunService::resume()` and every other pending run
to `CoworkerService`. Manual runs are cancelled before their user is deleted,
and removed after.

**Lifecycle.** Starting a run never does the work in the HTTP request:

| Step | Status | What happens |
|---|---|---|
| `POST /api/batch-runs` | `queued` | input checked, run inserted, `BatchRunJob` queued |
| `BatchRunJob` | `running` | inputs resolved again, files collected, then `immediate` works through them, `batch` submits |
| `CoworkerBatchPollJob` | `pending` | as for a coworker, until the batch has ended |
| end | `success` / `error` / `cancelled` | outputs, counts and summary on the run |

The job claims the run with a conditional update from `queued` to `running`,
so a run cancelled before it started, or picked up twice, is left alone.

**Mode.** `batch` costs half and may take up to 24 hours. `immediate` is one
synchronous request per file at full price. The default is `batch` when the
user's provider has a batch API, and otherwise every run is immediate.

**Input.** `fileIds` and `folderIds`, resolved through the user's own view of
their files, so a file shared with them works like one they own:

- An id the user cannot see or read is a `404` that names it. Both cases get
  the same answer, so ids cannot be probed.
- Folders are walked recursively unless `recursive` is false, by the coworker
  rules: up-to-date sources (unless `force`), outputs of any operation and
  the output folder are skipped.
- Files picked one by one are always processed, since the user asked for them.
  Only the mime filter applies, plus the PDF and converter checks and the check
  that their output replaces no file of the user's.
- A file that fails the mime filter, picked or found in a folder, is skipped
  and reported. Up-to-date sources and earlier outputs are left out silently.
- A file reached twice counts once.

**Output.** Each output goes beside its source, or into `outputFolder`, which
must be a folder the user can create files in (it is created if missing). A
source whose own folder is read-only is skipped before anything is billed,
with a log line saying to choose an output folder.

**Limits.**

| Limit | Value | Over it |
|---|---|---|
| Files per run | 200 (`MAX_ITEMS_PER_RUN`, as for a coworker) | more ids is a `400` with `limit`; a folder walk stops there, and the run log says so. Running it again picks up the rest, since finished files are skipped |
| Runs per user per hour | 12 (the coworker trigger cap) | `429` with `limit` |
| Requests to start or cancel | 20 per minute | Nextcloud's rate limiter |

**API.** Plain app routes, like the coworker API, so the MCP server reaches
them with the user's app password. All of them are `NoAdminRequired` and
`NoCSRFRequired`, and described in `openapi.json`.

| Route | Returns |
|---|---|
| `GET /api/batch-runs/operations` | operations and their option names, `modes`, `defaultMode`, `batchAvailable`, `maxItems`, `maxRunsPerHour` |
| `POST /api/batch-runs` | `{operation, options, mode, fileIds, folderIds}` → `202` with the queued run |
| `GET /api/batch-runs` | the user's manual runs, newest first |
| `GET /api/batch-runs/{id}` | the run with its log |
| `POST /api/batch-runs/{id}/cancel` | the cancelled run; `409` once it has ended |

**Skips.** A file the run passes over without asking the model counts in
`itemsSkipped`, neither processed nor failed. Each one gets a `warn` log entry,
`Skipped: <reason>`, with the file as its item, and a `Skipped: <name> — <reason>`
line in the summary:

| Reason | When |
|---|---|
| `Not a text, PDF or Office document (<mime>)` | it fails the mime filter |
| `Its folder is read-only; choose an output folder` | its output cannot be written beside it |
| `<name> already exists and was not written by AIquila; rename it or choose an output folder` | a file that is not an earlier output has the output's name |
| `No longer available` | it was deleted or unshared after the run was started |
| `Too large (<size>; the limit is <limit>)` | over `maxBytesPerFile` |
| `Could not be read: <error>` | reading it threw |
| `Not UTF-8 text` | its content is not valid UTF-8 |
| `Empty` | only whitespace |

The first 100 skips found while collecting are logged one by one, then a single
entry gives the count of the rest, so a folder full of photos does not push
everything else out of the 500-entry log. The summary lists at most 50 per-file
lines. Skips found before a batch is submitted are kept in the resume state and
reported when it ends. A model refusal is a failure (`refused`), not a skip.
Coworker runs over the docs task types count `itemsSkipped` too, for the read
reasons above. Their nightly walk still passes over other file types silently.

**Notification.** When a manual run ends as `success` or `error`,
`BatchRunNotifications` sends a Nextcloud notification (`batch_run_success` or
`batch_run_error`, object `batch_run` / run id) with the counts and, for a
failure, the error. Its link opens the run in **Cowork → Runs from Files**. A
**Show results** action opens the outputs' folder when all of them are in one
folder, and otherwise the first output. The personal task notification switches
apply: `task_success_notifications` (off by default) for a finished run,
`task_failure_notifications` (on by default) for a failed one. A cancelled run
is not announced, and neither is a `pending` one.

A run carries `status`, the item counts, `summary`, `error`, `outputs` (paths
in the user's Files) and `pollIntervalSeconds`. That interval is 30 while
queued, 10 while running and 300 while pending (the poll job's interval), and
null once the run has ended. Clients should wait that long between polls.

The request options carry the admin's instance instructions when they are
enabled for coworkers, since this is the same kind of background work. Usage
is recorded as `coworker`.

## Measuring it

```bash
occ aiquila:benchmark-batch --requests 10 --compare-sync --yes
```

Sends real requests and bills them, so it refuses to run non-interactively
without `--yes`. It reports time to batch end, the outcome mix, how many results
arrived out of order, and — with `--price-in` / `--price-out` — a batch-versus-
synchronous cost comparison with the discount applied.

## Implementation

- `nextcloud-app/lib/Service/ClaudeSDKService.php` — the transport, `toBatchParams()`, the extended-output switch, `convertBatchResult()`
- `nextcloud-app/lib/Service/ClaudeModels.php` — which models accept the extended-output beta
- `nextcloud-app/lib/Batch/BatchDocumentRunner.php` — folder walk, submission, resumption, output files
- `nextcloud-app/lib/Batch/BatchContext.php`, `BatchOperation.php` — what one run needs, and the per-file question
- `nextcloud-app/lib/Batch/SummarizeOperation.php`, `TranslateOperation.php` — the two operations
- `nextcloud-app/lib/Cowork/AbstractBatchTextTaskType.php` — the coworker adapter over the runner
- `nextcloud-app/lib/Service/BatchRunService.php`, `lib/Controller/BatchRunController.php` — manual runs and their API
- `nextcloud-app/lib/BackgroundJob/BatchRunJob.php` — starts a queued manual run
- `nextcloud-app/lib/Cowork/DocsSummarizeFolderTaskType.php`, `DocsTranslateFolderTaskType.php` — the two tasks
- `nextcloud-app/lib/Cowork/ResumableCoworkerTaskType.php` — the `resume()` contract
- `nextcloud-app/lib/Service/CoworkerService.php` — the `pending` branch and `resumePending()`
- `nextcloud-app/lib/BackgroundJob/CoworkerBatchPollJob.php` — the five-minute poll, for coworker and manual runs
- `nextcloud-app/lib/Command/BenchmarkBatchCommand.php` — `occ aiquila:benchmark-batch`
- `nextcloud-app/lib/TaskProcessing/SummaryProvider.php` — the single-request blocking path

See also [Provider settings schema](provider-settings.md) for how the admin
switch is declared, [Prompt caching](prompt-caching.md) for why batch requests
carry no `cache_control`, and [Request metadata](request-metadata.md) for the
hashed user id batches send.
