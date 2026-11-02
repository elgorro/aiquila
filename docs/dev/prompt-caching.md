# Prompt caching

Anthropic's prompt cache is a **prefix match**: the cache key is the exact bytes
of the rendered prompt up to a `cache_control` breakpoint. A breakpoint marks a
position; a later request that renders the same bytes up to that position reads
them back at roughly a tenth of the input price instead of paying for them
again. Any byte that changes ahead of a breakpoint invalidates it.

Render order is `tools` → `system` → `messages`, and a request may carry at most
**four** breakpoints. A fifth is a hard `400`.

This applies to the **Anthropic provider only** (`ClaudeSDKService`). The
OpenAI-compatible providers (Mistral, Hetzner, local) have no equivalent field
and report `cache_creation_tokens` / `cache_read_tokens` as `null`.

## Where AIquila places its breakpoints

Two mechanisms, used together.

**Explicit markers** are placed by `buildRequestParams()` on the parts of a
request that are large, stable and reused verbatim across requests:

| Marked | Opt out with |
|---|---|
| The system block | `cache_system: false` |
| The last tool definition — one marker caches the whole tool list | `cache_tools: false` |
| The `source` of a document block in `askWithDocument()` | `$cacheDoc = false` |
| The last image block in `askWithImage()` / `askWithImages()` | — |

Because tools render before system, the marker on the system block covers tools
*and* system in one read point.

**Top-level automatic caching** (`cacheControl` on the request, not on any block)
asks the API to place a breakpoint of its own on the last cacheable block and to
move it forward as the conversation grows. That is the part no explicit marker
can do well: in an agentic tool loop each iteration appends an assistant turn and
a `tool_result` turn and re-sends the whole conversation, so without a breakpoint
inside `messages` every iteration re-processes the entire history at full price.

The combination — an explicit marker pinning the static prefix, plus automatic
caching following the growing tail — is deliberate. The explicit marker gives the
expensive shared prefix a guaranteed read point that survives whatever happens
later in `messages`; the automatic one keeps up with the conversation without any
marker bookkeeping.

## Which calls opt in

Automatic caching only pays off when the tail of one request is the prefix of a
later one. On a single-shot call the breakpoint lands after a one-off question,
so the request pays the ~1.25× cache-write premium on bytes nothing ever reads
back — a pure surcharge. So only the multi-turn entry points opt in:

| Opts in | Does not |
|---|---|
| `chat()`, once the conversation has history | `ask()`, `askStream()` |
| `chatWithTools()`, `chatWithToolsStream()` | `askWithDocument()`, `askWithImage()`, `askWithImages()` |
| `chatWithNativeMcp()`, `chatWithNativeMcpCollect()` | `submitBatch()` |

Batch requests are the clearest case of the surcharge: `toBatchParams()` drops
`cache_control` outright, because the requests in a batch are independent and
nothing reads a breakpoint back. See [Batch processing](batch-processing.md).

`chat()` is the one that needs a second test. It serves real conversations, but
also one-shot requests that merely need structured content blocks — the mixed
image + PDF branch of `/api/ask` reaches it with a single user message carrying
large base64 payloads. A lone user message has nothing a later request can read
back, so history, not the method name, decides: `chat()` opts in from the second
message onwards.

Files attached in a conversation are part of that history. Every turn re-sends
each file on the user message it was attached to, re-read from Nextcloud, so
follow-up questions still see it. Images and PDFs go by their Files API
`file_id`, which stays the same while the file's bytes do, and text files go
inline. An unchanged file therefore renders the same bytes each turn and sits
inside the cached prefix. Editing it in Nextcloud invalidates the cache from
that message onwards, and so does the re-upload a day before an upload's 30-day
expiry (`FilesService::TTL_SECONDS`), which gives the file a new `file_id`.

### Context the model learns mid-conversation

