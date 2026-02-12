# Docker Development Environment

Complete guide for setting up and using the AIquila Docker development environment.

## Overview

The Docker environment provides a complete, isolated development setup with:

- **Caddy 2** - Reverse proxy with automatic HTTPS (self-signed certs for dev)
- **PostgreSQL 16** - Production-grade database
- **Nextcloud 32** - Test instance for development
- **Redis 7** - Caching and performance
- **MCP Server** - Development container with hot reload (HTTP transport on port 3339)
- **MailHog** - Email testing and debugging
- **Adminer** - Database management UI

**Note:** This setup requires manual configuration after first start. The AIquila app needs to be installed after Nextcloud initializes.

> **Looking for standalone mode?** If you already have a Nextcloud instance and only need the MCP server, see [Standalone Docker Setup](../mcp/standalone-docker.md).

## Quick Start

### Prerequisites

- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB+ RAM available for Docker
- **Linux users:** Ensure your user is in the `docker` group:
  ```bash
  sudo usermod -aG docker $USER
  newgrp docker  # Or log out and back in
  ```

### Initial Setup

```bash
# Navigate to docker directory
cd docker

# Copy environment template
cp .env.example .env

# (Optional) Edit environment variables - defaults work fine
nano .env

# Start all services
make up
```

### First Time Setup

**Step 1: Start Docker Services**

The first time you run `make up`, Docker will:

1. Download all required images (~2-3 GB)
2. Create PostgreSQL database
3. Create Redis cache
4. Start Nextcloud (takes 2-3 minutes to initialize)
5. Start MailHog and Adminer

Watch progress with:
```bash
make logs
```

**Step 2: Initialize Nextcloud**

1. Open http://localhost:8080 in your browser
2. Complete the Nextcloud installation wizard:
   - Create admin account (default: admin/admin123)
   - Database is already configured (PostgreSQL)
   - Click "Install"

**Step 3: Install AIquila App**

After Nextcloud is installed, enable the AIquila app:

**Option A: Via Web Interface**
1. Go to **Apps** in Nextcloud
2. Enable "AIquila" from the list

**Option B: Manual Copy**
```bash
# Copy AIquila app into Nextcloud container
docker cp ../nextcloud-app/. aiquila-nextcloud:/var/www/html/custom_apps/aiquila/

# Set permissions
docker exec -u root aiquila-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/aiquila

# Enable the app
docker exec -u www-data aiquila-nextcloud php occ app:enable aiquila
```

**Step 4: Install Anthropic PHP SDK (Optional)**

AIquila can use the official Anthropic PHP SDK for better error handling and features.

**Note:** Composer is pre-installed in the Nextcloud container via our custom Dockerfile.

```bash
# Install SDK dependencies (composer is already available)
sudo docker exec -u www-data aiquila-nextcloud bash -c 'cd /var/www/html/custom_apps/aiquila && composer install --no-dev'

# Verify vendor directory synced to host (automatic via volume mount)
ls -la nextcloud-app/vendor

# Test SDK installation
sudo docker exec -u www-data aiquila-nextcloud php /var/www/html/occ aiquila:test-sdk
```

The custom Nextcloud image includes:
- **Composer 2.x** - Pre-installed and ready to use
- **Git & Unzip** - Required for Composer dependencies
- **Volume mount** - Automatic sync between container and host

See [SDK-MIGRATION.md](../nextcloud-app/SDK-MIGRATION.md) for more details.

**Step 5: Configure AIquila**

1. Go to **Settings → Administration → AIquila**
2. Enter your Claude API key
3. (Optional) Configure model, max tokens, timeout

### Access Points

Once all services are running and configured:

| Service | HTTPS (via Caddy) | Direct HTTP | Credentials |
|---------|-------------------|-------------|-------------|
| **Nextcloud** | https://localhost | http://localhost:8080 | Set during installation |
| **MCP Server** | https://localhost:3340/mcp | http://localhost:3339/mcp | (no auth) |
| **MailHog UI** | - | http://localhost:8025 | (no auth) |
| **Adminer** | - | http://localhost:8081 | See below |

Caddy uses self-signed certificates in development. Your browser will show a certificate warning on first visit — this is expected.

**Adminer credentials:**
- System: `PostgreSQL`
- Server: `db`
- Username: `nextcloud`
- Password: `nextcloud_secure_password` (or your .env value)
- Database: `nextcloud`

