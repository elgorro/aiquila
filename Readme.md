# AIquila

Nextcloud + Claude AI Integration

## Overview

AIquila connects your Nextcloud instance with Claude AI, enabling two-way integration:

1. **MCP Server** - Use Claude (mobile/desktop) to manage your Nextcloud files, tasks, notes, and recipes via voice or chat
2. **Nextcloud App** - Use Claude AI directly within Nextcloud to analyze files, summarize documents, and generate content

## Features

### MCP Server (Claude → Nextcloud)
- Create, read, edit, delete files and folders
- Create tasks in Nextcloud Tasks app
- Add notes to Notes folder
- Save recipes to Recipes folder
- Voice control via Claude mobile app

### Nextcloud App (Nextcloud → Claude)
- "Ask Claude" action in Files context menu
- Document summarization
- Content generation and editing
- Admin and per-user API key configuration
- Configurable model, token limits, and timeouts
- Rate limiting and input validation

## Quick Start

### Docker Development Environment (Recommended)

The easiest way to get started with development:

```bash
cd docker
cp .env.example .env
make up
```

This starts a complete environment with:
- Nextcloud 31 (requires manual initialization)
- PostgreSQL 16 database
- Redis 7 caching
- MCP server with hot reload
- MailHog for email testing
- Adminer for database management

After services start:
1. Open http://localhost:8080 and complete Nextcloud setup
2. Copy AIquila app into container (see [Docker Setup Guide](docs/docker-setup.md))
3. Configure Claude API key in Settings

Access points:
- **Nextcloud**: http://localhost:8080
- **MailHog**: http://localhost:8025
- **Adminer**: http://localhost:8081

See [Docker Setup Guide](docs/docker-setup.md) for complete step-by-step instructions.

### MCP Server Setup (Manual)
```bash
cd mcp-server
npm install
npm run build
```

Add to Claude Desktop config (`~/.config/claude/claude_desktop_config.json`):
```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://your-nextcloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

### Nextcloud App Setup
1. Copy `nextcloud-app` to your Nextcloud's `apps/aiquila` directory
2. Enable the app in Nextcloud admin
3. Configure API key in Settings → AIquila

## Documentation

- [Docker Setup Guide](docs/docker-setup.md) - **Start here for development**
- [Installation Guide](docs/installation.md)
- [Development Guide](docs/development.md)
- [Configuration & Connectivity](docs/connectivity.md)
- [CI/CD Workflows](docs/ci-cd.md)
- [Best Practices](docs/best-practices.md)

## Roadmap

See [ROADMAP.md](ROADMAP.md) for planned features and development phases.

## Requirements

### For Docker Development
- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB+ RAM for Docker

### For Manual Setup
- Nextcloud Hub 10 (31.x)
- Node.js 18+ (for MCP server)
- PostgreSQL 15+ (recommended) or MySQL/MariaDB
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)
