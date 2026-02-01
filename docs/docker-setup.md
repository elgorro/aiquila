# Docker Development Environment

Complete guide for setting up and using the AIquila Docker development environment.

## Overview

The Docker environment provides a complete, isolated development setup with:

- **PostgreSQL 16** - Production-grade database
- **Nextcloud 31** - Test instance with AIquila app mounted
- **Redis 7** - Caching and performance
- **MCP Server** - Development container with hot reload
- **MailHog** - Email testing and debugging
- **Adminer** - Database management UI

## Quick Start

### Prerequisites

- Docker Engine 20.10+
- Docker Compose 2.0+
- 4GB+ RAM available for Docker

### Initial Setup

```bash
# Navigate to docker directory
cd /home/sirgorro/Dev/aiquila/docker

# Copy environment template
cp .env.example .env

# Edit environment variables (optional - defaults work fine)
nano .env

# Start all services
make up
```

### First Time Setup

The first time you run `make up`, Docker will:

1. Download all required images (~2-3 GB)
2. Create PostgreSQL database
3. Install Nextcloud
4. Enable AIquila app
5. Create test user for MCP server
6. Configure Redis caching
7. Set up MailHog for email testing

This takes 2-3 minutes. Watch progress with:

```bash
make logs
```

### Access Points

Once started, access the services at:

| Service | URL | Credentials |
|---------|-----|-------------|
| **Nextcloud** | http://localhost:8080 | admin / admin123 |
| **MailHog UI** | http://localhost:8025 | (no auth) |
| **Adminer** | http://localhost:8081 | See below |

**Adminer credentials:**
- System: `PostgreSQL`
- Server: `db`
- Username: `nextcloud`
- Password: `nextcloud_secure_password` (or your .env value)
- Database: `nextcloud`

## Development Workflow

### Live Code Reloading

**Nextcloud App:**
- Code in `nextcloud-app/` is mounted into the container
- Changes to PHP/JS/CSS are immediately reflected
- No rebuild needed
- Just refresh your browser

**MCP Server:**
- Code in `mcp-server/` is mounted into the container
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

### Custom Nextcloud Configuration

Edit `docker/nextcloud/custom.config.php` to modify Nextcloud settings:

- Redis configuration
- Debug settings
- App paths
- Upload limits
- etc.

Changes require Nextcloud restart:

```bash
docker-compose restart nextcloud
```

### Port Conflicts

If ports 8080, 8025, or 8081 are already in use, edit `docker/docker-compose.yml`:

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

The MCP server container can communicate with Nextcloud:

```bash
# Check MCP server logs
make logs-mcp

# The server connects to http://nextcloud internally
# Using credentials: testuser / testpass123

# Test file operations
make shell-mcp
curl -u testuser:testpass123 http://nextcloud/remote.php/dav/files/testuser/
```

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

### AIquila App Not Enabled

```bash
make shell-nc
php occ app:enable aiquila
php occ app:list | grep aiquila
```

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
┌─────────────────────────────────────────────────────┐
│                   Docker Host                       │
│                                                     │
│  ┌──────────────┐    ┌──────────────┐             │
│  │  Nextcloud   │────│  PostgreSQL  │             │
│  │   :8080      │    │              │             │
│  │              │    └──────────────┘             │
│  │  + AIquila   │                                  │
│  │    app       │    ┌──────────────┐             │
│  │  (mounted)   │────│    Redis     │             │
│  └──────────────┘    │              │             │
│         │            └──────────────┘             │
│         │                                          │
│         │            ┌──────────────┐             │
│         └────────────│   MailHog    │             │
│                      │   :8025      │             │
│  ┌──────────────┐    └──────────────┘             │
│  │  MCP Server  │                                  │
│  │              │    ┌──────────────┐             │
│  │  (mounted)   │────│   Adminer    │             │
│  └──────────────┘    │   :8081      │             │
│                      └──────────────┘             │
└─────────────────────────────────────────────────────┘
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
