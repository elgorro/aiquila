# AIquila

Nextcloud + Claude AI Integration

[![Nextcloud App Release](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml)
[![MCP Server Release](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml)

## Overview

AIquila connects your Nextcloud instance with Claude AI in two ways:

- **MCP Server** — lets Claude (Desktop/Mobile) manage your Nextcloud files, tasks, notes, and recipes via chat or voice
- **Nextcloud App** — adds Claude AI actions directly inside Nextcloud (summarize, analyze, generate content)

## Quick Start

### Docker (Recommended)

```bash
cd docker/installation
cp .env.example .env   # add your CLAUDE_API_KEY
make build-tarball     # build the app package
make up                # start all services
```

Access: http://localhost:8080 · MailHog: http://localhost:8025 · MCP: http://localhost:3339

### MCP Server Only

Use the [standalone Docker setup](docker/standalone/) to connect the MCP server to an existing Nextcloud instance.

## Documentation

- [Installation Guide](docs/installation.md)
- [Docker Dev Setup](docs/dev/docker-setup.md)
- [MCP Server](docs/mcp/README.md)
- [Connectivity & Troubleshooting](docs/connectivity.md)
- [Full Docs](docs/README.md)

## Acknowledgements

[Nextcloud](https://nextcloud.com) · [Claude](https://anthropic.com) · [MCP](https://modelcontextprotocol.io) · [TypeScript](https://www.typescriptlang.org) · [JavaScript](https://developer.mozilla.org/en-US/docs/Web/JavaScript) · [PHP](https://www.php.net) · [Vue](https://vuejs.org) · [Node.js](https://nodejs.org) · [npm](https://www.npmjs.com) · [Vite](https://vitejs.dev) · [Docker](https://www.docker.com) · [Caddy](https://caddyserver.com) · [PostgreSQL](https://www.postgresql.org) · [Redis](https://redis.io) · [MailHog](https://github.com/mailhog/MailHog) · [Adminer](https://www.adminer.org) · [Git](https://git-scm.com) · [GitHub](https://github.com) · [Markdown](https://commonmark.org) · [SVG](https://www.w3.org/Graphics/SVG/) · [Make](https://www.gnu.org/software/make/) · [Bash](https://www.gnu.org/software/bash/) · [tar](https://www.gnu.org/software/tar/)

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)