**MCP Server:**
- Runs with Streamable HTTP transport (SSE-capable) on port 3339
- HTTPS via Caddy: `https://localhost:3340/mcp`
- Direct HTTP: `http://localhost:3339/mcp`
- Within Docker network: `http://aiquila-mcp:3339/mcp`
- Connects to Nextcloud internally at `http://nextcloud`
- Logs: `make logs-mcp`

## Development Workflow

### Live Code Reloading

**Nextcloud App:**
After copying the app into the container (see Step 3 above), you can:
- Make changes to files in `nextcloud-app/`
- Manually copy updates: `docker cp nextcloud-app/. aiquila-nextcloud:/var/www/html/custom_apps/aiquila/`
- Or use a bind mount (advanced - see Troubleshooting section)
- Refresh browser to see changes

**MCP Server:**
- Code in `mcp-server/` is automatically mounted into the container
- Uses `tsx` for hot reload
- Changes automatically restart the server
- Watch logs with: `make logs-mcp`

### Common Tasks

#### View Logs

```bash
# All services
make logs

# Specific service
make logs-nc      # Nextcloud
make logs-mcp     # MCP server
docker-compose logs -f db       # Database
docker-compose logs -f redis    # Redis
```

#### Shell Access

```bash
# Nextcloud container
make shell-nc

# MCP server container
make shell-mcp

# PostgreSQL shell
make shell-db

# Redis CLI
docker-compose exec redis redis-cli
```

#### Service Management

```bash
# Start all services
make up

# Stop all services
make down

# Restart all services
make restart

# View service status
make status

# Rebuild containers
make build
```

### Running Tests

#### Nextcloud App Tests

```bash
# Run PHPUnit tests
make test-nc

# Or manually in container
make shell-nc
cd /var/www/html/custom_apps/aiquila
phpunit
```

#### MCP Server Tests

```bash
# Run all tests
make test-mcp

# Or manually
make shell-mcp
npm test

# Watch mode
npm run test:watch
```

### Using occ Commands

The Nextcloud CLI tool `occ` is available for administration:

```bash
make shell-nc

# Example commands
php occ app:list                    # List all apps
php occ app:enable aiquila          # Enable AIquila
php occ user:list                   # List users

# AIquila configuration (recommended method)
php occ aiquila:configure --show                           # Show current configuration
php occ aiquila:configure --api-key "sk-ant-..."           # Set API key
php occ aiquila:configure --model "claude-sonnet-4-20250514"  # Set model
php occ aiquila:configure --max-tokens 8192                # Set max tokens (1-100000)
php occ aiquila:configure --timeout 60                     # Set timeout in seconds (10-1800)

# Set multiple values at once
php occ aiquila:configure --model "claude-3-7-sonnet-20250219" --max-tokens 16384 --timeout 120

# AIquila testing commands
php occ aiquila:test                # Test HTTP client implementation
php occ aiquila:test-sdk            # Test Anthropic SDK implementation
php occ aiquila:test-sdk --stream   # Test streaming mode
php occ aiquila:test-sdk --prompt "Custom test prompt"

# Alternative: Direct config manipulation (not recommended)
php occ config:app:get aiquila      # Get AIquila config
php occ config:app:set aiquila api_key --value="sk-ant-..."  # Set API key
```

### Database Access

#### Via Adminer (Web UI)

Visit http://localhost:8081 and use the credentials above.

#### Via Command Line

```bash
# PostgreSQL shell
make shell-db

# Or explicit connection
docker-compose exec db psql -U nextcloud -d nextcloud

# Example queries
\dt                                 # List tables
SELECT * FROM oc_appconfig WHERE appid='aiquila';
\q                                  # Quit
```

### Email Testing with MailHog

All emails sent by Nextcloud are caught by MailHog:

1. Trigger an email in Nextcloud (e.g., share a file, password reset)
2. Visit http://localhost:8025
3. View the email in the web interface

No external SMTP server needed!

## Configuration

### Environment Variables

Edit `docker/.env` to customize:

```bash
# Database
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud
POSTGRES_PASSWORD=nextcloud_secure_password

# Nextcloud Admin
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=admin123
NEXTCLOUD_TRUSTED_DOMAINS=localhost:8080

# Test User (for MCP server)
NEXTCLOUD_TEST_USER=testuser
NEXTCLOUD_TEST_PASSWORD=testpass123

# Claude API
CLAUDE_API_KEY=sk-ant-api03-xxxxx
```

After changing `.env`, restart:

```bash
make down
make up
```

### Redis and Database Configuration

Redis and PostgreSQL are pre-configured via environment variables in `docker-compose.yml`:

