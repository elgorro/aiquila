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

- **[MCP Overview & Tools Reference](mcp/README.md)** — 372 tools across 46 categories
- **[Setup Guide](mcp/setup.md)** — installation and MCP client configuration
- **[OAuth 2.0](mcp/oauth.md)** — OAuth authentication for remote MCP clients
- **[Standalone Docker](mcp/standalone-docker.md)** — run MCP server in Docker (external Nextcloud)
- **[MCP-Connector](mcp/mcp-connector.md)** — use AIquila via the Anthropic Messages API

### Tool Documentation

| Category | Tools | Documentation |
|----------|-------|---------------|
| Files, status, apps, security, OCC, tags, search | 35 | [System Tools](mcp/tools/system-tools.md) |
| Calendar events | 6 | [Calendar](mcp/tools/apps/calendar.md) |
| Tasks (CalDAV) | 6 | [Tasks](mcp/tools/apps/tasks.md) |
| Contacts (CardDAV) | 6 | [Contacts](mcp/tools/apps/contacts.md) |
| Email | 10 | [Mail](mcp/tools/apps/mail.md) |
| Talk conversations & messages | 12 | [Talk](mcp/tools/apps/talk.md) |
| Kanban boards | 12 | [Deck](mcp/tools/apps/deck.md) |
| Photo albums & metadata | 11 | [Photos](mcp/tools/apps/photos.md) |
| Office documents | 3 | [Office](mcp/tools/apps/office.md) |
| Teams and their workspaces | 13 | [Teams](mcp/tools/apps/circles.md) |
| Tables (structured data) | 13 | [Tables](mcp/tools/apps/tables.md) |
| Projects, templates & prompt library | 11 | [Projects](mcp/tools/apps/projects.md) |
| Coworkers (scheduled AI jobs) | 15 | [Coworkers](mcp/tools/apps/cowork.md) |
| Folder workspaces (Text) | 5 | [Text](mcp/tools/apps/text.md) |
| Trash | 3 | [Trash](mcp/tools/apps/trash.md) |
| File versions | 2 | [Versions](mcp/tools/apps/versions.md) |
| Notifications | 4 | [Notifications](mcp/tools/apps/notifications.md) |
| User status | 5 | [User Status](mcp/tools/apps/user-status.md) |
| Out of office | 3 | [Out of Office](mcp/tools/apps/absence.md) |
| Bookmarks, folders, tags | 13 | [Bookmarks](mcp/tools/apps/bookmarks.md) |
| Maps, GPS, tracks, photos, contacts | 40 | [Maps](mcp/tools/apps/maps.md) |
| Notes | 5 | [Notes](mcp/tools/apps/notes.md) |
| News (RSS feeds) | 17 | [News](mcp/tools/apps/news.md) |
| Activity stream | 2 | [Activity](mcp/tools/apps/activity.md) |
| Polls | 21 | [Polls](mcp/tools/apps/polls.md) |
| Forms & surveys | 25 | [Forms](mcp/tools/apps/forms.md) |
| Fediverse (Social) | 26 | [Social](mcp/tools/apps/social.md) |
| Recipes | 6 | [Cookbook](mcp/tools/apps/cookbook.md) |
| NC AI tasks & image gen | 4 | [Assistant](mcp/tools/apps/assistant.md) |
| Translation | 1 | [Translate](mcp/tools/apps/translate.md) |
| File shares | 10 | [Shares](mcp/tools/apps/shares.md) |
| Social network share links | 1 | [Social Sharing](mcp/tools/apps/social-sharing.md) |
| Recommended files | 1 | [Recommendations](mcp/tools/apps/recommendations.md) |
| Announcements | 3 | [Announcements](mcp/tools/apps/announcements.md) |
| Self-service registration | 3 | [Registration](mcp/tools/apps/registration.md) |
| Terms of service | 5 | [Terms of Service](mcp/tools/apps/terms-of-service.md) |
| Password vault metadata | 3 | [Passman](mcp/tools/apps/passman.md) |
| AIquila config | 3 | [AIquila](mcp/tools/apps/aiquila.md) |
| Users & groups | 8 | [Users](mcp/tools/apps/users.md) / [Groups](mcp/tools/apps/groups.md) |

## Nextcloud App

- **[AIquila App Setup](installation/aiquila-setup.md)** — installation, configuration, and troubleshooting
- **[Internal API Guide](internal-api.md)** — integrate AIquila AI (ask/summarize/analyze) into your own Nextcloud apps
- **[Cowork Management API](cowork-api.md)** — register, steer and verify scheduled cowork jobs from your own Nextcloud app
- **[Monitoring](monitoring.md)** — OpenMetrics / Prometheus export for usage and task metrics
- **[Nextcloud compatibility](nextcloud-compatibility.md)** — supported Nextcloud versions and the policy behind the declared window

## Deployment

