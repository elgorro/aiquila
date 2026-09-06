[![MseeP.ai Security Assessment Badge](https://mseep.net/pr/elgorro-aiquila-badge.png)](https://mseep.ai/app/elgorro-aiquila)

# AIquila

Nextcloud + Claude AI Integration

[![Nextcloud App Release](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml)
[![MCP Server Release](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml)

## What is AIquila?

AIquila bridges your self-hosted Nextcloud instance with Claude AI. Instead of keeping your files, notes, tasks, and recipes locked inside Nextcloud — or copying them manually into a chat window — AIquila lets Claude read and write your Nextcloud data directly. You stay in control of your data on your own server; Claude gains the context it needs to actually help.

## How it works

AIquila has three components that can be used independently or together:

**MCP Server** — A [Model Context Protocol](https://modelcontextprotocol.io) server that gives any MCP-compatible AI assistant secure access to your Nextcloud. Browse and manage files, read and create notes, handle tasks and bookmarks, manage projects, and query recipes — all stored in your Nextcloud.

**Nextcloud App** — A native Nextcloud application that surfaces Claude AI actions directly inside the Nextcloud UI. Summarize a document, analyze a spreadsheet, or generate content without leaving your Nextcloud.

**Hetzner Deployment** — A single-command provisioning tool (`aiquila-hetzner`) that stands up a production-ready AIquila server on Hetzner Cloud, complete with Traefik reverse proxy, CrowdSec intrusion prevention, TLS, and optional monitoring.

## Getting Started

Pick the path that fits your setup:

| Path | What you get | Guide |
|------|-------------|-------|
| `npx aiquila-mcp` | Claude Desktop/Code + Nextcloud | [Quick start](docs/installation.md#path-1-claude-desktop--claude-code-simplest) |
| Docker + OAuth | Claude.ai + Nextcloud | [Quick start](docs/installation.md#path-2-claudeai-remote-mcp) |
| Nextcloud App | AI inside Nextcloud UI | [Quick start](docs/installation.md#path-3-nextcloud-app) |
| Hetzner Cloud | Full production deploy | [Quick start](docs/installation.md#path-4-self-hosted-on-hetzner-cloud) |
| Claude Mobile + Voice | Phone + Nextcloud hands-free | [Quick start](docs/installation.md#path-5-claude-mobile-app-voice) |

- [Getting Started Guide](docs/installation.md) — all five paths with step-by-step instructions
- [Full Documentation](docs/README.md) — architecture, configuration, and advanced topics

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)

See [ACKNOWLEDGMENTS.md](ACKNOWLEDGMENTS.md) for the open-source projects and services AIquila is built on.
