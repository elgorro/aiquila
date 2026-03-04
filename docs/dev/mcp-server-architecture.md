# MCP Server Architecture (Developer Reference)

Technical architecture overview of the AIquila MCP Server for developers working on the codebase.

## Overview

The MCP server is a TypeScript/Node.js implementation of the [Model Context Protocol](https://modelcontextprotocol.io/) that exposes Nextcloud as a set of AI tools. It supports two deployment modes:

- **stdio** — Claude Desktop (local, single-process)
- **HTTP** — Claude.ai and other network clients (Docker, self-hosted)

## Technology Stack

- **Runtime**: Node.js 24+
- **Language**: TypeScript 5.8+ (strict mode)
- **Protocol**: Model Context Protocol via [`@modelcontextprotocol/sdk`](https://github.com/modelcontextprotocol/typescript-sdk) ^1.x
- **Transport**: `StdioServerTransport` or `StreamableHTTPServerTransport`
- **HTTP framework**: Express 5 (via MCP SDK's `createMcpExpressApp`)
- **Auth**: OAuth 2.1 + PKCE, HS256 JWT via `node:crypto` (no external JWT library)
- **Dependencies**:
  - `pino` ^9.x — structured JSON logging to stderr
  - `webdav` ^5.x — WebDAV client
  - `zod` ^3.x — schema validation
- **Dev Tools**: `vitest`, `tsx`, `eslint`, `prettier`

## Project Structure

```
mcp-server/src/
├── index.ts             # Transport selector ($MCP_TRANSPORT)
├── server.ts            # McpServer factory + tool registration
├── logger.ts            # pino → stderr
├── transports/
│   ├── stdio.ts         # StdioServerTransport (Claude Desktop)
│   └── http.ts          # Express app + StreamableHTTPServerTransport + auth middleware
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

### stdio (`StdioServerTransport`) — Claude Desktop

A single long-lived `McpServer` instance is created at startup and connected to `StdioServerTransport`. JSON-RPC messages flow over stdin/stdout.

```
Claude Desktop
     │
     │  JSON-RPC over stdin/stdout
     ▼
StdioServerTransport
     │
     ▼
McpServer (single instance, lifetime = process)
     │
     ▼
Tool handlers → Nextcloud APIs
```

### HTTP (`StreamableHTTPServerTransport`) — Claude.ai / network clients

**Per-request stateless**: a new `McpServer` + `StreamableHTTPServerTransport` is created for every incoming HTTP request, then torn down when the response closes.

```typescript
const handleMcpRequest = async (req, res) => {
  const mcpServer = createServer();
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
  await mcpServer.connect(transport);
  res.on('close', () => { transport.close(); mcpServer.close(); });
  await transport.handleRequest(req, res, req.body);
};
```

Key points:
- `sessionIdGenerator: undefined` — disables session tracking; no client affinity required
- Required for Claude.ai, which connects from multiple backend IPs
- `SSEServerTransport` (MCP protocol version 2024-11-05) was deprecated in May 2025 — AIquila does not use it

```
Claude.ai (multiple IPs)
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
1. Claude.ai  →  GET /.well-known/oauth-authorization-server
                 (discovery: endpoints, capabilities)

2. Claude.ai  →  POST /register  (if MCP_REGISTRATION_ENABLED=true)
                 (dynamic client registration)

3. Claude.ai  →  GET /authorize?response_type=code&code_challenge=...
                 (PKCE auth code request)

4. Server     →  200 HTML login form (POST /auth/login)

5. User       →  POST /auth/login  {username, password, code_challenge, ...}

6. Server     →  validates credentials against OCS API
                 /ocs/v2.php/cloud/user

7. Server     →  302 redirect to client redirect_uri?code=<uuid>

8. Claude.ai  →  POST /token  {code, code_verifier}
                 (PKCE token exchange)

9. Server     →  {access_token: <HS256 JWT, 1h>, refresh_token: <UUID, 24h>}

10. Claude.ai  →  POST /mcp  Authorization: Bearer <access_token>
                  (every MCP request verified via requireBearerAuth middleware)
```

### Token Details

| Token | Format | TTL | Storage |
|-------|--------|-----|---------|
| Authorization code | UUID | 5 min | In-memory (`CodeStore`) |
| Access token | HS256 JWT (node:crypto) | 1 hour | Stateless (verified by signature) |
| Refresh token | UUID | 24 hours | Disk-persisted (`RefreshStore`) |

### Auth Storage

Token state is persisted to `MCP_AUTH_STATE_DIR` (default `/app/state`):

- `clients.json` — dynamically registered OAuth clients (`ClientsStore`)
- `refresh-tokens.json` — active refresh tokens (`RefreshStore`)

Authorization codes are in-memory only (5-min TTL, lost on restart by design).

### Client Configuration

Two options for OAuth clients:

**Static pre-seeded client** (for Claude.ai):
```
MCP_CLIENT_ID=<uuid>
MCP_CLIENT_REDIRECT_URIS=https://claude.ai/api/mcp/auth_callback  # default
```

**Dynamic registration** (for any client):
```
MCP_REGISTRATION_ENABLED=true
MCP_REGISTRATION_TOKEN=<secret>  # optional: gates POST /register with Bearer token
```

## Environment Variables

### Core

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `NEXTCLOUD_URL` | Yes | — | Trailing slash stripped automatically |
| `NEXTCLOUD_USER` | Yes | — | |
| `NEXTCLOUD_PASSWORD` | Yes | — | Use an app password |
| `MCP_TRANSPORT` | No | `stdio` | `stdio` or `http` |
| `LOG_LEVEL` | No | `info` | `trace` / `debug` / `info` / `warn` / `error` / `fatal` |

### HTTP Transport

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `MCP_PORT` | No | `3339` | Listening port |
| `MCP_HOST` | No | `0.0.0.0` | Bind address |
| `MCP_TRUST_PROXY` | No | `false` | Set to `1` when behind a single reverse proxy |
| `MCP_TLS_STRICT` | No | `false` | Set `true` to fail fast on TLS cert errors |

### Auth (`MCP_AUTH_ENABLED=true`)

| Variable | Required | Default | Notes |
|----------|----------|---------|-------|
| `MCP_AUTH_ENABLED` | No | `false` | Enable OAuth 2.1 provider |
| `MCP_AUTH_SECRET` | If auth | — | `openssl rand -hex 32` — signs JWT access tokens |
| `MCP_AUTH_ISSUER` | If auth | — | Public HTTPS URL of this server |
| `MCP_AUTH_STATE_DIR` | No | `/app/state` | Directory for persisted OAuth state |
| `MCP_REGISTRATION_ENABLED` | No | `false` | Enable dynamic client registration |
| `MCP_REGISTRATION_TOKEN` | No | — | Bearer token to gate `POST /register` |
| `MCP_CLIENT_ID` | No | — | Pre-seeded static client ID |
| `MCP_CLIENT_REDIRECT_URIS` | No | `https://claude.ai/api/mcp/auth_callback` | Comma-separated redirect URIs for static client |

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

Implements `OAuthServerProvider` from the MCP SDK. The provider is instantiated once and shared for the lifetime of the HTTP process. Individual stores (`ClientsStore`, `CodeStore`, `RefreshStore`) manage state independently.

## Data Flow Examples

### stdio (Claude Desktop)

```
User message → Claude → tools/call JSON-RPC
  → stdin → StdioServerTransport → McpServer
  → tool handler → Nextcloud API
  → response → McpServer → StdioServerTransport → stdout
  → Claude → user
```

### HTTP + OAuth (Claude.ai)

```
Claude.ai → HTTPS → Express router
  → requireBearerAuth (verify HS256 JWT)
  → per-request McpServer + StreamableHTTPServerTransport
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
- **OAuth/JWT** — PKCE enforced; access tokens are short-lived (1h); token signatures verified with `timingSafeEqual`
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