- Redis is automatically configured as Nextcloud's session handler
- PostgreSQL connection is set via `POSTGRES_*` environment variables
- No manual configuration needed

To verify Redis is working:
```bash
docker exec aiquila-nextcloud php occ config:list | grep redis
```

### Port Conflicts

If ports 443, 3339, 8080, 8025, or 8081 are already in use, edit `docker/docker-compose.yml`:

```yaml
services:
  nextcloud:
    ports:
      - "9080:80"  # Change 8080 -> 9080

  mailhog:
    ports:
      - "9025:8025"  # Change 8025 -> 9025
```

## Testing MCP Server Integration

The MCP server runs with Streamable HTTP transport in Docker, exposing an MCP endpoint on port 3339.

```bash
# Check MCP server logs
make logs-mcp

# Test the MCP endpoint is responding (from host)
curl -X POST http://localhost:3339/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'

# Test Nextcloud connectivity from inside MCP container
make shell-mcp
curl -u testuser:testpass123 http://nextcloud/remote.php/dav/files/testuser/
```

### Transport Modes

The MCP server supports two transport modes, controlled by the `MCP_TRANSPORT` environment variable:

| Mode | Value | Use Case |
|------|-------|----------|
| **stdio** (default) | `MCP_TRANSPORT=stdio` | Claude Desktop, local MCP clients |
| **HTTP** | `MCP_TRANSPORT=http` | Docker, network deployment, remote clients |

In Docker, `MCP_TRANSPORT=http` is set automatically in `docker-compose.yml`. For local development outside Docker, stdio is the default (no env var needed).

## Troubleshooting

### Services Won't Start

```bash
# Check status
make status

# View logs for errors
make logs

# Common fixes
make down
make up
```

### Nextcloud Shows Setup Wizard

This means the database wasn't initialized. Reset and try again:

```bash
make reset
make up
```

### AIquila App Not Found

The app needs to be manually copied into the container after Nextcloud initializes:

```bash
# Copy app files
docker cp nextcloud-app/. aiquila-nextcloud:/var/www/html/custom_apps/aiquila/

# Fix permissions
docker exec -u root aiquila-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/aiquila

# Enable the app
docker exec -u www-data aiquila-nextcloud php occ app:enable aiquila

# Verify
docker exec -u www-data aiquila-nextcloud php occ app:list | grep aiquila
```

### Live Development with App Bind Mount (Recommended)

The docker-compose.yml now includes a volume mount for instant code syncing:

```yaml
volumes:
  - nextcloud_data:/var/www/html
  - ../nextcloud-app:/var/www/html/custom_apps/aiquila:cached
```

This means:
- **All file changes** in `nextcloud-app/` are instantly available in Docker
- **No manual copying** with `docker cp` needed
- **Composer dependencies** (vendor/) are automatically synced

**One-time setup after enabling app:**

After you install Composer and the Anthropic SDK in the container, copy the vendor directory to your host:

```bash
# Copy vendor directory from Docker to host (one-time only)
sudo docker cp aiquila-nextcloud:/var/www/html/custom_apps/aiquila/vendor /home/sirgorro/Dev/aiquila/nextcloud-app/
sudo chown -R $(whoami):$(whoami) /home/sirgorro/Dev/aiquila/nextcloud-app/vendor

# Copy composer.lock for version control
sudo docker cp aiquila-nextcloud:/var/www/html/custom_apps/aiquila/composer.lock /home/sirgorro/Dev/aiquila/nextcloud-app/
sudo chown $(whoami):$(whoami) /home/sirgorro/Dev/aiquila/nextcloud-app/composer.lock
```

After this one-time setup, the volume mount handles all file syncing automatically!

### Permission Errors

```bash
# Fix Nextcloud permissions
make shell-nc
chown -R www-data:www-data /var/www/html
```

### MCP Server Can't Connect

Check environment variables:

```bash
make shell-mcp
env | grep NEXTCLOUD

# Should show:
# NEXTCLOUD_URL=http://nextcloud
# NEXTCLOUD_USER=testuser
# NEXTCLOUD_PASSWORD=testpass123
```

### Database Connection Errors

```bash
# Check database is running
docker-compose ps db

# Check health
docker-compose exec db pg_isready -U nextcloud

# View database logs
docker-compose logs db
```

### Port Already in Use

Error: "Bind for 0.0.0.0:8080 failed: port is already allocated"

Solution: Change ports in `docker-compose.yml` (see Port Conflicts above)

## Data Management

### Persistent Data

Data is stored in Docker volumes:

