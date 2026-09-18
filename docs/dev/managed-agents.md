# Managed Agents, and why the loop stays in PHP

Anthropic's **Managed Agents** move the agentic loop off the client. An **agent** is
a persisted, versioned configuration — `model`, `system`, `tools`, `mcp_servers`,
`skills` — created once and referenced by id. A **session** is one run against that
agent: it provisions a container from an **environment** template, runs the loop on
Anthropic's orchestration layer, and streams the turn back as SSE events. Tools
execute in the container; the loop that calls them does not.

The whole surface is vendored with the Anthropic PHP SDK already —
`Services/Beta/{Agents,Sessions,Environments,Vaults,Skills,MemoryStores,Deployments}`
— so adopting it would cost no dependency work. AIquila nonetheless keeps its own
loop. This page records why, and what would change the answer.

## What AIquila does instead

`ClaudeSDKService::chatWithTools()` is a bounded `for` loop. Each iteration renders
the request with `buildRequestParams()`, sends it, and splits the response into
`text` and `tool_use` blocks. With no `tool_use` blocks the accumulated text is the
answer. Otherwise the assistant turn is echoed back verbatim, every `tool_use` block
is dispatched through the caller's `$toolExecutor` — in practice
`McpClientService::executeTool()` — and all the results return as **one** `user` turn
of `tool_result` blocks, because splitting them teaches the model to stop calling
tools in parallel. The loop is capped at ten iterations; exhausting the cap logs a
warning and returns whatever text the last iteration produced.

Token and cache counters accumulate across iterations and are persisted per message,
which is what feeds the usage dashboard and the OpenMetrics endpoint. The growing
message tail is kept cheap by a top-level breakpoint rather than by marker
bookkeeping — see [Prompt caching](prompt-caching.md).

`chatWithToolsStream()` is the same loop yielding normalised events.

## Latency and network

A hosted loop looks like it trades N client→API round trips for one session plus a
stream. That only holds when the tools live somewhere Anthropic can reach. AIquila's
tools are **not** built into the app: they arrive from the MCP servers a user has
configured, and `McpClientService::getAllTools()` is the only source. So there are
two hosted shapes, and they differ sharply:

| Tools reach the model as | Hop per tool call |
|---|---|
| `mcp_toolset` — Anthropic connects to the MCP server itself | Anthropic → MCP server. No client involvement. |
| Custom tools — AIquila executes them | Anthropic → `agent.custom_tool_use` → session goes `idle` → AIquila posts `user.custom_tool_result` → session resumes |

The second shape is *slower* than the PHP loop, not faster: it inserts Anthropic's
orchestration layer and an SSE round trip into a hop that is currently a direct HTTP
call from the Nextcloud server. The first shape is genuinely faster — and AIquila
already has it. The [native MCP connector](native-mcp-connector.md) hands MCP server
descriptors straight to the Messages API, so Anthropic runs the loop server-side and
AIquila makes one call. It is admin-gated, falls back to the PHP loop whenever a
server is not HTTPS-reachable, and carries the same constraints Managed Agents would.

## State ownership

Today every byte of conversation state is in the Nextcloud database:
`aiquila_conversations` pins the model, provider, effort and thinking settings;
`aiquila_messages` holds the turns with their per-message token, cache, latency and
citation columns; `aiquila_message_files` holds attachments. The one thing that
outlives a request on Anthropic's side is a Files API upload, and
`aiquila_file_uploads` tracks those ids so a background job can delete them on a
retention window.

Sessions would invert that. Mid-conversation state, the full event history and the
container filesystem would live with Anthropic, and AIquila would keep its own tables
anyway — for the dashboard, for search, for export — leaving two stores to reconcile
and a session lifecycle (`running`, `idle`, `rescheduling`, `terminated`, archive,
delete) to mirror.

One thing genuinely argues the other way. `tool_use` and `tool_result` blocks exist
only inside a single `chatWithTools()` call; `aiquila_messages` stores a flat string,
so conversation history is rebuilt from prose and the model never sees what the tools
returned on an earlier turn. A session would preserve that history natively. So would
persisting the blocks, which is the cheaper of the two fixes and does not move any
state off the instance.

## Privacy and residency

