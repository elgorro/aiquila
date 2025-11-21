# NextClaude

Nextcloud + Claude AI Integration

## Overview

NextClaude connects your Nextcloud instance with Claude AI, enabling two-way integration:

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

## Quick Start

### MCP Server Setup
```bash
cd mcp-server
npm install
npm run build
```

Add to Claude Desktop config (`~/.config/claude/claude_desktop_config.json`):
```json
{
  "mcpServers": {
    "nextclaude": {
      "command": "node",
      "args": ["/path/to/nextclaude/mcp-server/dist/index.js"],
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
1. Copy `nextcloud-app` to your Nextcloud's `apps/nextclaude` directory
2. Enable the app in Nextcloud admin
3. Configure API key in Settings → NextClaude

## Documentation

- [Installation Guide](docs/installation.md)
- [Development Guide](docs/development.md)
- [Configuration & Connectivity](docs/connectivity.md)
- [CI/CD Workflows](docs/ci-cd.md)
- [Best Practices](docs/best-practices.md)

## Requirements

- Nextcloud Hub 10 (31.x)
- Node.js 18+ (for MCP server)
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)
