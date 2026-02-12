.PHONY: help up down restart logs logs-nc logs-mcp status shell-nc shell-mcp shell-db build clean reset test-nc test-mcp install-test install-test-clean standalone-up standalone-down standalone-logs standalone-test

# Default target
help:
	@echo "AIquila Docker Development Environment"
	@echo ""
	@echo "Available commands:"
	@echo "  make up           - Start all services"
	@echo "  make down         - Stop all services"
	@echo "  make restart      - Restart all services"
	@echo "  make logs         - View all logs (follow mode)"
	@echo "  make logs-nc      - View Nextcloud logs"
	@echo "  make logs-mcp     - View MCP server logs"
	@echo "  make status       - Show status of all services"
	@echo "  make shell-nc     - Open shell in Nextcloud container"
	@echo "  make shell-mcp    - Open shell in MCP server container"
	@echo "  make shell-db     - Open PostgreSQL shell"
	@echo "  make build        - Build all containers"
	@echo "  make clean        - Stop and remove containers (keeps volumes)"
	@echo "  make reset        - Full reset (removes volumes - DESTRUCTIVE)"
	@echo "  make test-nc      - Run Nextcloud app tests"
	@echo "  make test-mcp     - Run MCP server tests"
	@echo "  make install-test - Start installation test environment (port 8090)"
	@echo "  make install-test-clean - Clean up installation test"
	@echo ""
	@echo "Quick access URLs (HTTPS via Caddy):"
	@echo "  Nextcloud:    https://localhost"
	@echo "  MCP Server:   https://localhost:3340/mcp"
	@echo "  MailHog:      http://localhost:8025"
	@echo "  Adminer:      http://localhost:8081"
	@echo ""
	@echo "Direct HTTP (no TLS):"
	@echo "  Nextcloud:    http://localhost:8080"
	@echo "  MCP Server:   http://localhost:3339/mcp"
	@echo ""
	@echo "Standalone MCP Server (external Nextcloud):"
	@echo "  make standalone-up     - Start MCP server only (standalone mode)"
	@echo "  make standalone-down   - Stop standalone MCP server"
	@echo "  make standalone-logs   - View standalone MCP server logs"
	@echo "  make standalone-test   - Test standalone MCP connectivity"

# Start all services
up:
	@echo "🚀 Starting AIquila development environment..."
	docker-compose up -d
	@echo "✅ All services started!"
	@echo ""
	@echo "Access points (HTTPS via Caddy):"
	@echo "  Nextcloud:    https://localhost"
	@echo "  MCP Server:   https://localhost:3340/mcp"
	@echo "  MailHog:      http://localhost:8025"
	@echo "  Adminer:      http://localhost:8081"

# Stop all services
down:
	@echo "🛑 Stopping all services..."
	docker-compose down
	@echo "✅ All services stopped"

# Restart all services
restart:
	@echo "🔄 Restarting all services..."
	docker-compose restart
	@echo "✅ All services restarted"

# View all logs
logs:
	docker-compose logs -f

# View Nextcloud logs
logs-nc:
	docker-compose logs -f nextcloud

# View MCP server logs
logs-mcp:
	docker-compose logs -f mcp-server

# Show service status
status:
	docker-compose ps

# Open shell in Nextcloud container
shell-nc:
	docker-compose exec nextcloud bash

# Open shell in MCP server container
shell-mcp:
	docker-compose exec mcp-server sh

# Open PostgreSQL shell
shell-db:
	docker-compose exec db psql -U nextcloud -d nextcloud

# Build all containers
build:
	@echo "🔨 Building all containers..."
	docker-compose build
	@echo "✅ Build complete"

# Clean up (keeps volumes)
clean:
	@echo "🧹 Cleaning up containers..."
	docker-compose down -v --remove-orphans
	@echo "✅ Cleanup complete (volumes preserved)"

# Full reset (DESTRUCTIVE - removes all data)
reset:
	@echo "⚠️  WARNING: This will delete all data including database!"
	@read -p "Are you sure? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		docker-compose down -v --remove-orphans; \
		docker volume rm aiquila_postgres_data aiquila_redis_data aiquila_nextcloud_data 2>/dev/null || true; \
		echo "✅ Full reset complete"; \
	else \
		echo "❌ Reset cancelled"; \
	fi

# Run Nextcloud app tests
test-nc:
	@echo "🧪 Running Nextcloud app tests..."
	docker-compose exec nextcloud su -s /bin/sh www-data -c "cd /var/www/html/custom_apps/aiquila && ../../lib/composer/bin/phpunit"

# Run MCP server tests
test-mcp:
	@echo "🧪 Running MCP server tests..."
	docker-compose exec mcp-server npm test

# Installation test environment
install-test:
	@echo "🧪 Starting installation test environment..."
	$(MAKE) -C installation up

install-test-clean:
	$(MAKE) -C installation clean

# Standalone MCP server (connects to external Nextcloud)
standalone-up:
	$(MAKE) -C standalone up

standalone-down:
	$(MAKE) -C standalone down

standalone-logs:
	$(MAKE) -C standalone logs-follow

standalone-test:
	$(MAKE) -C standalone test
