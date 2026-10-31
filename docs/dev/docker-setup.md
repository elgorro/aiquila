# Docker Development Environment

Complete guide for setting up and using the AIquila Docker development environment.

## Overview

The Docker environment provides a complete, isolated development setup with:

- **Caddy 2** - Reverse proxy with automatic HTTPS (self-signed certs for dev)
- **PostgreSQL 18** - Production-grade database
- **Nextcloud 35** - Test instance with AIquila pre-installed from tarball
- **Redis 8** - Caching and performance
- **MCP Server** - Development container with hot reload (HTTP transport on port 3339)
- **MailHog** - Email testing and debugging
- **Adminer** - Database management UI (opt-in, `make up-tools`)

The AIquila app is installed automatically on first start from a pre-built tarball. No manual setup wizard or app installation is needed.

> **Standalone mode?** If you already have a Nextcloud instance and only need the MCP server, see [Standalone Docker Setup](../mcp/standalone-docker.md).

## Quick Start

### Prerequisites

- Docker Engine 20.10+
- Docker Compose 2.0+
- The Docker **buildx** plugin: images are built with BuildKit. Docker Desktop ships it;
  on Linux install `docker-buildx-plugin` from Docker's apt/dnf repository, or
  `docker-buildx` on Arch. `docker buildx version` should print a version.
- 4GB+ RAM available for Docker
- **Linux:** Ensure your user is in the `docker` group

### Setup

```bash
cd docker/installation

# Copy environment template and add your API key
cp .env.example .env
nano .env   # set CLAUDE_API_KEY

# Build the AIquila app tarball
make build-tarball

# Start all services
make up
```

On first run, Docker downloads images (~2-3 GB) and the entrypoint script automatically:
1. Waits for Nextcloud to initialize
2. Extracts and installs the AIquila app from the tarball
3. Creates the test user
4. Configures the AI provider API key
5. Enables debug mode

Watch progress:
```bash
make logs-follow
```

### Access Points

| Service | HTTPS (via Caddy) | Direct HTTP (localhost only) | Credentials |
|---------|-------------------|-------------|-------------|
| **Nextcloud** | https://localhost | http://localhost:8080 | admin / admin123 |
| **MCP Server** | https://localhost:3340/mcp | http://localhost:3339/mcp | — |
| **MailHog UI** | — | http://localhost:8025 | — |
| **Adminer** | — | http://localhost:8081 (after `make up-tools`) | See below |

Caddy uses self-signed certificates — expect a browser warning on first visit.

Only Caddy (443, 3340) listens on all interfaces. The direct ports are bound to
`127.0.0.1`, because Docker-published ports bypass host firewalls such as ufw — a
ufw rule does not protect a port Docker publishes on `0.0.0.0`. To reach the stack
from another machine, go through Caddy.

**Adminer:** System `PostgreSQL` · Server `db` · User `nextcloud` · Password from `.env` · Database `nextcloud`

## Development Workflow

### Updating the Nextcloud App

The Nextcloud app is installed from a tarball baked into the Docker image. To pick up PHP source changes:

```bash
make build-tarball   # rebuild tarball from source
make build           # rebuild Docker image
make up              # restart with new image
```

### MCP Server (Live Reload)

The MCP server source (`mcp-server/`) is volume-mounted into the container. Changes are picked up automatically via `tsx` hot reload — no rebuild needed.

```bash
make logs-mcp   # watch MCP server output
```

### Common Commands

```bash
make up            # start all services
make up-tools      # start all services plus Adminer
make down          # stop all services
make restart       # restart all services
make status        # show service status
make logs-follow   # follow all logs
make logs-nc       # Nextcloud logs
make logs-mcp      # MCP server logs
make shell         # shell in Nextcloud container
make shell-mcp     # shell in MCP container
make shell-db      # PostgreSQL shell
make test          # verify AIquila installation
make test-mcp      # run MCP server tests
make clean         # stop + remove containers and volumes, delete tarball
make reset         # full reset — rebuilds tarball + image from scratch
```

### occ Commands

```bash
make shell   # then inside the container:

php occ app:list
php occ app:enable aiquila
php occ user:list
php occ config:app:get aiquila
php occ aiquila:configure --api-key="sk-ant-..."
```

### Database Access

Via Adminer at http://localhost:8081 (start it with `make up-tools`), or:

```bash
make shell-db

# Example
\dt
SELECT * FROM oc_appconfig WHERE appid='aiquila';
\q
```

### Email Testing

All Nextcloud emails are caught by MailHog. View them at http://localhost:8025.

## Configuration

### Environment Variables

Edit `docker/installation/.env`:

```bash
POSTGRES_PASSWORD=nextcloud_secure_password
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=admin123
NEXTCLOUD_TRUSTED_DOMAINS=localhost localhost:8080
NEXTCLOUD_TEST_USER=testuser
NEXTCLOUD_TEST_PASSWORD=testpass123
CLAUDE_API_KEY=sk-ant-api03-xxxxx
```

After changing `.env`, restart: `make down && make up`

