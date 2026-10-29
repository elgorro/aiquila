# OAuth 2.1 Authentication

AIquila's HTTP transport includes a built-in OAuth 2.1 provider so any MCP client that supports OAuth can connect to a remote server without an external identity service. Authentication is **opt-in** — existing stdio setups (local MCP clients) are completely unaffected.

The authorization server follows **OAuth 2.1**
([`draft-ietf-oauth-v2-1-16`](https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/)), which
the MCP authorization specification builds on:

- authorization code grant with PKCE `S256` only; no implicit or password grant
- exact redirect URI matching, except that loopback redirects may use any port
- bearer tokens in the `Authorization` header only, never in the query string
- refresh tokens rotate on every use, and reusing one ends the grant
- `redirect_uri` is optional in the token request, because PKCE already binds the code. An
  OAuth 2.0 client that sends it must send the value from the authorization request

## How It Works

When OAuth is enabled, the standard flow looks like this:

1. **The MCP client discovers** your server via the OAuth metadata endpoint (`/.well-known/oauth-authorization-server`)
2. **The client redirects you** to a Nextcloud login form hosted by the MCP server
3. **You enter your Nextcloud credentials** — the server validates them against your Nextcloud instance
4. **An access token (JWT) is issued** and stored by the client
5. **All subsequent MCP requests** carry this token automatically

Your Nextcloud credentials are only used during login and are never stored by the MCP server.

## Lazy authentication

MCP clients need to inspect a connector before you sign in — they must discover the
server and read the tool list to show you what the connector can do. So AIquila does
not demand a token for every request. It serves a small set of methods anonymously and
challenges only when a request would actually touch your Nextcloud instance.

**Readable without a token:**

- `server/discover` (protocol `2026-07-28`)
- `initialize`, `notifications/initialized`, `ping` (the handshake of `2025-11-25` and earlier)
- `tools/list`, `resources/list`, `resources/templates/list`, `prompts/list`
- the `get_local_time` tool, which reports the server's clock and timezone

**Everything else requires a Bearer token** — every tool that reaches Nextcloud (files,
calendar, contacts, Talk, …). Refusals are an HTTP `401` carrying a `WWW-Authenticate`
header, which is the signal that makes a client pause, run the OAuth flow, and retry the
same call. A JSON-RPC batch is a single HTTP request, so one protected message in a batch
gates the whole batch. `subscriptions/listen`, which holds a change-notification stream
open, also requires a token.

The method is read from the JSON-RPC body. Under `2026-07-28` the client also mirrors it
into the `Mcp-Method` header, and the server rejects a request whose header and body
disagree, so a header cannot get a protected call past the check.

> **Behaviour change (v0.3.30).** Before this release, *every* `/mcp` request required a
> token. Anyone who can reach your `/mcp` endpoint can now enumerate tool names,
> descriptions and input schemas, and read the server's timezone. No Nextcloud data is
> reachable without a token. If you deliberately treat `/mcp` as a fully closed endpoint,
> set `MCP_LAZY_AUTH=false` to restore the old behaviour.

| Variable | Default | Effect |
|----------|---------|--------|
| `MCP_LAZY_AUTH` | `true` | `false` requires a Bearer token on every JSON-RPC method, including `server/discover`, `initialize` and `tools/list`. Clients that cannot pre-authenticate will not be able to inspect the connector. |

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

A client has to be known to the server before it can sign in. There are three ways, and
they can be combined.

**Option A — Client ID Metadata Documents (on by default)**