Anything that changes during a conversation has to be appended, never edited in
place. Editing the top-level `system` field changes the prefix ahead of the whole
history, so every cached turn is processed again. AIquila tells the model today's
date and the user's time zone this way. `RuntimeContextService` stores the note as a
`system` row right after the user's message, writes it only when it changed (the first
turn, a new day, a new time zone), and the history replays it on every later
turn. Each request is then a pure extension of the previous one.

Paths without a stored conversation send the same note fresh on each request. The
Talk bot appends it as a trailing `system` message after the buffered history, so the
system prompt stays stable. The Flow action appends it to the prompt. Without a user
(Talk guests, federated actors), the note uses the server's default time zone.

The row reaches the model as a
[mid-conversation system message](https://platform.claude.com/docs/en/build-with-claude/mid-conversation-system-messages)
(`role: "system"` inside `messages`) where that is accepted, and as a
`<system-reminder>` block appended to the preceding user turn everywhere else.
`Provider\SystemMessages` makes that call per request:

- **Claude, natively**: on models in `ClaudeModels::supportsMidConversationSystem()`
  (Fable 5 and 5.1, Opus 5 and 5.5, Opus 4.8; not Sonnet 5) and on platforms with
  `FEATURE_MID_CONVERSATION_SYSTEM` (the Claude API, Claude Platform on AWS,
  Bedrock, Vertex AI). A system message must directly follow a user turn and be
  last or followed by an assistant turn; one in any other position is folded even
  there. If the API still rejects the role, the request is retried once, folded,
  and a warning is logged.
- **Folded**: other Claude models and platforms, and every non-Claude provider. A
  folded note is rendered the same way on every turn, so it caches too.

`system` rows never show up in the chat, search or Context Chat;
`MessageMapper::findByConversation()` returns them only when asked to.

The automatic breakpoint consumes one of the four slots, so it stands down when
the explicit markers have already taken all four rather than letting the request
fail.

All breakpoints use the default five-minute TTL. Requests inside a conversation
or a tool loop start well under five minutes apart, and every read refreshes the
entry's timer at no cost, so the five-minute cache stays warm on its own — the
one-hour TTL would only double the write price for nothing.

## Tool calls in the history

An answer that used tools keeps the turns its tool loop added: the assistant's
`tool_use` blocks and the `tool_result` blocks sent back. They are stored with the
answer (`aiquila_messages.tool_trace`, built by `ToolTrace`) and go back into the
history ahead of it on every later turn. The model can then use what a tool
returned before without calling it again.

Every later request resends the trace, and the cache prefix has to stay
byte-identical. Both points shape what is stored:

- **Results are cut once, when stored**, at 10,000 characters, with a note saying
  how much was left out. Nothing is cut or dropped by age or depth at replay time.
  A rule like "full results for the last few turns only" would rewrite an older
  turn as the conversation grew, and every request would miss the cache from
  that turn on.
- **Only `text`, `tool_use` and `tool_result` are kept.** Thinking from a finished
  answer may be left out of later requests, and its signatures would not survive a
  change of model or provider.
- **Tool ids become nine alphanumerics.** Mistral accepts nothing else, and the
  other providers accept that form too, so the history replays on whichever
  provider answers next.

A request that defines no tools (no MCP servers configured, or the native MCP
connector, whose tools are Anthropic's) cannot carry `tool_use` blocks. The trace
then goes as labelled text: `[Called tool list_files with {"path":"/"}]` and
`[Tool list_files returned: …]`. The native connector's own tool calls are run by
Anthropic and are not stored.

## Tools that change between turns

`tools` renders first, so a conversation whose tool list changes (an MCP server
added, removed or offering a new tool) would rebuild its whole cache on that turn.
Where the model and platform take mid-conversation tool changes (beta
`mid-conversation-tool-changes-2026-07-01`; Opus 4.8 and later, the Fable models,
not Sonnet 5; the Claude API, Bedrock and Vertex AI), `ToolChanges` keeps `tools`
as the conversation first sent it:

- **A new tool** is appended with `defer_loading: true`, which keeps it out of the
  prefix, and offered by a `tool_addition` block.
- **A removed tool** stays declared, because a reference has to resolve. A
  `tool_removal` block withdraws it, and it can be offered again later.
- The blocks go in a `role: "system"` message after the user's turn. That message
  is stored as a system row (`aiquila_messages.tool_trace`), so later turns replay
  it in place. The declared list and the names on offer are kept in
  `aiquila_conversations.tool_state`.
- The cache breakpoint on `tools` sits on the last non-deferred tool. A deferred
  definition cannot carry one.

This header changes tools by reference only. A tool whose definition changed starts
the state over. That one request misses the cache, and the stored change rows
become plain text, so they never point at a declared list that no longer exists.
The same happens when the conversation moves to a model or provider without the
feature. There the tools go out as they are, and earlier changes are replayed as
text.

## Turning it off

Admin settings → **AIquila** → **Defaults** → *Automatic prompt caching*. On by
default; the explicit markers are unaffected by the toggle. Headless equivalent:

```bash
occ aiquila:configure --auto-cache=off
occ aiquila:configure --show          # reports the current value
```

## Reading the result

Every response carries the numbers, and AIquila persists them per message:

- `cache_creation_tokens` — written to cache this request (billed at ~1.25×)
- `cache_read_tokens` — served from cache this request (billed at ~0.1×)
- `input_tokens` — the uncached remainder only

Total prompt size is the sum of all three, not `input_tokens` alone. They are
shown per message in the chat UI, aggregated by the usage dashboard widget, and
exported to Prometheus by the OpenMetrics endpoint (see
[Monitoring](../monitoring.md)).

The healthy signature in a multi-turn loop is `cache_read_tokens` covering the
whole prior prefix and growing turn over turn, with `cache_creation_tokens`
small relative to the conversation. If `cache_read_tokens` stays at zero across
requests that should share a prefix, something ahead of the breakpoint is
changing between requests — a timestamp or per-request id interpolated into the
system prompt, a tool list whose order is not deterministic, or a switch of
model, which scopes the cache and invalidates it wholesale.

A [task budget](../installation/aiquila-setup.md#task-budget-for-tool-use) is also
part of the rendered prompt. AIquila sends the same `total` on every request of a
tool loop and never sends `remaining`, so the budget does not move the prefix.
Changing the setting starts new cache entries once.

### Minimum cacheable length

The API caches a prefix only when it reaches the model's minimum. Below that it
skips caching without an error, so the system block is marked on every request.
The minimum differs between models, and it does not shrink steadily from one
generation to the next:

| Model | Minimum cacheable prefix |
|---|---|
| Opus 5, Fable 5 | 512 tokens |
| Opus 4.8, Sonnet 5, Sonnet 4.6, Sonnet 4.5 | 1024 tokens |
| Opus 4.7 | 2048 tokens |
| Opus 4.6, Opus 4.5, Haiku 4.5 | 4096 tokens |

So a short system prompt that never cached on one model may cache on another.
Check what the configured model does with `occ aiquila:test --cache-check`. It
sends the chat system prompt twice and compares the usage:

```bash
occ aiquila:test --cache-check --user alice
```

| Result | Meaning | Exit code |
|---|---|---|
| Cache hit | The second request read the prefix back | 0 |
| Not cached | Nothing written or read: the prefix is under the model minimum | 0 |
| Cache miss | Written but never read: something in the prefix changes between identical requests | 1 |

Across real traffic, `nextcloud_aiquila_prompt_cache_tokens_total` gives the
same signal per model and request type; see [Monitoring](../monitoring.md).

The chat system prompt is assembled by `InstructionsService` in a fixed order:
instance instructions, the user's default, the project prompt, the project
paths. The order runs from most shared to most specific, so the start of the
text is the same for every user of an instance. It is still one system block
with a single breakpoint at its end, so the cache hits only when the whole block
matches. The fixed order matters for a future split into separate blocks.
`{user}` in the instance instructions makes that layer per user. Nothing else
in the system prompt varies per request; the date and time zone travel as a
`system` row in `messages` instead (above).
