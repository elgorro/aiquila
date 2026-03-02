# AIquila MCP Server

MCP (Model Context Protocol) server that gives Claude full access to your Nextcloud instance — files, calendar, tasks, contacts, mail, maps, bookmarks, notes, and more. 113 tools across 18 categories.

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

### Docker / Claude.ai (HTTP transport)

See the [Docker setup guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md#docker--claudeai-http-transport) for running AIquila as an HTTP server with OAuth for Claude.ai.

## What It Can Do

| Category | Tools |
|----------|------:|
| Files | 8 |
| Status & Diagnostics | 2 |
| App Management | 4 |
| Tags | 6 |
| Security | 2 |
| Shares | 1 |
| OCC Command | 1 |
| Tasks | 6 |
| Calendar | 6 |
| Notes | 3 |
| Contacts | 6 |
| Cookbook | 6 |
| Bookmarks | 13 |
| Mail | 8 |
| Maps | 24 |
| Users | 4 |
| Groups | 4 |
| AIquila | 3 |
| **Total** | **113** |

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
- [Tools Reference](https://github.com/elgorro/aiquila/blob/main/docs/mcp/README.md) — all 113 tools documented
- [Architecture](https://github.com/elgorro/aiquila/blob/main/docs/dev/mcp-server-architecture.md) — design and internals
- [Full Documentation](https://github.com/elgorro/aiquila/blob/main/docs/) — complete docs index

## License

MIT — part of the [AIquila project](https://github.com/elgorro/aiquila).
