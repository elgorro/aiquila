# OAuth 2.0 Authentication

AIquila's HTTP transport includes a built-in OAuth 2.1 provider so any MCP client that supports OAuth can connect to a remote server without an external identity service. Authentication is **opt-in** — existing stdio setups (local MCP clients) are completely unaffected.

## How It Works

When OAuth is enabled, the standard flow looks like this:

1. **The MCP client discovers** your server via the OAuth metadata endpoint (`/.well-known/oauth-authorization-server`)
2. **The client redirects you** to a Nextcloud login form hosted by the MCP server
3. **You enter your Nextcloud credentials** — the server validates them against your Nextcloud instance
4. **An access token (JWT) is issued** and stored by the client
5. **All subsequent MCP requests** carry this token automatically

Your Nextcloud credentials are only used during login and are never stored by the MCP server.

## Lazy authentication

MCP clients need to inspect a connector before you sign in — they must complete the
handshake and read the tool list to show you what the connector can do. So AIquila does
not demand a token for every request. It serves a small set of methods anonymously and
challenges only when a request would actually touch your Nextcloud instance.

**Readable without a token:**

- `initialize`, `notifications/initialized`, `ping`
- `tools/list`, `resources/list`, `resources/templates/list`, `prompts/list`
- the `get_local_time` tool, which reports the server's clock and timezone

**Everything else requires a Bearer token** — every tool that reaches Nextcloud (files,
calendar, contacts, Talk, …). Refusals are an HTTP `401` carrying a `WWW-Authenticate`
header, which is the signal that makes a client pause, run the OAuth flow, and retry the
same call. A JSON-RPC batch is a single HTTP request, so one protected message in a batch
gates the whole batch.

> **Behaviour change (v0.3.30).** Before this release, *every* `/mcp` request required a
> token. Anyone who can reach your `/mcp` endpoint can now enumerate tool names,
> descriptions and input schemas, and read the server's timezone. No Nextcloud data is
> reachable without a token. If you deliberately treat `/mcp` as a fully closed endpoint,
> set `MCP_LAZY_AUTH=false` to restore the old behaviour.

| Variable | Default | Effect |
|----------|---------|--------|
| `MCP_LAZY_AUTH` | `true` | `false` requires a Bearer token on every JSON-RPC method, including `initialize` and `tools/list`. Clients that cannot pre-authenticate will not be able to inspect the connector. |

The startup log tells you which mode you are in:

```
Lazy auth enabled — tools/list and public tools are readable without a token (set MCP_LAZY_AUTH=false to require one)
```

## Prerequisites

- AIquila MCP server running in HTTP transport mode (e.g. via [Standalone Docker](standalone-docker.md))
- A **public HTTPS URL** for the MCP server — the client must be able to reach it over the internet with a valid TLS certificate
- `openssl` available locally to generate a signing key

## Setup

### 1. Generate a JWT signing secret

```bash
openssl rand -hex 32
```

Copy the output — you'll use it as `MCP_AUTH_SECRET`. Keep it safe; anyone with this value can forge tokens.

### 2. Configure your `.env`

In `docker/standalone/.env`, add the three auth variables below the Nextcloud block:

```env
MCP_AUTH_ENABLED=true
MCP_AUTH_SECRET=<paste the openssl output here>
MCP_AUTH_ISSUER=https://mcp.example.com
```

| Variable | Required | Description |
|---|---|---|
| `MCP_AUTH_ENABLED` | — | Set to `true` to enable OAuth. Default: `false` |
| `MCP_AUTH_SECRET` | When auth on | HS256 signing key. Min 32 characters. Generate with `openssl rand -hex 32` |
| `MCP_AUTH_ISSUER` | When auth on | Public HTTPS base URL of your MCP server (no trailing slash) |

`MCP_AUTH_ISSUER` must exactly match the public URL your MCP client uses to reach your server. It appears in the OAuth metadata that clients fetch, so any mismatch will break the flow.

### 3. Configure client registration

Choose one of two approaches:

**Option A — Dynamic registration (recommended for most clients)**

Enable dynamic registration so clients can self-register on first connect:

```env
MCP_REGISTRATION_ENABLED=true
# Optional: require a token to gate who may register
MCP_REGISTRATION_TOKEN=<openssl rand -hex 32>
```

