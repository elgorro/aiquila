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
| `chat()` | `ask()`, `askStream()` |
| `chatWithTools()`, `chatWithToolsStream()` | `askWithDocument()`, `askWithImage()`, `askWithImages()` |
| `chatWithNativeMcp()`, `chatWithNativeMcpCollect()` | `submitBatch()` |

The automatic breakpoint consumes one of the four slots, so it stands down when
the explicit markers have already taken all four rather than letting the request
fail.

All breakpoints use the default five-minute TTL. Requests inside a conversation
or a tool loop start well under five minutes apart, and every read refreshes the
entry's timer at no cost, so the five-minute cache stays warm on its own — the
one-hour TTL would only double the write price for nothing.

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
