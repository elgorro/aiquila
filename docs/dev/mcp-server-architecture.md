# MCP Server Architecture (Developer Reference)

Technical architecture overview of the AIquila MCP Server for developers working on the codebase.

## Overview

The MCP server is a TypeScript/Node.js implementation of the [Model Context Protocol](https://modelcontextprotocol.io/) that exposes Nextcloud as a set of AI tools. It supports two deployment modes:

- **stdio** — local MCP clients (Claude Desktop, Cursor in stdio mode, etc.)
- **HTTP** — remote/network MCP clients (Claude.ai, Cursor, VS Code, etc.) via Docker or self-hosted

## Technology Stack

- **Runtime**: Node.js 26+
- **Language**: TypeScript 5.8+ (strict mode)
- **Protocol**: Model Context Protocol via the [TypeScript SDK](https://github.com/modelcontextprotocol/typescript-sdk) v2:
  - `@modelcontextprotocol/server` — `McpServer`, stdio transport, OAuth error and wire types
  - `@modelcontextprotocol/node` — `NodeStreamableHTTPServerTransport`
  - `@modelcontextprotocol/express` — `createMcpExpressApp`, `requireBearerAuth`, `mcpAuthMetadataRouter` (serves the discovery documents)
- **Transport**: `StdioServerTransport` or `NodeStreamableHTTPServerTransport`
- **HTTP framework**: Express 5 (via the SDK's `createMcpExpressApp`)
- **Auth**: OAuth 2.1 + PKCE, HS256 JWT via [`jose`](https://github.com/panva/jose)
- **Dependencies**:
  - `express` ^5.x — HTTP framework
  - `jose` ^6.x — JWT signing and verification
  - `express-rate-limit` ^8.x — per-IP limits on the OAuth endpoints
  - `pino` ^10.x — structured JSON logging to stderr
  - `webdav` ^5.x — WebDAV client
  - `zod` ^4.x — schema validation
- **Dev Tools**: `vitest`, `tsx`, `eslint`, `prettier`

## Project Structure

```
mcp-server/src/
├── index.ts             # Transport selector ($MCP_TRANSPORT)
├── server.ts            # McpServer factory + tool registration
├── logger.ts            # pino → stderr
├── transports/
│   ├── stdio.ts         # StdioServerTransport (local MCP clients)
│   └── http.ts          # Express app + NodeStreamableHTTPServerTransport + auth middleware
├── auth/
│   ├── provider.ts      # NextcloudOAuthProvider — JWT sign/verify, code issuance
│   ├── store.ts         # ClientsStore, CodeStore, RefreshStore (disk-persisted)
│   └── login.ts         # POST /auth/login — Nextcloud credential validation
├── client/
│   ├── webdav.ts        # WebDAV singleton
│   ├── caldav.ts        # CalDAV helper (authenticated fetch)
│   ├── ocs.ts           # OCS API (Nextcloud REST)
│   ├── aiquila.ts       # AIquila app client
│   ├── bookmarks.ts     # Bookmarks client
│   ├── mail.ts          # Mail client
│   └── maps.ts          # Maps client
├── tools/
│   ├── types.ts         # ToolResponse, NextcloudConfig, shared Zod schemas
│   ├── system/          # files, status, apps, occ, security, tags
│   └── apps/            # tasks, calendar, notes, mail, contacts, bookmarks,
│                        #   cookbook, maps, shares, groups, users, aiquila
└── __tests__/           # vitest unit + integration tests
```

## Transport Layer

Transport is selected at startup via the `MCP_TRANSPORT` environment variable (`stdio` by default).

### Protocol revisions

Both transports are dual-era. They serve MCP [`2026-07-28`](https://modelcontextprotocol.io/specification/2026-07-28), which has no handshake, and the handshake-based revisions up to `2025-11-25`, on the same endpoint or process. The SDK decides the era from how the client opens:

| Client sends | Era | Served as |
|---|---|---|
| A request with `_meta["io.modelcontextprotocol/protocolVersion"]` (and, on HTTP, the matching `MCP-Protocol-Version` / `Mcp-Method` / `Mcp-Name` headers) | modern | `2026-07-28`, statelessly, one request at a time. `server/discover` returns the supported versions, capabilities and server identity |
| `initialize`, or any request without that `_meta` field | legacy | The revision negotiated by `initialize` (`2025-11-25` or earlier) |

A modern request naming a version the server does not serve gets `400` with `UnsupportedProtocolVersionError` (`-32022`), listing the supported versions. A header that disagrees with the body gets `400` with `HeaderMismatch` (`-32020`). See [Versioning](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning) and [Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http) in the specification.

Every server instance comes from the same factory, `createServer()` in `src/server.ts`, so both eras see the same tools.

### stdio (`serveStdio`) — local MCP clients

`serveStdio(() => createServer())` reads the client's opening message and pins the connection to one `McpServer` for the life of the process. A `server/discover` probe or any request with the per-request `_meta` envelope selects `2026-07-28`; an `initialize` handshake selects the negotiated legacy revision, and the connection stays on it. A dual-era client may send `server/discover` first and fall back to `initialize`, in which case the factory runs twice and the probe instance is discarded. JSON-RPC messages flow over stdin/stdout. The tool set is resolved once at startup and cached.

```
MCP Client (e.g. Claude Desktop)
     │
     │  JSON-RPC over stdin/stdout
     ▼
serveStdio (picks the era from the opening message)
     │
     ▼
McpServer (one pinned instance, lifetime = process)
     │
     ▼
Tool handlers → Nextcloud APIs
```

### HTTP (`createMcpHandler`) — remote/network MCP clients

**Per-request stateless** in both eras: every HTTP request gets a fresh `McpServer` from the factory, which is torn down when the response closes.

```typescript
const mcpHandler = toNodeHandler(
  createMcpHandler(() => createServer(), { legacy: 'stateless' })
);
const handleMcpRequest = (req, res) => mcpHandler(req, res, req.body);
```

Key points:
- `createMcpHandler` (from `@modelcontextprotocol/server`) serves `2026-07-28` requests itself and passes legacy ones to its `legacy: 'stateless'` leg: a session-less streamable transport, so no `Mcp-Session-Id` is minted. `'reject'` would cut off every client that still opens with `initialize`
- `toNodeHandler` (from `@modelcontextprotocol/node`) adapts it to Express and forwards `req.auth` (set by `requireBearerAuth`) and the already-parsed `req.body`
- `GET` and `DELETE` on `/mcp` are answered `405` (once auth has passed): there is no standalone SSE stream and no session to end. Long-lived change notifications use `subscriptions/listen` under `2026-07-28`
- No client affinity is required, so distributed clients (e.g. Claude.ai, Cursor) can connect from multiple IPs
- The deprecated HTTP+SSE transport (`2024-11-05`) is not served

```
MCP Client (e.g. Claude.ai, Cursor, VS Code)
     │
     │  HTTPS (via Traefik/Caddy)
     ▼
Express app  ──── Bearer auth check (when MCP_AUTH_ENABLED=true)
     │
     ▼  per request:
┌─────────────────────────────┐
│  new McpServer              │
│  new StreamableHTTP         │  ← torn down on response close
│       Transport             │
└─────────────────────────────┘
     │
     ▼
Tool handlers → Nextcloud APIs
```

## Auth Layer

When `MCP_AUTH_ENABLED=true`, the server runs a built-in OAuth 2.1 + PKCE authorization server. Auth is transparent to tool handlers — they always see an already-authenticated context.

### OAuth Flow

```
1. MCP Client  →  GET /.well-known/oauth-authorization-server
                  (discovery: endpoints, capabilities)

2. MCP Client  →  POST /register  (if MCP_REGISTRATION_ENABLED=true)
                  (dynamic client registration — skipped by a client that
                  uses a Client ID Metadata Document URL as its client_id)

3. MCP Client  →  GET /authorize?response_type=code&code_challenge=...
                  (PKCE auth code request; a URL client_id is fetched and
                  validated here, see src/auth/cimd.ts)

4. Server      →  200 HTML login form (POST /auth/login)

5. User        →  POST /auth/login  {username, password, code_challenge, ...}

6. Server      →  validates credentials against OCS API
                  /ocs/v2.php/cloud/user

7. Server      →  302 redirect to client redirect_uri?code=<uuid>

8. MCP Client  →  POST /token  {code, code_verifier}
                  (PKCE token exchange)

9. Server      →  {access_token: <HS256 JWT, 1h>, refresh_token: <UUID, 24h>}

10. MCP Client →  POST /mcp  Authorization: Bearer <access_token>
                  (every MCP request verified via requireBearerAuth middleware)
```

### Token Details

| Token | Format | TTL | Storage |
|-------|--------|-----|---------|
| Authorization code | UUID | 5 min | In-memory (`CodeStore`) |
| Access token | HS256 JWT (jose) | 1 hour | Stateless (verified by signature) |
| Refresh token | UUID | 24 hours | Disk-persisted (`RefreshStore`) |

### Auth Storage

Token state is persisted to `MCP_AUTH_STATE_DIR` (default `/app/state`):

- `clients.json` — dynamically registered OAuth clients (`ClientsStore`)
- `refresh-tokens.json` — active refresh tokens (`RefreshStore`)

Authorization codes are in-memory only (5-min TTL, lost on restart by design).

### Authorization server

`src/auth/router.ts` implements the endpoints itself rather than through an SDK router:

| Endpoint | Spec | Notes |
|---|---|---|
| `/.well-known/oauth-authorization-server` | RFC 8414 | Built by `buildAuthorizationServerMetadata`, served via `mcpAuthMetadataRouter` |
| `/.well-known/oauth-protected-resource/mcp` | RFC 9728 | Also served at the root path by `http.ts` |
| `/authorize` | RFC 6749 §4.1.1, RFC 7636 | 100 req / 15 min per IP. Errors before `client_id` and `redirect_uri` are verified are a `400` JSON body, never a redirect (§4.1.2.1); afterwards they redirect with `error`, `state` and `iss` (RFC 9207 §2) |
| `/auth/login` | — | Nextcloud credential check, issues the code. 10 req / 15 min per IP |
| `/token` | RFC 6749 §4.1.3, §6 | 50 req / 15 min per IP. `no-store` + `Pragma: no-cache` (§5.1). Refresh may narrow, never widen, the granted scope |
| `/register` | RFC 7591 | 20 req / hour per IP. Mounted only with `MCP_REGISTRATION_ENABLED=true` |
| `/revoke` | RFC 7009 | 50 req / 15 min per IP. Revokes refresh tokens issued to the calling client; always `200` |

Every error, on these endpoints and in the bearer check on `/mcp`, is the SDK's v2
`OAuthError`. Redirect URIs match exactly, except loopback URIs, which match on any port
(RFC 8252 §7.3) — `src/auth/redirect-uri.ts` applies the same rule at `/authorize` and at
`/auth/login`.

### Client Configuration

Three options for OAuth clients, usable together:

**Client ID Metadata Documents** (on by default; `MCP_CIMD_ENABLED`): the client's
`client_id` is an HTTPS URL serving its metadata. `ClientsStore.getClient` checks the
pre-seeded and registered clients first, then resolves an `https://` id through
`ClientIdDocumentResolver` (`src/auth/cimd.ts`), which guards the fetch against SSRF and
caches valid documents. The checks are listed in
[OAuth setup](../mcp/oauth.md#client-id-metadata-documents).

**Dynamic registration** (any RFC 7591 client):
```
MCP_REGISTRATION_ENABLED=true
MCP_REGISTRATION_TOKEN=<secret>  # optional: gates POST /register with Bearer token
```

A gated endpoint rejects registration without `Authorization: Bearer <MCP_REGISTRATION_TOKEN>`,
so every client has to be given the token — for the Nextcloud app that is the per-server
**Registration token** field, see
[OAuth setup](../mcp/oauth.md#connecting-the-nextcloud-app-to-a-gated-server).

**Static pre-seeded client** (for clients that do not support dynamic registration):
```
MCP_CLIENT_ID=<stable-identifier>
MCP_CLIENT_REDIRECT_URIS=<client-callback-url>
# Example: https://claude.ai/api/mcp/auth_callback for Claude.ai / Claude Code
```

## Environment Variables

### Core

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `NEXTCLOUD_URL` | Yes | — | Trailing slash stripped automatically |
| `NEXTCLOUD_USER` | Yes | — | |
| `NEXTCLOUD_PASSWORD` | Yes | — | Use an app password |
| `MCP_TRANSPORT` | No | `stdio` | `stdio` or `http` |
| `MCP_MAX_FILE_SIZE` | No | `1073741824` | Max `write_file` content length in bytes (1 GB) |
| `LOG_LEVEL` | No | `info` | `trace` / `debug` / `info` / `warn` / `error` / `fatal` |
| `MCP_OCC_ALLOWLIST` | No | *(built-in)* | Comma-separated list of allowed OCC commands for `run_occ` |

### HTTP Transport

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `MCP_PORT` | No | `3339` | Listening port |
| `MCP_HOST` | No | `0.0.0.0` | Bind address |
| `MCP_TRUST_PROXY` | No | `false` | Set to `1` when behind a single reverse proxy |
| `MCP_TLS_STRICT` | No | `false` | Set `true` to fail fast on TLS cert errors |
| `MCP_ALLOWED_HOSTS` | No | — | Comma-separated extra hostnames accepted by DNS rebinding protection (e.g. `mcp` for a Docker service name). The `MCP_AUTH_ISSUER` hostname plus `localhost` / `127.0.0.1` are always allowed |
| `MCP_CORS_ORIGINS` | No | — | Comma-separated extra origins allowed to make cross-origin browser requests. The `MCP_AUTH_ISSUER` origin is allowed by default when auth is enabled. Origins are matched exactly and echoed back — `*` is never sent, because these endpoints are credentialed |

### Auth (`MCP_AUTH_ENABLED=true`)

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `MCP_AUTH_ENABLED` | No | `false` | Enable OAuth 2.1 provider |
| `MCP_AUTH_SECRET` | If auth | — | `openssl rand -hex 32` — signs JWT access tokens |
| `MCP_AUTH_ISSUER` | If auth | — | Public HTTPS URL of this server |
| `MCP_AUTH_STATE_DIR` | No | `/app/state` | Directory for persisted OAuth state |
| `MCP_REGISTRATION_ENABLED` | No | `false` | Enable dynamic client registration |
| `MCP_REGISTRATION_TOKEN` | No | — | Bearer token to gate `POST /register` |
| `MCP_CIMD_ENABLED` | No | `true` (`false` when `MCP_REGISTRATION_TOKEN` is set) | Accept Client ID Metadata Document URLs as `client_id` |
| `MCP_CLIENT_ID` | No | — | Pre-seeded static client ID |
| `MCP_CLIENT_REDIRECT_URIS` | No | `""` (empty) | Comma-separated redirect URIs for static client |

## Architecture Layers

### 1. Entry Point (`index.ts`)

Reads `MCP_TRANSPORT` and delegates to `startStdio()` or `startHttp()`. No business logic here.

### 2. Server Factory (`server.ts`)

`createServer()` instantiates an `McpServer` and registers all tool modules via `registerTools()`. Called once at startup (stdio) or once per request (HTTP).

### 3. Client Layer (`client/`)

Infrastructure adapters for each Nextcloud API surface:

| File | Pattern | Used by |
|------|---------|---------|
| `webdav.ts` | Singleton | files, notes, cookbook |
| `caldav.ts` | Functional helper | tasks, calendar |
| `ocs.ts` | Functional helper | auth/login, system tools |
| `mail.ts`, `maps.ts`, etc. | Functional helpers | app tools |

The WebDAV client is a singleton to reuse connections across tool calls. CalDAV and OCS helpers are stateless functions.

### 4. Tool Layer (`tools/`)

Each tool module exports an array of tool definitions. Tools call client layer functions; they do not manage connections directly.

```typescript
export const myTool = {
  name: 'tool_name',
  description: 'What it does',
  inputSchema: z.object({ ... }),
  handler: async (args) => {
    // call client layer
    return { content: [{ type: 'text', text: '...' }] };
  },
};
```

### 5. Auth Layer (`auth/`)

`router.ts` holds the authorization server endpoints and `provider.ts` the grant logic behind them (`NextcloudOAuthProvider`). `verifyAccessToken` throws the v2 `OAuthError` with code `invalid_token`, which `requireBearerAuth` turns into a 401 challenge (RFC 6750 §3.1); any other error becomes a 500. The provider is instantiated once and shared for the lifetime of the HTTP process. Individual stores (`ClientsStore`, `CodeStore`, `RefreshStore`) manage state independently.

## Data Flow Examples

### stdio (local MCP client)

```
User message → MCP client → tools/call JSON-RPC
  → stdin → StdioServerTransport → McpServer
  → tool handler → Nextcloud API
  → response → McpServer → StdioServerTransport → stdout
  → MCP client → user
```

### HTTP + OAuth (remote MCP client)

```
MCP client → HTTPS → Express router
  → requireBearerAuth (verify HS256 JWT)
  → createMcpHandler → per-request McpServer (modern or legacy leg)
  → tool handler → Nextcloud API
  → response → transport → HTTP response
  → (server + transport torn down on connection close)
```

## Error Handling

Three levels:

1. **Tool handler** — catches domain errors (WebDAV 404, auth failure, etc.) and returns `{ isError: true }` to Claude
2. **Server** — catches unexpected exceptions from tool handlers
3. **Main** — catches fatal startup errors (`process.exit(1)`)

All errors are logged via pino before being surfaced.

## Security

- **Credentials** — environment variables only; never logged
- **Input validation** — Zod schemas on all tool inputs; prevents injection via type enforcement
- **OAuth/JWT** — PKCE enforced; access tokens are short-lived (1h); JWT signature, issuer and audience verified by `jose`; the internal service token (`MCP_INTERNAL_TOKEN`) is compared with `timingSafeEqual`
- **HTTPS** — enforced by the reverse proxy (Traefik/Caddy); the MCP container itself serves plain HTTP internally
- **Rate limiting** — Express handles concurrent requests; further rate limiting can be added at the proxy layer
- **DNS rebinding** — `allowedHosts` derived from `MCP_AUTH_ISSUER` hostname; `localhost`/`127.0.0.1` always allowed for health checks

## Logging

Structured JSON via [pino](https://getpino.io/), always to **stderr** (stdout is reserved for the MCP protocol in stdio mode).

```typescript
// src/logger.ts
export const logger = pino({ level: process.env.LOG_LEVEL ?? 'info' }, process.stderr);
```

| Level | Use case |
|-------|----------|
| `trace` / `debug` | Development, deep tracing |
| `info` | Default — startup, login, token events |
| `warn` | Auth failures, misconfiguration |
| `error` / `fatal` | Unexpected exceptions, fatal config errors |

Example output:
```json
{"level":30,"time":1700000000000,"msg":"[token] Access token issued","user":"alice","client":"claude-desktop"}
```

## Testing

```bash
cd mcp-server && npm test   # vitest unit tests
```

Tests live in `src/__tests__/`. Coverage includes:
- Auth provider (token sign/verify, code exchange, refresh rotation)
- Auth stores (persistence, TTL expiry)
- Transport setup (stdio + HTTP)
- Tool handlers (mocked clients)

End-to-end tests are in `docker/standalone/scripts/`:
- `test-oauth.sh` — full PKCE flow against a live server
- `test-tools.sh` — exercises core MCP tools against real Nextcloud

## Build & Deployment

### Development

```bash
npm run dev                       # hot reload via tsx (stdio)
MCP_TRANSPORT=http npm run dev    # HTTP on :3339
```

### Production

```bash
npm run build   # TypeScript → dist/
```

Docker is the standard production deployment. See `docker/standalone/` (HTTP + OAuth, external Nextcloud) or `hetzner/docker/` for cloud provisioning.

## References

- [MCP TypeScript SDK](https://github.com/modelcontextprotocol/typescript-sdk)
- [Model Context Protocol spec](https://modelcontextprotocol.io/)
- [WebDAV RFC 4918](https://tools.ietf.org/html/rfc4918)
- [CalDAV RFC 4791](https://tools.ietf.org/html/rfc4791)
- [Zod Documentation](https://zod.dev/)
