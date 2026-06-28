# AIquila Documentation

Complete documentation for the AIquila Nextcloud app and MCP server.

## Getting Started

**[Getting Started Guide](installation.md)** — five paths to get up and running:
1. **Local MCP Client** — `npx aiquila-mcp` (simplest — Claude Desktop, Cursor, VS Code, etc.)
2. **Remote MCP Client** — Docker + OAuth (Claude.ai, Cursor, VS Code, etc.)
3. **Nextcloud App** — AI inside Nextcloud UI
4. **Hetzner Cloud** — single-command production deploy
5. **Mobile MCP Client** — voice-driven Nextcloud via mobile app

## MCP Server

- **[MCP Overview & Tools Reference](mcp/README.md)** — 100+ tools across 20 modules
- **[Setup Guide](mcp/setup.md)** — installation and MCP client configuration
- **[OAuth 2.0](mcp/oauth.md)** — OAuth authentication for remote MCP clients
- **[Standalone Docker](mcp/standalone-docker.md)** — run MCP server in Docker (external Nextcloud)
- **[MCP-Connector](mcp/mcp-connector.md)** — use AIquila via the Anthropic Messages API

### Tool Documentation

| Category | Tools | Documentation |
|----------|-------|---------------|
| Files, status, apps, security, search | 25 | [System Tools](mcp/tools/system-tools.md) |
| Calendar events | 6 | [Calendar](mcp/tools/apps/calendar.md) |
| Tasks (CalDAV) | 6 | [Tasks](mcp/tools/apps/tasks.md) |
| Contacts (CardDAV) | 6 | [Contacts](mcp/tools/apps/contacts.md) |
| Email | 8 | [Mail](mcp/tools/apps/mail.md) |
| Bookmarks, folders, tags | 13 | [Bookmarks](mcp/tools/apps/bookmarks.md) |
| Maps, GPS, tracks, photos | 26 | [Maps](mcp/tools/apps/maps.md) |
| Notes | 5 | [Notes](mcp/tools/apps/notes.md) |
| News (RSS feeds) | 17 | [News](mcp/tools/apps/news.md) |
| Recipes | 6 | [Cookbook](mcp/tools/apps/cookbook.md) |
| NC AI tasks & image gen | 4 | [Assistant](mcp/tools/apps/assistant.md) |
| File shares | 4 | [Shares](mcp/tools/apps/shares.md) |
| Users & groups | 8 | [Users](mcp/tools/apps/users.md) / [Groups](mcp/tools/apps/groups.md) |
| AIquila config | 3 | [AIquila](mcp/tools/apps/aiquila.md) |

## Nextcloud App

- **[AIquila App Setup](installation/aiquila-setup.md)** — installation, configuration, and troubleshooting
- **[Internal API Guide](internal-api.md)** — integrate AIquila AI (ask/summarize/analyze) into your own Nextcloud apps
- **[Cowork Management API](cowork-api.md)** — register, steer and verify scheduled cowork jobs from your own Nextcloud app
- **[Monitoring](monitoring.md)** — OpenMetrics / Prometheus export for usage and task metrics

## Deployment

- **[Hetzner Cloud](hetzner/README.md)** — overview and quickstart
  - [Commands](hetzner/commands.md) | [Configuration](hetzner/configuration.md) | [Advanced](hetzner/advanced.md)
  - [Traefik](hetzner/traefik.md) | [CrowdSec](hetzner/crowdsec.md) | [Storage Box](hetzner/storage-box.md)
  - [CI Flow](hetzner/ci-flow.md) | [Integration Tests](hetzner/integration-test.md) | [Audit Log](hetzner/audit-log.md)
- **[Connectivity Guide](connectivity.md)** — network and connection troubleshooting

## Development

- **[Development Guide](dev/development.md)** — contributing and workflow
- **[Docker Setup](dev/docker-setup.md)** — development environment (`docker/installation/`)
- **[Best Practices](dev/best-practices.md)** — code quality and standards
- **[CI/CD](dev/ci-cd.md)** — continuous integration and deployment
- **[MCP Server Architecture](dev/mcp-server-architecture.md)** — technical design
- **[OpenAPI](dev/openapi.md)** — OpenAPI documentation
- **MCP Development** — [Architecture](mcp/development/architecture.md) | [Adding Tools](mcp/development/adding-tools.md) | [Adding Apps](mcp/development/adding-apps.md)

## Documentation Structure

```
docs/
├── README.md                        # This file — navigation hub
├── installation.md                  # Getting started guide
├── connectivity.md                  # Network & connection troubleshooting
├── internal-api.md                  # Nextcloud app internal AI API
├── cowork-api.md                    # Cowork job management API (ICoworkManager)
│
├── installation/                    # Nextcloud app setup
│   └── aiquila-setup.md            # Full installation & config guide
│
├── mcp/                             # MCP Server
│   ├── README.md                    # Overview & full tools reference
│   ├── setup.md                     # Setup guide (MCP client / npx)
│   ├── oauth.md                     # OAuth 2.0 for remote MCP clients
│   ├── standalone-docker.md         # Standalone Docker deployment
│   ├── mcp-connector.md            # MCP-Connector (Messages API)
│   ├── tools/                       # Tool documentation
│   │   ├── system-tools.md          # Files, status, apps, security, search
│   │   └── apps/                    # App-specific tools
│   │       ├── aiquila.md           # AIquila config & test
│   │       ├── assistant.md         # NC AI task processing
│   │       ├── bookmarks.md         # Bookmarks, folders, tags
│   │       ├── calendar.md          # Calendar events
│   │       ├── contacts.md          # Contacts via CardDAV
│   │       ├── cookbook.md           # Recipes (schema.org)
│   │       ├── groups.md            # Group management
│   │       ├── mail.md              # Email accounts & messages
│   │       ├── maps.md              # Maps, GPS, tracks, photos
│   │       ├── notes.md             # Markdown notes
│   │       ├── shares.md            # File sharing
│   │       ├── tasks.md             # Tasks via CalDAV
│   │       └── users.md             # User management
│   └── development/                 # MCP development guides
│       ├── architecture.md          # Architecture overview
│       ├── adding-tools.md          # How to add new tools
│       └── adding-apps.md          # How to add new app integrations
│
├── hetzner/                         # Hetzner Cloud deployment
│   ├── README.md                    # Overview & quickstart
│   ├── commands.md                  # CLI command reference
│   ├── configuration.md             # Configuration & env vars
│   ├── advanced.md                  # Advanced usage
│   ├── traefik.md                   # Traefik reverse proxy
│   ├── crowdsec.md                  # CrowdSec intrusion prevention
│   ├── storage-box.md              # Hetzner Storage Box backups
│   ├── ci-flow.md                   # CI/CD flow
│   ├── integration-test.md          # Integration test workflow
│   └── audit-log.md                # Audit logging
│
├── dev/                             # Development documentation
│   ├── docker-setup.md              # Docker dev environment
│   ├── development.md               # Contributing & workflow
│   ├── best-practices.md            # Code quality guidelines
│   ├── ci-cd.md                     # CI/CD setup
│   ├── mcp-server-architecture.md   # MCP technical architecture
│   └── openapi.md                   # OpenAPI documentation
```

## Resources

- [GitHub Repository](https://github.com/elgorro/aiquila)
- [Report Issues](https://github.com/elgorro/aiquila/issues)
