# AIquila MCP Server

MCP (Model Context Protocol) server that gives any MCP client full access to your Nextcloud instance — files, calendar, tasks, contacts, mail, talk, maps, bookmarks, notes, polls, forms, and more. 316 tools across 43 categories.

## Quick Start

### Stdio transport (local MCP clients)

Add to your MCP client configuration. Example for Claude Desktop (`~/.config/claude/claude_desktop_config.json`); Claude Code, Cursor and VS Code use the same server definition in their own config files:

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

### HTTP transport (Docker, remote MCP clients)

See the [Docker setup guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md#docker--claudeai-http-transport) for running AIquila as an HTTP server with OAuth for remote MCP clients such as Claude.ai.

### Which AI model does this use?

None. This server has no model of its own and no provider setting — it exposes your
Nextcloud to whatever MCP client you point at it, and that client brings its own model.
Provider choice (Claude, Hetzner Inference, Mistral, a local model, DeepSeek) belongs to
the [AIquila Nextcloud app](https://github.com/elgorro/aiquila/blob/main/docs/installation/aiquila-setup.md), which is a separate component.

## What It Can Do

### Core (always available)

| Category      | Tools |
| ------------- | ----: |
| Files         |    15 |
| Shares        |    10 |
| Apps          |     6 |
| Tags          |     6 |
| User Status   |     5 |
| Users         |     4 |
| Groups        |     4 |
| Notifications |     4 |
| Status        |     3 |
| Absence       |     3 |
| Trash         |     3 |
| Search        |     2 |
| Versions      |     2 |
| Activity      |     2 |
| **Subtotal**  | **69** |

### AIquila app

| Category     | Tools |
| ------------ | ----: |
| Projects     |     7 |
| Cowork       |    12 |
| AIquila      |     3 |
| Security     |     2 |
| OCC          |     1 |
| **Subtotal** | **25** |

### Optional Nextcloud apps

| Category         | Tools |
| ---------------- | ----: |
| Maps             |    40 |
| Forms            |    25 |
| Polls            |    21 |
| News             |    17 |
| Bookmarks        |    13 |
| Deck             |    12 |
| Photos           |    11 |
| Mail             |    10 |
| Talk             |    10 |
| Circles          |     8 |
| Calendar         |     6 |
| Tasks            |     6 |
| Contacts         |     6 |
| Cookbook         |     6 |
| Notes            |     5 |
| Text             |     5 |
| Terms of Service |     5 |
| Assistant        |     4 |
| Announcements    |     3 |
| Passman          |     3 |
| Registration     |     3 |
| Translate        |     1 |
| Social Sharing   |     1 |
| Recommendations  |     1 |
| **Subtotal**     | **222** |

**Total: 316 tools.**

## Configuration

| Variable               | Required | Notes                                                                                 |
| ---------------------- | -------- | ------------------------------------------------------------------------------------- |
| `NEXTCLOUD_URL`        | Yes      | trailing slash stripped automatically                                                 |
| `NEXTCLOUD_USER`       | Yes      |                                                                                       |
| `NEXTCLOUD_PASSWORD`   | Yes      | use an App Password                                                                   |
| `MCP_TRANSPORT`        | No       | `stdio` (default) or `http`                                                           |
| `MCP_AUTH_ENABLED`     | No       | `true` to enable OAuth for remote clients                                             |
| `MCP_AUTH_SECRET`      | If auth  | `openssl rand -hex 32`                                                                |
| `MCP_AUTH_ISSUER`      | If auth  | public HTTPS URL of this server                                                       |
| `MCP_AUTH_STATE_DIR`   | No       | directory for persisted OAuth state (default `/app/state`)                            |
| `MCP_LAZY_AUTH`        | No       | `false` requires a bearer token on every JSON-RPC method (default `true`)             |
| `MCP_LOCALE`           | No       | BCP 47 locale tag for `get_local_time` (e.g. `sv-SE`); defaults to the system locale  |
| `MCP_MAX_FILE_SIZE`    | No       | max `write_file` content length in bytes (default `1073741824` = 1 GB)                |
| `MCP_ALLOWED_HOSTS`    | No       | extra hostnames for DNS rebinding protection                                          |
| `MCP_CORS_ORIGINS`     | No       | extra browser origins allowed via CORS                                                |
| `MCP_OCC_ALLOWLIST`    | No       | allowed OCC commands for `run_occ`; overrides the default allowlist                   |
| `LOG_LEVEL`            | No       | `trace`/`debug`/`info`/`warn`/`error`/`fatal`                                          |

## Requirements

- Node.js 26+
- A Nextcloud instance with an App Password

Optional Nextcloud apps unlock additional tool categories: Tasks, Calendar, Contacts, Notes, Cookbook, Deck, Bookmarks, Mail, Maps, Photos, Talk, Circles, and more.

## Documentation

- [Setup Guide](https://github.com/elgorro/aiquila/blob/main/docs/mcp/setup.md) — detailed installation and configuration
- [Tools Reference](https://github.com/elgorro/aiquila/blob/main/docs/mcp/README.md) — the full tool catalogue
- [Architecture](https://github.com/elgorro/aiquila/blob/main/docs/dev/mcp-server-architecture.md) — design and internals
- [Full Documentation](https://github.com/elgorro/aiquila/blob/main/docs/) — complete docs index

## License

MIT — part of the [AIquila project](https://github.com/elgorro/aiquila).