- `aiquila_postgres_data` - Database
- `aiquila_redis_data` - Cache
- `aiquila_nextcloud_data` - Nextcloud files

These persist between `make down` and `make up`.

### Backup Data

```bash
# Backup database
docker-compose exec db pg_dump -U nextcloud nextcloud > backup.sql

# Backup Nextcloud files
docker cp aiquila-nextcloud:/var/www/html/data ./nextcloud-data-backup
```

### Restore Data

```bash
# Restore database
cat backup.sql | docker-compose exec -T db psql -U nextcloud nextcloud

# Restore files
docker cp ./nextcloud-data-backup/. aiquila-nextcloud:/var/www/html/data/
```

### Full Reset (Destructive)

**WARNING:** This deletes ALL data including database, files, and configuration!

```bash
make reset
```

You'll be prompted to confirm. After reset, run `make up` to start fresh.

### Clean Restart (Keeps Volumes)

```bash
make clean
make up
```

This removes containers but preserves data volumes.

## Advanced Usage

### Custom Nextcloud Entrypoint

The setup script at `docker/nextcloud/entrypoint.sh`:

- Waits for Nextcloud to initialize
- Enables AIquila app
- Creates test user
- Configures Claude API key
- Sets up MailHog integration

Modify it to add your own setup steps.

### Building Custom Images

```bash
# Build MCP server image
docker-compose build mcp-server

# Build all images
make build
```

### Debug Mode

Enable more verbose logging:

```bash
# Edit .env
NEXTCLOUD_DEBUG=true

# Restart
make restart

# Check logs
make logs-nc
```

### Networking

All services communicate on the `aiquila-network` bridge network:

- Service-to-service communication uses service names (e.g., `http://nextcloud`)
- External access uses published ports (e.g., `http://localhost:8080`)

View network details:

```bash
docker network inspect aiquila-network
```

### Production Considerations

**Do NOT use this setup in production!**

For production:
- Use secure passwords
- Enable HTTPS/SSL
- Disable debug mode
- Use proper reverse proxy (nginx/Traefik)
- Configure firewall rules
- Set up backups
- Use environment-specific secrets management

## Architecture Diagram

```
┌───────────────────────────────────────────────────────────┐
│                      Docker Host                           │
│                                                           │
│  ┌────────────────┐                                       │
│  │  Caddy (HTTPS) │                                       │
│  │  :443  :3340   │                                       │
│  └───┬────────┬───┘                                       │
│      │        │                                           │
│      │        └──────────────┐                            │
│      ▼                       ▼                            │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │  Nextcloud   │    │  MCP Server  │                     │
│  │  :8080 (http)│    │  :3339 (http)│                     │
│  │  + AIquila   │    │  (HTTP/SSE)  │                     │
│  │  (mounted)   │    │  (mounted)   │                     │
│  └──────┬───────┘    └──────┬───────┘                     │
│         │                   │ http://nextcloud             │
│         │                   │                              │
│  ┌──────┴───────┐    ┌─────┴────────┐                     │
│  │  PostgreSQL  │    │    Redis     │                     │
│  └──────────────┘    └──────────────┘                     │
│                                                           │
│  ┌──────────────┐    ┌──────────────┐                     │
│  │   MailHog    │    │   Adminer    │                     │
│  │   :8025      │    │   :8081      │                     │
│  └──────────────┘    └──────────────┘                     │
└───────────────────────────────────────────────────────────┘
```

## Makefile Command Reference

```bash
make help          # Show all available commands
make up            # Start all services
make down          # Stop all services
make restart       # Restart all services
make logs          # View all logs (follow mode)
make logs-nc       # View Nextcloud logs
make logs-mcp      # View MCP server logs
make status        # Show status of all services
make shell-nc      # Open shell in Nextcloud container
make shell-mcp     # Open shell in MCP server container
make shell-db      # Open PostgreSQL shell
make build         # Build all containers
make clean         # Stop and remove containers (keeps volumes)
make reset         # Full reset (DESTRUCTIVE - removes all data)
make test-nc       # Run Nextcloud app tests
make test-mcp      # Run MCP server tests
```

## Getting Help

If you encounter issues:

1. Check the troubleshooting section above
2. View logs: `make logs`
3. Check service status: `make status`
4. Try a clean restart: `make clean && make up`
5. As last resort: `make reset && make up`

For more help, see:
- [Development Guide](development.md)
- [Installation Guide](installation.md)
- [GitHub Issues](https://github.com/yourusername/aiquila/issues)
