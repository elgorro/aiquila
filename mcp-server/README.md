# AIquila MCP Server

MCP (Model Context Protocol) server that gives Claude full access to your Nextcloud instance — files, calendar, tasks, contacts, mail, maps, bookmarks, notes, and more. 126 tools across 20 categories.

## Quick Start

### Claude Desktop (stdio)

Add to `~/.config/claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "npx",
      "args": ["-y", "aiquila-mcp"],
      "env": {
        "NEXTCLOUD_URL": "https://your-nextcloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

Generate an App Password in Nextcloud: **Settings → Security → Devices & sessions**.

### Docker / Claude.ai / Claude Mobile (HTTP transport)

See the [Docker setup guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md#docker--claudeai-http-transport) for running AIquila as an HTTP server with OAuth for Claude.ai and Claude Mobile.

## What It Can Do

| Category | Tools |
|----------|------:|
| Files | 11 |
| Status & Diagnostics | 3 |
| App Management | 6 |
| Tags | 6 |
| Security | 2 |
| Search | 2 |
| OCC Command | 1 |
| Shares | 4 |
| Tasks | 6 |
| Calendar | 6 |
| Notes | 5 |
| Contacts | 6 |
| Cookbook | 6 |
| Bookmarks | 13 |
| Mail | 8 |
| Maps | 26 |
| Assistant / AI | 4 |
| Users | 4 |
| Groups | 4 |
| AIquila | 3 |
| **Total** | **126** |

## Configuration

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXTCLOUD_URL` | Yes | trailing slash stripped automatically |
| `NEXTCLOUD_USER` | Yes | |
| `NEXTCLOUD_PASSWORD` | Yes | use an App Password |
| `MCP_TRANSPORT` | No | `stdio` (default) or `http` |
| `MCP_AUTH_ENABLED` | No | `true` to enable OAuth for Claude.ai |
| `MCP_AUTH_SECRET` | If auth | `openssl rand -hex 32` |
| `MCP_AUTH_ISSUER` | If auth | public HTTPS URL of this server |
| `LOG_LEVEL` | No | `trace`/`debug`/`info`/`warn`/`error`/`fatal` |

## Requirements

- Node.js 24+
- A Nextcloud instance with an App Password

Optional Nextcloud apps unlock additional tool categories: Tasks, Calendar, Contacts, Notes, Cookbook, Bookmarks, Mail, Maps.

## Documentation

- [Setup Guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md) — detailed installation and configuration
- [Tools Reference](https://github.com/elgorro/aiquila/blob/main/docs/mcp/README.md) — all 126 tools documented
- [Architecture](https://github.com/elgorro/aiquila/blob/main/docs/dev/mcp-server-architecture.md) — design and internals
- [Full Documentation](https://github.com/elgorro/aiquila/blob/main/docs/) — complete docs index

## License

MIT — part of the [AIquila project](https://github.com/elgorro/aiquila).