This is the standard OAuth 2.0 approach and works with Claude.ai, Cursor, VS Code extensions, and any other client that supports RFC 7591.

**Option B — Static pre-seeded client**

For clients that do not support dynamic registration, pre-seed a client ID and redirect URI:

```env
MCP_CLIENT_ID=<stable-identifier>
MCP_CLIENT_REDIRECT_URIS=<your-client-callback-url>
```

Check your MCP client's documentation for its callback URL. Example:
- **Claude.ai / Claude Code**: `https://claude.ai/api/mcp/auth_callback`

You can combine both options — a pre-seeded client and dynamic registration simultaneously.

### 4. Ensure your server is reachable over HTTPS

Most MCP clients require a valid (non-self-signed) TLS certificate. Options:

- **Caddy with a real domain** — Point a domain at your server's IP, update the Caddyfile to use your domain instead of `local_certs`, and Caddy will obtain a Let's Encrypt certificate automatically.
- **nginx + certbot** — See [nginx + Let's Encrypt](standalone-docker.md#option-b-nginx--certbot) in the standalone Docker guide.
- **Cloudflare Tunnel** — Route traffic through Cloudflare without opening firewall ports.
- **Any reverse proxy** — Nginx, Traefik, etc. — as long as the upstream URL matches `MCP_AUTH_ISSUER`.

> **Reverse proxy users:** If you run the MCP server behind Traefik, nginx, or any other proxy, also set `MCP_TRUST_PROXY=1` in your `.env`. Without it the built-in rate limiter will throw `ERR_ERL_UNEXPECTED_X_FORWARDED_FOR` when the proxy adds forwarding headers. See [Reverse proxy troubleshooting](standalone-docker.md#reverse-proxy-traefik-nginx--err_erl_unexpected_x_forwarded_for).

### 5. Restart the MCP server

```bash
cd docker/standalone
make restart
```

Confirm auth is active in the logs:

```
OAuth 2.0 authentication enabled (issuer: https://mcp.example.com)
```

### 6. Verify the OAuth metadata endpoint

```bash
curl https://mcp.example.com/.well-known/oauth-authorization-server
```

You should receive a JSON document similar to:

```json
{
  "issuer": "https://mcp.example.com",
  "authorization_endpoint": "https://mcp.example.com/oauth/authorize",
  "token_endpoint": "https://mcp.example.com/oauth/token",
  "registration_endpoint": "https://mcp.example.com/oauth/register",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "code_challenge_methods_supported": ["S256"]
}
```

### 7. Confirm protected requests are rejected

Listing the server's tools works without a token — see [Lazy authentication](#lazy-authentication):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://mcp.example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
# 200
```

Calling a tool that touches Nextcloud does not:

```bash
curl -i https://mcp.example.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_files","arguments":{"path":"/"}}}'
```

You should get `401 Unauthorized` with a challenge header pointing at the protected
resource metadata:

```http
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer error="invalid_token", error_description="Missing Authorization header", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp", scope="mcp:tools"
```

That header is what makes an MCP client prompt you to sign in and then retry the same
call. If you set `MCP_LAZY_AUTH=false`, the first request returns `401` too.

## Client-Specific Setup

### Claude.ai / Claude Code

Claude.ai uses dynamic registration. Enable it with `MCP_REGISTRATION_ENABLED=true`.

In Claude.ai, go to **Settings** → **Integrations** → **Add MCP Server** and enter:

```
https://mcp.example.com/mcp
```

Claude.ai will walk you through the OAuth login flow. When the Nextcloud login form appears, enter the credentials for the Nextcloud account you want Claude.ai to act as.

In Claude Code (CLI), add the server:

```bash
claude mcp add --transport http https://mcp.example.com/mcp
```

### Cursor

Cursor supports MCP via HTTP and uses dynamic registration. Enable `MCP_REGISTRATION_ENABLED=true`.

In Cursor, add the server URL `https://mcp.example.com/mcp` in **Settings → MCP** (or your `~/.cursor/mcp.json`). Cursor will initiate the OAuth flow on first use.

### VS Code (MCP extensions)

VS Code MCP extensions (e.g. Continue, GitHub Copilot with MCP support) also use dynamic registration. Enable `MCP_REGISTRATION_ENABLED=true` and add the server URL in the extension's settings.

### Other MCP clients

For any MCP client that supports OAuth 2.0 with PKCE:

1. Enable `MCP_REGISTRATION_ENABLED=true` (dynamic registration, recommended), **or** configure `MCP_CLIENT_ID` + `MCP_CLIENT_REDIRECT_URIS` with your client's callback URL.
2. Point your client at `https://mcp.example.com/mcp`.
3. Follow the OAuth flow prompted by your client.

## Token Lifetime

| Token | Lifetime | Notes |
|---|---|---|
| Access token | 1 hour | JWT signed with `MCP_AUTH_SECRET` |
| Refresh token | 24 hours | Opaque UUID stored in memory; rotated on each use |

Tokens are stored in memory only — restarting the container invalidates all active sessions. Users will need to re-authenticate after a restart.

## Security Notes

- **`MCP_AUTH_SECRET` is critical.** Rotate it by updating the env var and restarting. All existing tokens are immediately invalidated.
- The MCP server validates Nextcloud credentials on every login by calling your Nextcloud instance's OCS API. If Nextcloud is unreachable, logins will fail.
- Use a Nextcloud **app password** (not your main password) for the MCP login. This limits the blast radius if the credential is ever exposed. See [Security Best Practice: App Passwords](setup.md#security-best-practice-app-passwords).
- The login form is served over HTTPS (enforced by your reverse proxy). Never run the auth-enabled server without TLS.
- **Tool names, descriptions and input schemas are readable without a token** by default, along with the server's timezone. This is [lazy authentication](#lazy-authentication); no Nextcloud data is exposed. Set `MCP_LAZY_AUTH=false` if your threat model treats the tool inventory as sensitive.

## Troubleshooting

### `/.well-known/oauth-authorization-server` returns 404

The OAuth router only mounts when `MCP_AUTH_ENABLED=true`. Check the container logs for the "OAuth 2.0 authentication enabled" line. If it's absent, the env var is not being read — verify your `.env` file and restart.

### `/mcp` returns 500 instead of 401 for an expired token

Fixed. Older versions raised a generic error when an access token was invalid or expired,
which the MCP SDK turned into `500 Internal Server Error`. Clients read that as a server
fault rather than a cue to re-authenticate, so the session appeared to hang after a token
aged out. The server now answers `401` with a `WWW-Authenticate` challenge and the client
silently refreshes. Upgrade if you see 500s correlated with the one-hour token lifetime.

### Client shows "unable to connect" or "invalid issuer"

`MCP_AUTH_ISSUER` must exactly match the URL your MCP client uses to reach the server, including scheme and any subdomain. Compare the value in `/.well-known/oauth-authorization-server` → `"issuer"` with the URL you entered in your client.

### Login form appears but credentials are rejected

- Confirm `NEXTCLOUD_URL` is set and reachable from inside the container: `make shell` → `curl $NEXTCLOUD_URL/status.php`
- Verify the Nextcloud username and password are correct
- If using an app password, make sure it hasn't expired or been revoked

### `MCP_AUTH_SECRET` or `MCP_AUTH_ISSUER` missing on startup

The server will exit with a clear error message if either variable is missing when `MCP_AUTH_ENABLED=true`:

```
Error: MCP_AUTH_ISSUER must be set when MCP_AUTH_ENABLED=true
Error: MCP_AUTH_SECRET must be set when MCP_AUTH_ENABLED=true
```

Set the missing variable in `.env` and restart.

### Client reconnects frequently

Some clients (e.g. Claude.ai) connect from multiple backend IPs. AIquila uses per-request stateless transport — each request is handled independently with no session affinity required, so this is handled automatically.

If you see frequent reconnects, the container may be restarting and clearing in-memory tokens. Check `make logs` for crash/restart cycles.

### Pre-seeded client has no redirect URIs (warning in logs)

If you see:

```
[config] MCP_CLIENT_ID is set but MCP_CLIENT_REDIRECT_URIS is empty
```

Set `MCP_CLIENT_REDIRECT_URIS` to your client's callback URL, or switch to dynamic registration with `MCP_REGISTRATION_ENABLED=true`.
