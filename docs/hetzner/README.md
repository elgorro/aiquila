# AIquila — Hetzner Deployment

`aiquila-hetzner` is a single-binary CLI written in Go that provisions a
production-ready AIquila stack on Hetzner Cloud with one command.

---

## What it sets up

- Hetzner Cloud server (configurable type, image, and location)
- Ed25519 SSH key pair (auto-generated or supplied)
- Firewall (TCP 22/80/443 + UDP 443, optional CIDR restriction on SSH)
- Optional Hetzner Cloud Volume (plain ext4 or LUKS-encrypted)
- cloud-init: Docker + Docker Compose via distro-native package manager
- Optional swap file
- Optional extra OS packages installed via cloud-init
- Traefik reverse proxy with automatic TLS (Let's Encrypt)
- CrowdSec intrusion prevention
- `.env` generated from your credentials
- Optional Prometheus + Grafana monitoring stack (MCP stack only)
- Optional Hetzner DNS A/AAAA record creation
- Optional private network attachment

---

## Stacks

Three deployment configurations are supported via `--stack`:

| Stack | Flag | Description |
|-------|------|-------------|
| `mcp` | `--stack mcp` | MCP server only; Nextcloud is hosted externally (default) |
| `nextcloud` | `--stack nextcloud` | Nextcloud + AIquila app only |
| `full` | `--stack full` | Nextcloud + MCP on a single server |

---

## Prerequisites

- Hetzner Cloud account + API token (`$HCLOUD_TOKEN`)
- (Optional) Hetzner DNS token (`$HETZNER_DNS_TOKEN`) for DNS automation
- For `--stack mcp`: existing Nextcloud instance with an app password
- For `--stack nextcloud` / `full`: domain name for the Nextcloud server
- Domain that points to (or will be pointed to) the server IP
- Go 1.22+ to build from source

---

## Installation

```bash
cd hetzner
go build -o aiquila-hetzner ./cmd/aiquila-hetzner/
sudo mv aiquila-hetzner /usr/local/bin/
```

---

## Quick Start

```bash
export HCLOUD_TOKEN=your_token

# MCP-only (external Nextcloud):
aiquila-hetzner create \
  --mcp-domain mcp.example.com \
  --nc-url https://nextcloud.example.com \
  --nc-user admin \
  --nc-password your-app-password

# Nextcloud-only:
aiquila-hetzner create \
  --stack nextcloud \
  --nc-domain nc.example.com \
  --nc-admin-password your-admin-password

# Full stack (NC + MCP on one server):
aiquila-hetzner create \
  --stack full \
  --mcp-domain mcp.example.com \
  --nc-domain nc.example.com \
  --nc-admin-password your-admin-password
```

Use `--dry-run` to preview what would be created without making any API calls.

The CLI prints a cost warning and asks for confirmation before the first
billable API call. Use `--noconfirm` to skip the prompt in CI/CD pipelines.

---

## Documentation

| Topic | File |
|-------|------|
| Command reference | [commands.md](commands.md) |
| Configuration (profiles, env vars, DeployConfig) | [configuration.md](configuration.md) |
| Advanced topics & .env management | [advanced.md](advanced.md) |
| Integration testing | [integration-test.md](integration-test.md) |
| CI/CD flow | [ci-flow.md](ci-flow.md) |
| Audit log | [audit-log.md](audit-log.md) |
| Traefik (reverse proxy & TLS) | [traefik.md](traefik.md) |
| CrowdSec (intrusion prevention) | [crowdsec.md](crowdsec.md) |
| Storage Box (alpha) | [storage-box.md](storage-box.md) |
