# Streaming chat responses (SSE)

AIquila's chat reply arrives as it is written rather than in one piece at the
end. This page describes how that works, what breaks it, and how to tell.

## The path a token takes

```
provider API  ──SSE──▶  LLMProvider::chatWithToolsStream()   (PHP generator)
                          │
                          ▼
              ConversationController::streamConversationReply()
                          │  accumulates text, usage, citations
                          ▼
              SSEResponse::render()  ──SSE──▶  web server ──▶ reverse proxy
                          │                                        │
                          ▼                                        ▼
              persists the assistant message          api.js sendMessageStream()
                                                                   │
                                                                   ▼
                                                      ChatView.vue draft bubble
```

`POST /api/conversations/{id}/messages/stream` is the endpoint. Events are bare
`data: <json>` blocks; the kind is the `type` field inside the JSON:

| Event | Meaning |
|---|---|
| `user_message` | the persisted user message, sent first |
| `text_delta` | a chunk of assistant text |
| `tool_use` / `tool_result` | a tool invocation and its output |
| `done` | terminal: usage totals and citations |
| `error` | terminal: the turn failed, partially or entirely |
| `persisted` | always last: the stored assistant message and conversation |

`error` is always followed by `persisted`, so a failed turn still leaves the
user whatever text arrived before it went wrong, suffixed with
`_(stream interrupted: …)_`. The frontend falls back to the non-streaming
`POST /api/conversations/{id}/messages` if the stream cannot be opened at all.

## What buffering looks like

There is one failure mode, and it never reports itself: something holds the
response until it is complete. The reply is correct and nothing is logged — it
just all appears at once after a long silence. Every requirement below exists to
prevent that, on one of the two legs.

### The upstream leg — provider to PHP

Nextcloud's `IClientService` is the right client to use (CA bundle, proxy,
local-address guard), but two of its defaults defeat streaming, so
`OCA\AIquila\Http\SseStreaming` overrides them per request:

- **`handler`** — Guzzle's cURL handlers cannot stream a response, which is why
  Guzzle's own default stack routes `stream => true` to its `StreamHandler`.
  Nextcloud pins a bare `CurlHandler`, so without the override the entire reply
  is downloaded before the first `fread()` returns.
- **`version`** — Nextcloud pins HTTP/2, which the `StreamHandler` refuses
  outright (`HTTP/2.0 is not supported by the stream handler`).

On top of that, PHP's HTTP stream wrapper only hands back whole chunks, so the
read granularity is the real determinant of latency: at the 8 KB default a read
waits for roughly two hundred events, which on a typical model is ten seconds of
output. `SseStreaming::tuneSseStream()` drops it to 64 bytes.

Anything reading an SSE response through `IClientService` needs all three.

### The downstream leg — PHP to the browser

- **Content type.** The response must reach the client as `text/event-stream`.
  This is not automatic: Nextcloud's dispatcher calls `render()` and only
  afterwards emits the response headers, but `render()` writes straight to the
  output stream, so PHP has already sent its own defaults by then and the
  declared headers are dropped. `SSEResponse::render()` therefore emits them
  itself before the first event. A stream that goes out as `text/html` is
  compressed by a stock Apache, and compression buffers.
- **No compression.** `Content-Encoding` on an SSE response means buffering.
  `SSEResponse` sets `no-gzip` in the request environment and turns off
  `zlib.output_compression`; `docker/installation/Dockerfile` also tells
  `mod_deflate` to skip requests that ask for `text/event-stream`.
- **No proxy buffering.** `X-Accel-Buffering: no` covers nginx.
  `docker/caddy/Caddyfile` pins `flush_interval -1` on the Nextcloud upstream.
  Traefik does not buffer responses unless a `buffering` middleware is declared,
  and none is in the Hetzner stacks (the `bufferingSize` in `traefik.yml` is
  access-log buffering, unrelated).
- **PHP output buffering.** `output_buffering` off and `implicit_flush` on are
  the defaults in the Nextcloud images; `render()` drains any remaining buffer
  regardless.

## Checking an instance

The **AIquila streaming responses** setup check (Administration settings →
Overview) answers this directly. It requests `GET /api/stream-probe`, a
diagnostic endpoint that emits four ticks 150 ms apart, and reports a warning if
they all arrive together or if the response came back compressed.

By hand, watch the timestamps rather than the result:

```bash
curl -N -H 'Accept: text/event-stream' \
     https://cloud.example.com/index.php/apps/aiquila/api/stream-probe \
  | ts '%.s'          # or: while read -r l; do printf '%s %s\n' "$(date +%s.%N)" "$l"; done
```

Four ticks spread over ~450 ms is healthy; four ticks with the same timestamp
means something in front of PHP is buffering. Check the response headers too —
`Content-Type: text/event-stream` and no `Content-Encoding`:

```bash
curl -sI -H 'Accept: text/event-stream' \
     https://cloud.example.com/index.php/apps/aiquila/api/stream-probe
```

## Testing the chat stream itself

`docker/installation` with the `local` provider pointed at any
OpenAI-compatible server (Ollama, LM Studio, llama.cpp) exercises the whole path
without an API key — see [local-provider.md](local-provider.md):

```bash
occ config:app:set aiquila provider       --value='local'
occ config:app:set aiquila local_base_url --value='http://<host>:1234'
occ config:app:set aiquila model_local    --value='<model id>'
```

A server that drops the connection mid-generation is the case worth rehearsing:
the providers treat EOF without `[DONE]` or a `finish_reason` as a truncated
turn and emit `error`, so the partial text is persisted as interrupted rather
than stored as a finished reply.