`PHP_MEMORY_LIMIT` and `PHP_UPLOAD_LIMIT` (both default `512M`) can also be set in
`.env`. `PHP_UPLOAD_LIMIT` sets `upload_max_filesize` and `post_max_size`; keep it in
step with `request_body max_size` in `docker/caddy/Caddyfile`, which rejects larger
bodies before they reach PHP.

### PHP settings

`docker/installation/php-hardening.ini` is baked into the image as
`conf.d/zz-aiquila-hardening.ini`, after the upstream `nextcloud.ini`. It turns off
`expose_php` and `display_errors` and lowers `max_input_nesting_level` to 32. Errors
still reach `data/nextcloud.log` (`loglevel` 0 in `custom.config.php`). The Hetzner
stacks apply the same settings; see
[Nextcloud image and PHP](../hetzner/advanced.md#nextcloud-image-and-php).

### Updating base images

The Nextcloud image is built `FROM nextcloud:35-apache`, a floating tag that upstream
rebuilds for PHP and Nextcloud security releases. `make up`, `make up-tools` and
`make reset` pass `--pull`, so each run picks up a newer base; `make build` is a full
`--pull --no-cache` rebuild. A plain `docker compose up --build` reuses the cached
base and does not. The [PHP Images workflow](ci-cd.md#php-images-workflow-php-imagesyml)
flags when a pinned image falls behind.

### Port Conflicts

Edit `docker/installation/docker-compose.yml` to change port mappings if needed.

## Testing MCP Server Integration

```bash
# Check MCP server is responding
curl -X POST http://localhost:3339/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'

# Test Nextcloud connectivity from inside MCP container
make shell-mcp
curl -u testuser:testpass123 http://nextcloud/remote.php/dav/files/testuser/
```

The MCP server uses `MCP_TRANSPORT=http` in Docker. For local development outside Docker, stdio is the default.

## Troubleshooting

### `buildx plugin is not installed`
`make build`, `make up`, `make up-tools` and `make reset` stop with this message when
`docker buildx version` fails. Without the plugin, Compose would fall back to Docker's
deprecated legacy builder, which ignores the `RUN --mount=type=cache` steps in the
Dockerfiles. Install buildx (see [Prerequisites](#prerequisites)) and re-run.
`make build-tarball` only runs a container, so it works without buildx.

### Services won't start
```bash
make status
make logs
make down && make up
```

### AIquila app not installed
Check the entrypoint logs — the tarball may be missing or corrupt:
```bash
make logs-nc
# Look for [INSTALL] and [VERIFY] lines

# Rebuild tarball and restart
make reset
```

### MCP server can't connect to Nextcloud
```bash
make shell-mcp
env | grep NEXTCLOUD
curl -u testuser:testpass123 http://nextcloud/status.php
```

### Permission errors
```bash
make shell
chown -R www-data:www-data /var/www/html
```

### Database connection errors
```bash
docker compose ps db
docker compose exec db pg_isready -U nextcloud
docker compose logs db
```

## Data Management

Data is stored in named Docker volumes that persist between restarts:

- `aiquila_postgres_data` — database
- `aiquila_redis_data` — cache
- `aiquila_nextcloud_data` — Nextcloud files

```bash
# Backup database
docker compose exec db pg_dump -U nextcloud nextcloud > backup.sql

# Restore database
cat backup.sql | docker compose exec -T db psql -U nextcloud nextcloud

# Full reset (DESTRUCTIVE — deletes all data)
make reset
```

> **Upgrading PostgreSQL major versions:** the stack ships `postgres:18`. A major upgrade
> won't read an older major's data volume automatically — dump before bumping the image and
> restore afterwards. See [Upgrading PostgreSQL to 18](../hetzner/advanced.md#upgrading-postgresql-to-18).
>
> Note that `postgres_data` is mounted at `/var/lib/postgresql` (not `/var/lib/postgresql/data`),
> because `postgres:18` stores its data in a major-version subdirectory and refuses to start
> against the older mount point. A volume from a stack that predates this cannot be reused in
> place — see [Where the data volume is mounted](../hetzner/advanced.md#where-the-data-volume-is-mounted).

## Architecture

```
┌───────────────────────────────────────────────────────────┐
│                      Docker Host                          │
│                                                           │
│  ┌────────────────┐                                       │
│  │  Caddy (HTTPS) │                                       │
│  │  :443  :3340   │                                       │
│  └───┬────────┬───┘                                       │
│      │        │                                           │
│      ▼        ▼                                           │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │  Nextcloud   │    │  MCP Server  │                     │
│  │  :8080       │    │  :3339       │                     │
│  │  + AIquila   │◄───│  (HTTP/SSE)  │                     │
│  │  (tarball)   │    │  (src mount) │                     │
│  └──────┬───────┘    └──────────────┘                     │
│         │                                                 │
│  ┌──────┴───────┐    ┌──────────────┐                     │
│  │  PostgreSQL  │    │    Redis     │                     │
│  └──────────────┘    └──────────────┘                     │
│                                                           │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │   MailHog    │    │   Adminer    │                     │
│  │   :8025      │    │   :8081 opt  │                     │
│  └──────────────┘    └──────────────┘                     │
└───────────────────────────────────────────────────────────┘
```

For more help, see the [Development Guide](development.md) or [GitHub Issues](https://github.com/elgorro/aiquila/issues).
