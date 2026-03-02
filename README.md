# AIquila

Nextcloud + Claude AI Integration

[![Nextcloud App Release](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml)
[![MCP Server Release](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml)

## What is AIquila?

AIquila bridges your self-hosted Nextcloud instance with Claude AI. Instead of keeping your files, notes, tasks, and recipes locked inside Nextcloud — or copying them manually into a chat window — AIquila lets Claude read and write your Nextcloud data directly. You stay in control of your data on your own server; Claude gains the context it needs to actually help.

## How it works

AIquila has three components that can be used independently or together:

**MCP Server** — A [Model Context Protocol](https://modelcontextprotocol.io) server that gives Claude (Desktop or Mobile) secure access to your Nextcloud. Through natural conversation, Claude can browse and manage files, read and create notes, handle tasks and bookmarks, and query recipes — all stored in your Nextcloud.

**Nextcloud App** — A native Nextcloud application that surfaces Claude AI actions directly inside the Nextcloud UI. Summarize a document, analyze a spreadsheet, or generate content without leaving your Nextcloud.

**Hetzner Deployment** — A single-command provisioning tool (`aiquila-hetzner`) that stands up a production-ready AIquila server on Hetzner Cloud, complete with Traefik reverse proxy, CrowdSec intrusion prevention, TLS, and optional monitoring.

## Getting Started

- [Installation Guide](docs/installation.md) — set up AIquila for the first time
- [Full Documentation](docs/README.md) — architecture, configuration, and advanced topics

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)

See [ACKNOWLEDGMENTS.md](ACKNOWLEDGMENTS.md) for the open-source projects and services AIquila is built on.