Managed Agents pins inference geography through `model.inference_geo`. The SDK enum
has exactly two members, `us` and `global`. **There is no EU value**, and the pin is
validated at agent save, at session create and on every turn, so it cannot be
worked around. Beyond inference, a session's event history, container filesystem and
any attached memory-store contents are stored by Anthropic for the session's life.

That is a harder position than the app holds now. Today the only identifier leaving
the instance is a per-instance HMAC of the user id (see
[Request metadata](request-metadata.md)); the native MCP connector is documented as
not Zero-Data-Retention eligible and defaults off for exactly this reason; and
`ProviderAccessService` lets an administrator route data-sensitive users and groups
to the [Hetzner](hetzner-provider.md) or [local](local-provider.md) provider per
rule. An EU deployment can run AIquila today without any inference leaving the EU.
No Managed Agents configuration reproduces that.

## Feature surface

| Managed Agents offers | AIquila's position |
|---|---|
| **Vaults** — credentials Anthropic holds and injects at egress, so executing code never sees them | Mostly covered. `McpClientService` encrypts per-server tokens and refreshes an expired `oauth2` grant before each call. What vaults add is the boundary itself. |
| **Memory stores** — persistent context across sessions | No equivalent. Projects and project paths are the nearest thing, and they are file scopes, not memory. |
| **Skills** — on-demand domain expertise, discoverable from a repo | Overlaps prompts and project system prompts. |
| **Session budgets** — a hard dollar cap per session | Duplicates `aiquila_usage_stats` and the usage widget, which report rather than enforce. |
| **Scheduled deployments** — cron-fired autonomous sessions | Duplicates Cowork, which already runs cron-scheduled jobs through Nextcloud's background job. |

Only memory is genuinely missing, and it does not need a hosted loop to exist. The
rest is built, and built provider-agnostically.

Cowork deserves its own note, because it looks like the obvious candidate and is not
one. Coworker task types never touch the tool loop: `AbstractBatchTextTaskType` calls
`$provider->ask()` and goes out over the Message Batches transport at half price (see
[Batch processing](batch-processing.md)). They are deterministic pipelines over
Nextcloud files, not open-ended agent runs. Moving them to hosted sessions would cost
the batch discount and gain nothing they use.

## Lock-in

This is the decisive one. `chatWithTools()` and `chatWithToolsStream()` are not
Anthropic implementation details — they are declared on `LLMProviderInterface` and
satisfied by every provider the app ships: `ClaudeSDKService` and `MistralProvider`
each implement the loop, and `AbstractOpenAiCompatibleProvider` implements it once on
behalf of the Hetzner, local and DeepSeek providers. The interface's contract is that
messages and tools stay in the canonical
Anthropic block shape and each provider translates internally, which is what keeps
the controllers and `McpClientService` provider-agnostic.

Anthropic-shaped extras degrade quietly on the other providers: prompt caching
reports `null` counters, `service_tier` and `speed` are simply not sent. Managed
Agents cannot degrade that way. It replaces the loop rather than decorating a
request, so adopting it for Anthropic does not remove the PHP loop — the other four
providers still need it. It adds a second execution model beside the one that exists,
with its own event vocabulary, lifecycle and failure modes, and doubles the surface
every future tool-loop change has to cross.

## The standing position

**Keep the loop in PHP.** `chatWithTools()` stays the portable default; the native
MCP connector stays the opt-in path for deployments whose MCP servers Anthropic can
reach. Managed Agents is not adopted.

Four things would reopen the question, and any one of them is enough:

- `inference_geo` gains an EU value, or Managed Agents becomes Zero-Data-Retention
  eligible.
- **Self-hosted environments** (`config.type: "self_hosted"`) become practical for a
  Nextcloud app to run. The SDK already ships them, and they are the one shape that
  keeps tool execution and filesystem contents on the operator's infrastructure while
  Anthropic runs only the loop — but they require the operator to run a long-polling
  worker process, which a PHP app inside Nextcloud has no natural place for.
- Managed Agents leaves beta *and* gains a counterpart on at least one other provider,
  so the interface argument stops biting.
- AIquila grows a long-horizon autonomous surface — one that genuinely needs sessions
  lasting beyond a request, cross-session memory and mid-run compaction — that the
  batch pipelines cannot serve.

Until then the one deficiency this evaluation actually surfaced is worth fixing
directly, and cheaply: persist `tool_use` and `tool_result` blocks so a turn's tool
history survives into the next one.
