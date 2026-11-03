# Refusals and fallback

Some Claude models, Opus 5 and Fable 5 among them, run safety classifiers that
can decline a request. A decline is **not an HTTP error**. The API returns a
normal `200` with `stop_reason: "refusal"` and a `stop_details` object. This page
covers how `ClaudeSDKService` handles that, and how it opts into Anthropic's
server-side fallback. The reference is
[Refusals and fallback](https://platform.claude.com/docs/en/build-with-claude/refusals-and-fallback).

## Recognising a refusal

The check runs on `stop_reason` alone. `stop_details.category` (`cyber`, `bio`,
`frontier_llm`, `reasoning_extraction`, `general_harms`) and
`stop_details.explanation` can both be `null` on a refusal, and that is a
normal value.

A refusal can arrive before any output or after part of it. Partial output is
incomplete, so AIquila discards it:

- **Non-streaming calls** go through `createMessage()`. That method raises a
  `RefusalException`, and `handleException()` turns it into an error result:

  ```php
  ['error' => '<explanation>', 'error_type' => 'refused', 'refusal_category' => 'cyber', 'usage' => [...]]
  ```

  This is the same shape the [Batch API path](batch-processing.md#per-request-outcomes)
  returns. The usage travels with it because some refusals are billed, and
  `UsageStatService` records it.
- **`chatWithToolsStream()`** ends the turn with an `error` event that carries
  `error_type: refused` and `refusal_category`. `ConversationController` drops the
  text and thinking that streamed before it, and persists only the refusal (see
  [Streaming responses](streaming.md)).
- **`chatWithNativeMcp()`**, the native MCP connector path, does the same, and
  `chatWithNativeMcpCollect()` returns the refused error result without the
  partial text.
- **`askStream()`** throws the `RefusalException` after the text it already yielded.

Each refusal is logged at `info` as `AIquila SDK: request refused`, with its
category. A response that a fallback model served is logged as `served by a
fallback model`. Error-rate monitoring cannot see either one, because both are
HTTP 200s.

## Server-side fallback

When fallback is on, every Messages request carries `fallbacks: "default"` and
the beta header `server-side-fallback-2026-07-01`. It must be exactly that date:
`2026-06-01` only accepts the explicit-list form. When the requested model
declines, the API runs the same request on the model Anthropic recommends for
that refusal category, all inside the one call. A model without fallback support
just runs the request on its own, so the field is safe to send everywhere.

Both travel through `requestOptionsForMessages()` as an extra body param and a
beta flag, the same way fast mode does. That keeps the request on the GA
endpoint. The flags share one comma-separated `anthropic-beta` header. The
native MCP connector is already on the beta endpoint, so
`callBetaCreateStreamWithMcp()` passes `fallbacks` as a named argument instead.

It is sent only when both of these hold:

- the platform serves it (`FEATURE_SERVER_FALLBACK`): the Claude API and
  Claude Models on Google Cloud (Anthropic managed). See
  [Claude on cloud platforms](cloud-providers.md).
- the admin has not switched it off: app config `server_fallback`, default on.
  Set it under **Defaults** → *Retry refused requests on a fallback model*, or
  with `occ aiquila:configure --server-fallback=on|off`.

`toBatchParams()` never forwards it: the Batch API turns a request carrying
`fallbacks` into an errored item.

The served model is identified by a `fallback_message` entry in
`usage.iterations`. With sticky routing, a later turn can go straight to the
fallback model and carry no `fallback` content block at all.

### The handoff block in a tool loop

A `fallback` content block marks where one model's output gives way to the
next. When the turn is echoed back in the tool loop, `FallbackHandoff` applies
the documented rules:

| Block | Echoed? |
|---|---|
| `fallback` | Yes, in its original position. The API checks the thinking blocks around it by position |
| `text` | Yes, on either side |
| `thinking`, `redacted_thinking`, client `tool_use` before the final `fallback` | No. A tool call from the model that declined is also **not run** |
| anything after the final `fallback` | Yes |

A non-streaming response leaves out the declined model's partial output, so its
`fallback` block comes first. Only streamed responses carry blocks before the
handoff.

The GA SDK types have no `fallback` variant. They coerce the block into another
class (in practice `ToolUseBlock`) with `type` still set to `fallback`, so block
handling in `ClaudeSDKService` branches on `->type`, never on `instanceof`.
`FallbackHandoff` reads the raw properties back through `toProperties()`.
