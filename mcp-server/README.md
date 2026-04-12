# AIquila MCP Server

MCP (Model Context Protocol) server that gives any MCP client full access to your Nextcloud instance — files, calendar, tasks, contacts, mail, talk, maps, bookmarks, notes, and more. 198 tools across 31 categories.

## Quick Start

### Stdio (Claude Desktop, Claude Code, Cursor, etc.)

Add to your MCP client configuration (example for Claude Desktop `~/.config/claude/claude_desktop_config.json`):

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

### HTTP Transport (Docker, Claude.ai, remote clients)

See the [Docker setup guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md#docker--claudeai-http-transport) for running AIquila as an HTTP server with OAuth for remote MCP clients.

## What It Can Do

### Core (always available)

| Category   | Tools |
| ---------- | ----: |
| Files      |    12 |
| Status     |     3 |
| Apps       |     6 |
| Tags       |     6 |
| Search     |     2 |
| Users      |     4 |
| Groups     |     4 |
| Shares     |    10 |
| Absence    |     3 |
| Trash      |     3 |
| Versions   |     2 |

### AIquila app

| Category   | Tools |
| ---------- | ----: |
| AIquila    |     3 |
| Security   |     2 |
| OCC        |     1 |
| Projects   |     7 |

### Optional Nextcloud apps

| Category      | Tools |
| ------------- | ----: |
| Calendar      |     6 |
| Tasks         |     6 |
| Contacts      |     6 |
| Notes         |     5 |
| Mail          |     8 |
| Deck          |    12 |
| Cookbook       |     6 |
| Maps          |    25 |
| Photos        |    11 |
| Talk          |    10 |
| Circles       |     8 |
| Bookmarks     |    13 |
| Assistant     |     4 |
| Translate     |     1 |
| User Status   |     5 |
| Notifications |     4 |
| **Total**     | **198** |

## Configuration

| Variable             | Required | Notes                                         |
| -------------------- | -------- | --------------------------------------------- |
| `NEXTCLOUD_URL`      | Yes      | trailing slash stripped automatically         |
| `NEXTCLOUD_USER`     | Yes      |                                               |
| `NEXTCLOUD_PASSWORD` | Yes      | use an App Password                           |
| `MCP_TRANSPORT`      | No       | `stdio` (default) or `http`                   |
| `MCP_AUTH_ENABLED`   | No       | `true` to enable OAuth for remote clients     |
| `MCP_AUTH_SECRET`    | If auth  | `openssl rand -hex 32`                        |
| `MCP_AUTH_ISSUER`    | If auth  | public HTTPS URL of this server               |
| `LOG_LEVEL`          | No       | `trace`/`debug`/`info`/`warn`/`error`/`fatal` |

## Requirements

- Node.js 24+
- A Nextcloud instance with an App Password

Optional Nextcloud apps unlock additional tool categories: Tasks, Calendar, Contacts, Notes, Cookbook, Deck, Bookmarks, Mail, Maps, Photos, Talk, Circles, and more.

## Documentation

- [Setup Guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md) — detailed installation and configuration
- [Tools Reference](https://github.com/elgorro/aiquila/blob/main/docs/mcp/README.md) — all 198 tools documented
- [Architecture](https://github.com/elgorro/aiquila/blob/main/docs/dev/mcp-server-architecture.md) — design and internals
- [Full Documentation](https://github.com/elgorro/aiquila/blob/main/docs/) — complete docs index

## License

MIT — part of the [AIquila project](https://github.com/elgorro/aiquila).