Nothing to configure. A client that supports
[Client ID Metadata Documents](https://datatracker.ietf.org/doc/draft-ietf-oauth-client-id-metadata-document/)
(CIMD) uses an HTTPS URL as its `client_id`; that URL serves a JSON document with the
client's name and redirect URIs. The server fetches and validates the document when the
client first appears, so there is no registration step and no per-client state on the
server. The MCP specification (`2026-07-28`) makes CIMD the preferred mechanism, and
clients choose it whenever the metadata advertises `client_id_metadata_document_supported`.

| Variable | Default | Effect |
|---|---|---|
| `MCP_CIMD_ENABLED` | `true`, or `false` when `MCP_REGISTRATION_TOKEN` is set | `false` stops accepting URL `client_id`s and drops `client_id_metadata_document_supported` from the metadata |

CIMD lets any client start the sign-in flow — the user still has to enter valid Nextcloud
credentials. If you gate registration with `MCP_REGISTRATION_TOKEN` you have chosen who may
be a client, so CIMD is off by default in that case; set `MCP_CIMD_ENABLED=true` to have
both. See [Client ID Metadata Documents](#client-id-metadata-documents) under Security Notes
for what the server checks.

**Option B — Dynamic registration**

Enable dynamic registration so clients can self-register on first connect:

```env
MCP_REGISTRATION_ENABLED=true
# Optional: require a token to gate who may register
MCP_REGISTRATION_TOKEN=<openssl rand -hex 32>
```

This is the RFC 7591 approach. The MCP specification deprecates it in favour of CIMD but
keeps it for backward compatibility, and many clients still rely on it.

A registered client may only use redirect URIs the server trusts. Otherwise anyone could
register a redirect to their own host, send a user a sign-in link, and receive the
authorization code. The following are always allowed:

- loopback URIs on any port, such as `http://127.0.0.1:<port>/…` or `http://[::1]:<port>/…`, for native clients
- `https://` on `claude.ai`, `claude.com` and the host of `NEXTCLOUD_URL`. The AIquila
  Nextcloud app registers itself with a callback on that host
- private-use schemes that contain a dot, such as `com.example.app:/callback` (RFC 8252 §7.1)

| Variable | Default | Effect |
|---|---|---|
| `MCP_REDIRECT_ALLOWLIST` | empty | Comma-separated extra entries: a host (`vscode.dev`), a scheme (`cursor:`), or `*` for any `https` host |

Registration with any other redirect URI fails with `invalid_redirect_uri`. Plain `http`
is only allowed on loopback. Clients registered earlier whose redirect URIs no longer pass
are dropped when the server starts, and register again. If Nextcloud is reached over an
internal address (`NEXTCLOUD_URL=http://nc`), list its public host in
`MCP_REDIRECT_ALLOWLIST`. The Hetzner full stack does this by default.

With `MCP_REGISTRATION_TOKEN` set, `POST /register` answers `401` unless the request
carries `Authorization: Bearer <token>` (RFC 7591 §3.1 initial access token). Clients must
therefore be told the token — see
[Connecting the Nextcloud app to a gated server](#connecting-the-nextcloud-app-to-a-gated-server).

**Option C — Static pre-seeded client**

For clients that support neither, pre-seed a client ID and redirect URI:

```env
MCP_CLIENT_ID=<stable-identifier>
MCP_CLIENT_REDIRECT_URIS=<your-client-callback-url>
```

Check your MCP client's documentation for its callback URL. Example:
- **Claude.ai / Claude Code**: `https://claude.ai/api/mcp/auth_callback`

All three options work side by side.

#### Connecting the Nextcloud app to a gated server

The AIquila Nextcloud app registers itself dynamically when you click **Authenticate** on an
MCP server entry. If the server gates registration, paste the same value you set in
`MCP_REGISTRATION_TOKEN` into the **Registration token** field:

**Settings → Administration → AIquila → MCP servers** → edit the server → set
*Authentication* to **OAuth 2.0** → **Registration token**.

The token is encrypted at rest with Nextcloud's crypto service and only ever sent to the
server's `registration_endpoint`. Leave it empty for servers with open registration.
Changing the token discards the previously registered client and its access tokens, so the
next **Authenticate** registers again against the gated endpoint.

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
  "issuer": "https://mcp.example.com/",
  "authorization_endpoint": "https://mcp.example.com/authorize",
  "token_endpoint": "https://mcp.example.com/token",
  "revocation_endpoint": "https://mcp.example.com/revoke",
  "registration_endpoint": "https://mcp.example.com/register",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "code_challenge_methods_supported": ["S256"],
  "token_endpoint_auth_methods_supported": ["client_secret_post", "none"],
  "authorization_response_iss_parameter_supported": true,
  "client_id_metadata_document_supported": true
}
```

`registration_endpoint` appears only with `MCP_REGISTRATION_ENABLED=true`, and
`client_id_metadata_document_supported` only while CIMD is enabled.

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

Claude uses Client ID Metadata Documents when the server advertises them, which it does by
default — no registration settings are needed. With CIMD disabled, enable dynamic
registration instead (`MCP_REGISTRATION_ENABLED=true`).

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

Cursor supports MCP via HTTP. If your Cursor version does not use CIMD, enable dynamic registration with `MCP_REGISTRATION_ENABLED=true`.

In Cursor, add the server URL `https://mcp.example.com/mcp` in **Settings → MCP** (or your `~/.cursor/mcp.json`). Cursor will initiate the OAuth flow on first use.

### VS Code (MCP extensions)

VS Code MCP extensions (e.g. Continue, GitHub Copilot with MCP support) use CIMD or dynamic registration, depending on the extension. If it does not use CIMD, enable `MCP_REGISTRATION_ENABLED=true`, then add the server URL in the extension's settings.

### Other MCP clients

For any MCP client that supports OAuth 2.0 with PKCE:

1. If the client supports Client ID Metadata Documents, nothing is needed. Otherwise enable `MCP_REGISTRATION_ENABLED=true` (dynamic registration), **or** configure `MCP_CLIENT_ID` + `MCP_CLIENT_REDIRECT_URIS` with your client's callback URL.
2. Point your client at `https://mcp.example.com/mcp`.
3. Follow the OAuth flow prompted by your client.

## Token Lifetime

| Token | Lifetime | Notes |
|---|---|---|
| Access token | 1 hour | JWT signed with `MCP_AUTH_SECRET` |
| Refresh token | 24 hours | 256 random bits; rotated on each use. The server stores only its SHA-256 hash |

Refresh tokens and revocations are kept in `MCP_AUTH_STATE_DIR`, so sessions survive a
restart as long as that directory is persistent and writable.

### Ending a session

Every sign-in starts a *grant*. The refresh tokens rotated from it, and the access tokens
issued along the way, all belong to that grant.

- **Revoking a refresh token** at `/revoke` ends the whole grant. Its access tokens stop
  working at once, not when they expire (RFC 7009 §2.1).
- **Revoking an access token** ends only that token. The refresh token keeps working.
- **Presenting a refresh token a second time** ends the grant. A rotated token that comes
  back means two parties hold it, and the server cannot tell which one is the client, so
  it revokes everything and logs `Refresh token reuse detected` at `warn` (RFC 9700
  §4.14.2). A client that retries a refresh after a lost response triggers this too, and
  has to sign in again.
- **Disabling or deleting the user in Nextcloud** ends the grant at the next refresh, so
  within an hour at most. The server asks the provisioning API about the user, as
  `NEXTCLOUD_USER`. That needs admin rights. Without them the check cannot see other
  users, logs a warning once and lets the refresh through.
- **Changing the Nextcloud password does not end a grant.** The server never keeps the
  password, so it cannot tell that it changed. Nextcloud handles its own app passwords the
  same way. To cut off a client after a password change, revoke its refresh token, or
  disable the user until the next refresh has been refused.

## Security Notes

- **`MCP_AUTH_SECRET` is critical.** Rotate it by updating the env var and restarting. All existing tokens are immediately invalidated.
- The MCP server validates Nextcloud credentials on every login by calling your Nextcloud instance's OCS API. If Nextcloud is unreachable, logins will fail.
- Use a Nextcloud **app password** (not your main password) for the MCP login. This limits the blast radius if the credential is ever exposed. See [Security Best Practice: App Passwords](setup.md#security-best-practice-app-passwords).
- The login form is served over HTTPS (enforced by your reverse proxy). Never run the auth-enabled server without TLS.
- **Tool names, descriptions and input schemas are readable without a token** by default, along with the server's timezone. This is [lazy authentication](#lazy-authentication); no Nextcloud data is exposed. Set `MCP_LAZY_AUTH=false` if your threat model treats the tool inventory as sensitive.

### Client ID Metadata Documents

A CIMD `client_id` is chosen by whoever starts the flow, and the server fetches it. The
fetch is open to any public HTTPS host and checked as follows:

- The `client_id` must be an `https` URL with a path, without userinfo, fragment or dot
  segments.
- Every address the host resolves to is checked against the special-purpose ranges of
  RFC 6890 (private, loopback, link-local — where cloud metadata services live — CGNAT,
  multicast, IPv4-mapped IPv6 and others). If any one is listed, nothing is fetched. The
  connection goes to the address that passed the check, so a DNS answer that changes
  between check and connect cannot redirect it.
- Redirects are not followed. The response must be `200` with a JSON content type and at
  most 5 KB, and the fetch times out after 5 seconds.
- The document must name itself: its `client_id` must equal the URL exactly. It needs a
  `client_name` and at least one redirect URI, and must not carry a client secret. Only
  public clients (`token_endpoint_auth_method` absent or `none`) are accepted.
- Each redirect URI must share the `client_id` URL's origin, or be a loopback URI
  (`http://127.0.0.1`, `http://localhost`, `http://[::1]` on any port) for native clients.
- Valid documents are cached according to their `Cache-Control` header — 15 minutes when
  it has none, never more than 24 hours. Failures are never cached.

The document's `client_name` is chosen by its author, so the consent page shows the **host
of the `client_id` URL** instead. That host is the part of the client's identity the server
has verified.

A rejected document is logged at `warn` with the reason:

```
[auth] Client ID metadata document rejected  reason="… resolves to a special-purpose address (169.254.169.254)"
```

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
