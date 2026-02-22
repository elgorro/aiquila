# MCP Server - Standalone Docker Setup

Run the AIquila MCP server in Docker, connecting to an **existing external Nextcloud instance**. No local Nextcloud, database, or other services needed.

## When to Use This

- You already have a Nextcloud instance (self-hosted or provider)
- You want to run the MCP server in a container for isolation
- You need HTTPS termination (included via Caddy)
- You want a quick way to expose the MCP HTTP endpoint for network clients

For the full development environment (with local Nextcloud), see [Docker Setup](../dev/docker-setup.md).

## Prerequisites

- Docker Engine 20.10+ and Docker Compose 2.0+
- A reachable Nextcloud instance with WebDAV access
- Nextcloud credentials (app password recommended)

## Quick Start

```bash
cd docker/standalone
cp .env.example .env
# Edit .env with your Nextcloud URL and credentials
nano .env
make up
```

## Configuration

Edit `.env` with your Nextcloud connection details:

| Variable | Description | Example |
|----------|-------------|---------|
| `NEXTCLOUD_URL` | Full URL of your Nextcloud instance | `https://cloud.example.com` |
| `NEXTCLOUD_USER` | Nextcloud username | `admin` |
| `NEXTCLOUD_PASSWORD` | Password or app password | `xxxxx-xxxxx-xxxxx` |
| `MCP_TRUST_PROXY` | Hop count or `true` when behind a reverse proxy (see [Reverse proxy](#reverse-proxy-traefik-nginx)) | `1` |
| `LOG_LEVEL` | Log verbosity: `trace`, `debug`, `info`, `warn`, `error`, `fatal` (default: `info`) | `warn` |

### Security: Use App Passwords

For better security, create a Nextcloud app password:

1. Log in to Nextcloud
2. Go to **Settings** → **Security** → **Devices & sessions**
3. Create a new app password named "MCP Server"
4. Use the generated password in `.env`

## Access Points

| Protocol | URL | Notes |
|----------|-----|-------|
| HTTPS | `https://localhost:3340/mcp` | Self-signed cert (Caddy) |
| HTTP | `http://localhost:3339/mcp` | Direct, no TLS |

## MCP Client Configuration

### Claude Desktop (Remote HTTP)

For Claude Desktop connecting to the dockerized MCP server:

```json
{
  "mcpServers": {
    "aiquila": {
      "url": "http://localhost:3339/mcp"
    }
  }
}
```

### Claude.ai (OAuth required)

Claude.ai requires OAuth 2.0 authentication before it will connect to a remote MCP server. AIquila includes a built-in OAuth provider — enable it by setting three extra variables in `.env`:

```env
MCP_AUTH_ENABLED=true
MCP_AUTH_SECRET=<run: openssl rand -hex 32>
MCP_AUTH_ISSUER=https://mcp.example.com   # your public HTTPS URL
```

Then restart and add `https://mcp.example.com/mcp` in Claude.ai under **Settings → Integrations → Add MCP Server**.

See the **[OAuth 2.0 Setup Guide](oauth.md)** for the full walkthrough, including HTTPS requirements, token lifetimes, and troubleshooting.

## Available Commands

Run from `docker/standalone/`:

| Command | Action |
|---------|--------|
| `make help` | Show all commands |
| `make up` | Start MCP server + Caddy |
| `make down` | Stop services |
| `make restart` | Restart services |
| `make logs` | Recent logs (last 100 lines) |
| `make logs-follow` | Stream logs in real time |
| `make status` | Service status |
| `make shell` | Shell in MCP container |
| `make build` | Rebuild container |
| `make clean` | Stop and remove everything |
| `make test` | Test connectivity |

Or from `docker/`:

| Command | Action |
|---------|--------|
| `make standalone-up` | Start standalone MCP server |
| `make standalone-down` | Stop standalone MCP server |
| `make standalone-logs` | Stream standalone logs |
| `make standalone-test` | Test standalone connectivity |

## Verification

```bash
# Test MCP endpoint
curl -X POST http://localhost:3339/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'

# Or use the built-in test
make test
```

## Troubleshooting

### "Set NEXTCLOUD_URL in .env" error

Ensure `.env` exists and all three variables are set:

```bash
cp .env.example .env
nano .env
```

### MCP server starts but tools fail

The server starts independently of Nextcloud connectivity. Tools will fail if:

- `NEXTCLOUD_URL` is unreachable from the container
- Credentials are wrong
- The Nextcloud instance requires VPN/firewall access

Test connectivity: `make test`

### External Nextcloud uses self-signed certificate

If your Nextcloud instance uses a self-signed certificate, add to `docker-compose.yml` under `mcp-server.environment`:

```yaml
NODE_TLS_REJECT_UNAUTHORIZED: "0"
```

**Warning**: Only use this in development. For production, mount the CA certificate instead.

### Certificate warnings on HTTPS (port 3340)

Caddy uses self-signed certificates in development. This is expected. For production, remove the `local_certs` directive from the Caddyfile.

### Port conflicts

If 3339 or 3340 are in use, edit `docker-compose.yml` to change the host ports (left side of the colon):

```yaml
ports:
  - "4339:3339"  # Change 3339 to any free port
```

### Reverse proxy (Traefik, nginx, …) — `ERR_ERL_UNEXPECTED_X_FORWARDED_FOR`

When the MCP server sits behind a reverse proxy, the proxy adds an `X-Forwarded-For` header. The built-in rate limiter throws `ERR_ERL_UNEXPECTED_X_FORWARDED_FOR` if Express doesn't know to trust that header.

Fix: set `MCP_TRUST_PROXY=1` in your `.env` (or however many proxy hops sit in front of the container):

```env
MCP_TRUST_PROXY=1
```

| Value | Meaning |
|-------|---------|
| `1` | Trust one hop — use this when a single reverse proxy (Traefik, nginx, Caddy) sits in front of the MCP server |
| `2`, `3`, … | Trust N hops — use when multiple proxies are stacked |
| `true` | Trust all proxies — only safe on private/trusted networks |

Restart after adding the variable:

```bash
make restart
```

### DNS resolution issues

If the MCP container cannot resolve your Nextcloud hostname, add to `docker-compose.yml` under the `mcp-server` service:

```yaml
extra_hosts:
  - "cloud.example.com:192.168.1.100"
```

## Architecture

```
┌─────────────────────────────────────┐
│         Docker Host                 │
│                                     │
│  ┌───────────────┐                  │
│  │ Caddy (HTTPS) │                  │       ┌──────────────┐
│  │ :3340         │                  │       │  External    │
│  └───────┬───────┘                  │       │  Nextcloud   │
│          │                          │       │  instance    │
│  ┌───────▼───────┐   NEXTCLOUD_URL  │       │              │
│  │  MCP Server   │ ────────────────────────>│  WebDAV      │
│  │  :3339 (http) │                  │       │  OCS API     │
│  │  (hot reload) │                  │       │  CalDAV      │
│  └───────────────┘                  │       └──────────────┘
└─────────────────────────────────────┘
```