- **[Hetzner Cloud](hetzner/README.md)** — overview and quickstart
  - [Commands](hetzner/commands.md) | [Configuration](hetzner/configuration.md) | [Advanced](hetzner/advanced.md)
  - [Traefik](hetzner/traefik.md) | [CrowdSec](hetzner/crowdsec.md) | [Storage Box](hetzner/storage-box.md)
  - [CI Flow](hetzner/ci-flow.md) | [Integration Tests](hetzner/integration-test.md) | [Audit Log](hetzner/audit-log.md)
- **[Connectivity Guide](connectivity.md)** — network and connection troubleshooting

## Development

- **[Contributing](../CONTRIBUTING.md)** — how to propose a change: claim an issue first, branch and commit conventions, the checks CI runs
- **[Development Guide](dev/development.md)** — local setup, adding tools and endpoints, debugging
- **[Docker Setup](dev/docker-setup.md)** — development environment (`docker/installation/`)
- **[Best Practices](dev/best-practices.md)** — code quality and standards
- **[CI/CD](dev/ci-cd.md)** — continuous integration and deployment
- **[MCP Server Architecture](dev/mcp-server-architecture.md)** — technical design
- **[Provider settings schema](dev/provider-settings.md)** — how providers describe their own configuration, and how to add one
- **[Claude on cloud platforms](dev/cloud-providers.md)** — Claude through Amazon Bedrock, Google Cloud, Microsoft Foundry and Claude Platform on AWS
- **[Local model provider](dev/local-provider.md)** — self-hosted inference via Ollama, LM Studio or llama.cpp
- **[Hetzner Inference provider](dev/hetzner-provider.md)** — EU-hosted open-weight models via Hetzner's experimental API
- **[Mistral provider](dev/mistral-provider.md)** — the Mistral wire format, connectors and document handling
- **[Native MCP connector](dev/native-mcp-connector.md)** — letting Anthropic connect to MCP servers directly instead of looping in PHP
- **[Managed Agents](dev/managed-agents.md)** — why the agentic loop stays in PHP, and what would change that
- **[Context Agent](dev/context-agent.md)** — why AIquila serves the model behind Nextcloud's Context Agent rather than the agent task types
- **[Request metadata](dev/request-metadata.md)** — the hashed `metadata.user_id` sent to Anthropic, and how to resolve it
- **[Streaming responses](dev/streaming.md)** — how chat replies reach the browser as they are written, and what buffers them
- **[Prompt caching](dev/prompt-caching.md)** — where cache breakpoints are placed, and how to read the cache-hit numbers
- **[Refusals and fallback](dev/refusals.md)** — classifier refusals, discarding partial output, and Anthropic's server-side fallback
- **[Batch processing](dev/batch-processing.md)** — the Message Batches transport, the bulk cowork tasks, and the extended-output switch
- **[OpenAPI](dev/openapi.md)** — OpenAPI documentation
- **MCP Development** — [Architecture](mcp/development/architecture.md) | [Adding Tools](mcp/development/adding-tools.md) | [Adding Apps](mcp/development/adding-apps.md)

## Documentation Structure

```
docs/
├── README.md                        # This file — navigation hub
├── installation.md                  # Getting started guide
├── nextcloud-compatibility.md       # Supported Nextcloud versions & policy
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
│   │       ├── circles.md           # Teams and workspaces
│   │       ├── contacts.md          # Contacts via CardDAV
│   │       ├── cookbook.md           # Recipes (schema.org)
│   │       ├── groups.md            # Group management
│   │       ├── mail.md              # Email accounts & messages
│   │       ├── maps.md              # Maps, GPS, tracks, photos
│   │       ├── notes.md             # Markdown notes
│   │       ├── office.md            # Document conversion and templates
│   │       ├── shares.md            # File sharing
│   │       ├── tables.md            # Tables, rows, relations
│   │       ├── talk.md              # Talk conversations & messages
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
│   ├── provider-settings.md         # Provider settings schema
│   ├── cloud-providers.md           # Claude on Bedrock, Google Cloud, Foundry, AWS
│   ├── local-provider.md            # Ollama / LM Studio / llama.cpp
│   ├── hetzner-provider.md          # Hetzner Inference provider
│   ├── mistral-provider.md          # Mistral provider
│   ├── native-mcp-connector.md      # Anthropic-side MCP tool execution
│   ├── managed-agents.md            # Hosted agent loop: evaluation and position
│   ├── context-agent.md             # Context Agent task types: position
│   ├── request-metadata.md          # Hashed metadata.user_id
│   ├── streaming.md                 # Server-Sent Events path and buffering
│   ├── prompt-caching.md            # Cache breakpoint placement
│   ├── batch-processing.md          # Message Batches & bulk cowork tasks
│   └── openapi.md                   # OpenAPI documentation
```

## Resources

- [GitHub Repository](https://github.com/elgorro/aiquila)
- [Report Issues](https://github.com/elgorro/aiquila/issues)
