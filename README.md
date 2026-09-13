# AIquila

Private AI for your Nextcloud — your data, your server, your choice of AI provider

[![Nextcloud App Release](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/nc-release.yml)
[![MCP Server Release](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml/badge.svg?branch=main)](https://github.com/elgorro/aiquila/actions/workflows/mcp-release.yml)

## What is AIquila?

AIquila brings AI to your self-hosted Nextcloud. Instead of keeping your files, notes, tasks and recipes locked inside Nextcloud — or copying them by hand into a chat window — AIquila lets an AI assistant read and write your Nextcloud data directly. Your data stays on your own server, and you decide which AI provider sees it: a hosted API, or a model running on your own hardware that sends nothing to anyone.

## How it works

AIquila has three components that can be used independently or together:

**MCP Server** — A [Model Context Protocol](https://modelcontextprotocol.io) server that gives any MCP-compatible AI assistant secure access to your Nextcloud. 311 tools across 43 categories: browse and manage files, read and create notes, handle tasks and bookmarks, manage projects, query recipes, and more. It has no model of its own — it exposes Nextcloud to whichever MCP client you connect.

**Nextcloud App** — A native Nextcloud application that brings AI directly into the Nextcloud UI. Chat about your documents, summarise and rewrite text, run Coworkers (saved, repeatable AI jobs), and scope a conversation to a Project so the assistant only sees the folders you choose. Admins pick which providers are available, and may restrict them per user or group.

**Hetzner Deployment** — A single-command provisioning tool (`aiquila-hetzner`) that stands up a production-ready AIquila server on Hetzner Cloud, complete with Traefik reverse proxy, CrowdSec intrusion prevention, TLS, and optional monitoring.

### AI providers

The Nextcloud app supports five providers for chat. Each user can pick a provider and model per conversation, within what the admin allows.

| Provider | Hosting | Notes |
|---|---|---|
| **Claude (Anthropic)** | Anthropic API | The default, and the most deeply integrated: vision, thinking budgets, native MCP connector, and PDF handling with citations. |
| **Hetzner Inference** | Hetzner (German provider) | OpenAI-compatible. Currently an [experimental Hetzner service](https://docs.hetzner.com/general/company-and-policy/experiments/inference/), free while experimental; Hetzner states it does not store request and response content. Tokens from [experiments.hetzner.com](https://experiments.hetzner.com/inference). |
| **Mistral** | Mistral API (French provider) | Also supports the native MCP connector. Mistral offers EU-only processing through its regional inference endpoints. |
| **Local model** | Your own hardware | Ollama, LM Studio or llama.cpp. Nothing leaves your infrastructure. Supports bearer/basic/header auth and mTLS. |
| **DeepSeek** | DeepSeek API | OpenAI-compatible. No native MCP connector. |

Provider choice applies to chat, the per-conversation model picker and the Nextcloud
Assistant / TaskProcessing integrations, which all run on the provider the user has
selected. Assistant actions that send images ("Describe this image", "Analyze images")
need a vision-capable provider, and report which of the available ones qualify when
the selected provider is not.

## Getting Started

Pick the path that fits your setup:

| Path | What you get | Guide |
|------|-------------|-------|
| `npx aiquila-mcp` | Local MCP client + Nextcloud | [Quick start](docs/installation.md#path-1-local-mcp-client-simplest) |
| Docker + OAuth | Remote MCP client + Nextcloud | [Quick start](docs/installation.md#path-2-remote-mcp-client-docker--oauth) |
| Nextcloud App | AI inside the Nextcloud UI | [Quick start](docs/installation.md#path-3-nextcloud-app) |
| Hetzner Cloud | Full production deploy | [Quick start](docs/installation.md#path-4-self-hosted-on-hetzner-cloud) |
| Mobile + Voice | Phone + Nextcloud hands-free | [Quick start](docs/installation.md#path-5-mobile-mcp-client-voice) |

- [Getting Started Guide](docs/installation.md) — all five paths with step-by-step instructions
- [Full Documentation](docs/README.md) — architecture, configuration, and advanced topics
- [Nextcloud compatibility](docs/nextcloud-compatibility.md) — which Nextcloud versions are supported, and what "supported" means

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first —
most importantly, **open or comment on an issue before writing code**, so we can
confirm the approach before you invest the effort.

The guide covers branch and commit conventions, the checks CI runs per component,
and what to expect from CI on a pull request from a fork.

## License

AGPL-3.0 (Nextcloud App) / MIT (MCP Server)

See [ACKNOWLEDGMENTS.md](ACKNOWLEDGMENTS.md) for the open-source projects and services AIquila is built on.
