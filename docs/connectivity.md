# Configuration & Connectivity

## Architecture Overview

```
┌─────────────────────┐
│   MCP Client        │
│  (Claude, Cursor,   │
│   VS Code, …)       │
└─────────┬───────────┘
          │ MCP Protocol (stdio or HTTP)
          ▼
┌─────────────────────┐
│   MCP Server        │
│   (Node.js)         │
└─────────┬───────────┘
          │ WebDAV / CalDAV
          ▼
┌─────────────────────┐         ┌─────────────────────┐
│   Nextcloud         │◄────────│   AIquila App    │
│   Instance          │         │   (PHP)             │
└─────────────────────┘         └─────────┬───────────┘
                                          │ HTTPS
                                          ▼
                                ┌─────────────────────┐
                                │   Claude API        │
                                │   (Anthropic)       │
                                └─────────────────────┘
```

## MCP Server Configuration

### Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `NEXTCLOUD_URL` | Yes | Full URL to your Nextcloud instance (e.g., `https://cloud.example.com`) |
| `NEXTCLOUD_USER` | Yes | Nextcloud username |
| `NEXTCLOUD_PASSWORD` | Yes | App password (not your main password!) |

### Stdio MCP Client Config

Example for Claude Desktop (check your client's docs for config location):
- **Linux**: `~/.config/claude/claude_desktop_config.json`
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/path/to/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.example.com",
        "NEXTCLOUD_USER": "username",
        "NEXTCLOUD_PASSWORD": "xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
      }
    }
  }
}
```

### Using with HTTP-based MCP Clients

Remote MCP clients (Claude.ai, Cursor, VS Code extensions, etc.) connect over HTTP rather than stdio. The MCP server must be accessible from the client. Options:

1. **Local network**: Run the MCP server on a machine accessible on your network
2. **Remote server**: Deploy the MCP server on your Nextcloud host or a VPS
3. **Tailscale/VPN**: Connect your client device to your home network

HTTP-based clients also require OAuth 2.0 authentication. See the **[OAuth 2.0 Setup Guide](mcp/oauth.md)** for setup instructions.

## Nextcloud App Configuration

### Admin Settings

Path: **Settings → Administration → AIquila**

- **API Key**: Claude API key (starts with `sk-ant-`)
- This key is used for all users who don't have a personal key

### User Settings

Path: **Settings → AIquila**

- Users can set their own API key to override the admin key
- Useful for billing separation or different API plans

## Network Requirements

### MCP Server → Nextcloud

The MCP server needs access to:

| Endpoint | Protocol | Purpose |
|----------|----------|---------|
| `/remote.php/dav/files/{user}/` | WebDAV (HTTPS) | File operations |
| `/remote.php/dav/calendars/{user}/` | CalDAV (HTTPS) | Task management |

### Nextcloud App → Claude API

The Nextcloud server needs outbound access to:

| Host | Port | Purpose |
|------|------|---------|
| `api.anthropic.com` | 443 | Claude API calls |

## Security Considerations

### App Passwords

Always use Nextcloud App Passwords instead of your main password:

1. Go to **Settings → Security → Devices & sessions**
2. Enter a name (e.g., "AIquila MCP")
3. Click **Create new app password**
4. Use this password in your configuration

Benefits:
- Can be revoked independently
- Limited scope
- Doesn't expose your main password

### API Key Storage

- API keys are stored via Nextcloud's `ICredentialsManager` (encrypted at rest)
- MCP server tokens (bearer, OAuth) are encrypted via `ICrypto` before database storage
- On upgrade, existing plaintext keys are automatically migrated to encrypted storage
- Keys are never exposed in admin UI or CLI output — only `(configured)` / `****` is shown

### HTTPS

- Always use HTTPS for your Nextcloud instance
- MCP server connections use HTTPS by default
- Self-signed certificates may require additional configuration

## Firewall Configuration

### If Nextcloud is behind a firewall

Ensure outbound HTTPS (port 443) is allowed to:
- `api.anthropic.com`

### If running MCP server remotely

The MCP server communicates via stdio with local MCP clients, so no inbound ports are needed on the MCP server machine. It only needs outbound access to your Nextcloud instance.

## Proxy Configuration

### MCP Server behind proxy

```bash
export HTTP_PROXY=http://proxy:8080
export HTTPS_PROXY=http://proxy:8080
node dist/index.js
```

### Nextcloud behind proxy

Configure in `config/config.php`:
```php
'proxy' => 'proxy:8080',
'proxyuserpwd' => 'user:password',  // if needed
```
