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
| Cowork `docs:summarize` / `docs:translate` | `AbstractBatchTextTaskType` | N requests, submit now, collect later |

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

## The bulk cowork tasks

`docs:summarize` and `docs:translate` walk the coworker's input folder and write
one output file per document — `<name>.summary.md`, `<name>.<language>.md` —
into the configured output folder, or beside the source when none is set. The
source is tagged `aiquila:summarized` / `aiquila:translated` so processed files
can be filtered in the Files UI.

Files, rather than tags or comments, because a summary or a translation is prose
that a system tag cannot hold and a Nextcloud comment truncates.

Writing output into the tree being read makes three things load-bearing, all
decided during the walk rather than after it:

- A source whose output is at least as new as itself is skipped, so a nightly
  coworker does not re-bill the whole folder every night. `force` overrides it.
- A file whose name already ends in the task's own output suffix is never a
  source. Otherwise each run would summarise the last run's summaries and grow
  another `.summary.summary.md` generation every night.
- The configured output folder is skipped during the walk, since it is normally
  nested inside the input path.

These checks run *inside* the walk because the walk stops at the item cap.
Applied afterwards, the first 200 already-finished files would fill the quota
every night and everything past them would never be reached.

A run is capped at `MAX_ITEMS_PER_RUN` (200) files, and a file larger than
`maxBytesPerFile` (1 MiB by default) is skipped rather than truncated — half a
contract summarised as if it were the whole thing is worse than no summary.

**Text only.** The default mime types are the `text/` family and
`application/json`; PDFs are deliberately excluded, because their bytes are not
text and nothing on this path builds the base64 `document` block the Messages
API wants for them. Content that is not valid UTF-8 is skipped for the same
reason, and because a batch goes out as one request for the whole folder —
outside any per-file guard — so one binary file would take the entire run down
rather than just itself.

On a non-Anthropic provider the same tasks fall back to a synchronous per-file
loop at full price, and the run summary says which path it took.

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
- `nextcloud-app/lib/Cowork/AbstractBatchTextTaskType.php` — folder walk, submission, resumption, output files
- `nextcloud-app/lib/Cowork/DocsSummarizeFolderTaskType.php`, `DocsTranslateFolderTaskType.php` — the two tasks
- `nextcloud-app/lib/Cowork/ResumableCoworkerTaskType.php` — the `resume()` contract
- `nextcloud-app/lib/Service/CoworkerService.php` — the `pending` branch and `resumePending()`
- `nextcloud-app/lib/BackgroundJob/CoworkerBatchPollJob.php` — the five-minute poll
- `nextcloud-app/lib/Command/BenchmarkBatchCommand.php` — `occ aiquila:benchmark-batch`
- `nextcloud-app/lib/TaskProcessing/SummaryProvider.php` — the single-request blocking path

See also [Provider settings schema](provider-settings.md) for how the admin
switch is declared, [Prompt caching](prompt-caching.md) for why batch requests
carry no `cache_control`, and [Request metadata](request-metadata.md) for the
hashed user id batches send.
