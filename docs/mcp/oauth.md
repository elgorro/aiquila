# OAuth 2.0 Authentication for Claude.ai

Claude.ai's MCP connector requires OAuth 2.0 before it will connect to a remote MCP server. AIquila's HTTP transport includes a built-in OAuth 2.1 provider that handles this automatically — no external identity service needed.

Authentication is **opt-in** via a single environment variable, so existing stdio and Claude Desktop setups are completely unaffected.

## How It Works

When OAuth is enabled, the flow looks like this:

1. **Claude.ai discovers** your server via the standard OAuth metadata endpoint (`/.well-known/oauth-authorization-server`)
2. **Claude.ai redirects you** to a Nextcloud login form hosted by the MCP server
3. **You enter your Nextcloud credentials** — the server validates them against your Nextcloud instance
4. **An access token (JWT) is issued** and stored by Claude.ai
5. **All subsequent MCP requests** carry this token automatically

Your Nextcloud credentials are only used during login and are never stored by the MCP server.

## Prerequisites

- AIquila MCP server running in HTTP transport mode (e.g. via [Standalone Docker](standalone-docker.md))
- A **public HTTPS URL** for the MCP server — Claude.ai must be able to reach it over the internet with a valid TLS certificate
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

`MCP_AUTH_ISSUER` must exactly match the public URL Claude.ai uses to reach your server. It appears in the OAuth metadata that Claude.ai fetches, so any mismatch will break the flow.

### 3. Ensure your server is reachable over HTTPS

Claude.ai requires a valid (non-self-signed) TLS certificate. Options:

- **Caddy with a real domain** — Point a domain at your server's IP, update the Caddyfile to use your domain instead of `local_certs`, and Caddy will obtain a Let's Encrypt certificate automatically.
- **Cloudflare Tunnel** — Route traffic through Cloudflare without opening firewall ports.
- **Any reverse proxy** — Nginx, Traefik, etc. — as long as the upstream URL matches `MCP_AUTH_ISSUER`.

> **Reverse proxy users:** If you run the MCP server behind Traefik, nginx, or any other proxy, also set `MCP_TRUST_PROXY=1` in your `.env`. Without it the built-in rate limiter will throw `ERR_ERL_UNEXPECTED_X_FORWARDED_FOR` when the proxy adds forwarding headers. See [Reverse proxy troubleshooting](standalone-docker.md#reverse-proxy-traefik-nginx--err_erl_unexpected_x_forwarded_for).

### 4. Restart the MCP server

```bash
cd docker/standalone
make restart
```

Confirm auth is active in the logs:

```
OAuth 2.0 authentication enabled (issuer: https://mcp.example.com)
```

### 5. Verify the OAuth metadata endpoint

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

### 6. Confirm unauthenticated requests are rejected

```bash
curl -i https://mcp.example.com/mcp
```

You should get `401 Unauthorized`. Without a valid Bearer token, all `/mcp` requests are blocked.

### 7. Add the server to Claude.ai

In Claude.ai, go to **Settings** → **Integrations** → **Add MCP Server** and enter:

```
https://mcp.example.com/mcp
```

Claude.ai will walk you through the OAuth login flow. When the Nextcloud login form appears, enter the credentials for the Nextcloud account you want Claude.ai to act as.

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

## Troubleshooting

### `/.well-known/oauth-authorization-server` returns 404

The OAuth router only mounts when `MCP_AUTH_ENABLED=true`. Check the container logs for the "OAuth 2.0 authentication enabled" line. If it's absent, the env var is not being read — verify your `.env` file and restart.

### Claude.ai shows "unable to connect" or "invalid issuer"

`MCP_AUTH_ISSUER` must exactly match the URL Claude.ai uses to reach the server, including scheme and any subdomain. Compare the value in `/.well-known/oauth-authorization-server` → `"issuer"` with the URL you entered in Claude.ai.

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

### Claude.ai reconnects frequently

This is usually caused by the container restarting (which clears in-memory tokens). Check `make logs` for crash/restart cycles and resolve the underlying issue.
