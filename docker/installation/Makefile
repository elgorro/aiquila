.PHONY: help build-tarball build up down restart logs logs-nc logs-mcp logs-follow status shell shell-mcp shell-db nc-log test test-mcp clean reset standalone-up standalone-down standalone-logs standalone-test

SCRIPTS_DIR := ../scripts
COMPOSE := docker compose

# Default target
help:
	@echo "AIquila Docker Development Environment"
	@echo ""
	@echo "Workflow:"
	@echo "  make build-tarball  - Build aiquila.tar.gz from source (runs on host)"
	@echo "  make build          - Build Docker image (embeds tarball)"
	@echo "  make up             - Start all services (builds tarball + image if needed)"
	@echo "  make down           - Stop all services"
	@echo "  make restart        - Restart all services"
	@echo "  make clean          - Stop services, remove volumes, delete tarball"
	@echo "  make reset          - Full reset (rebuild tarball + image from scratch)"
	@echo ""
	@echo "Logs:"
	@echo "  make logs           - Show logs (last 100 lines)"
	@echo "  make logs-follow    - Follow all logs in real time"
	@echo "  make logs-nc        - Follow Nextcloud logs"
	@echo "  make logs-mcp       - Follow MCP server logs"
	@echo ""
	@echo "Shells:"
	@echo "  make shell          - Open shell in Nextcloud container"
	@echo "  make shell-mcp      - Open shell in MCP server container"
	@echo "  make shell-db       - Open PostgreSQL shell"
	@echo ""
	@echo "Testing:"
	@echo "  make test           - Run installation verification"
	@echo "  make test-mcp       - Run MCP server tests"
	@echo "  make nc-log         - Show Nextcloud application log"
	@echo ""
	@echo "Standalone MCP server (external Nextcloud):"
	@echo "  make standalone-up      - Start MCP server only"
	@echo "  make standalone-down    - Stop standalone MCP server"
	@echo "  make standalone-logs    - Follow standalone MCP logs"
	@echo "  make standalone-test    - Test standalone MCP connectivity"
	@echo ""
	@echo "Access points (HTTPS via Caddy):"
	@echo "  Nextcloud:  https://localhost"
	@echo "  MCP Server: https://localhost:3340/mcp"
	@echo "  MailHog:    http://localhost:8025"
	@echo "  Adminer:    http://localhost:8081"
	@echo ""
	@echo "Direct HTTP (no TLS):"
	@echo "  Nextcloud:  http://localhost:8080"
	@echo "  MCP Server: http://localhost:3339/mcp"

# Build tarball from source
build-tarball:
	@echo "=== Building aiquila.tar.gz ==="
	@bash $(SCRIPTS_DIR)/build-tarball.sh

# Build Docker image (requires tarball)
build: aiquila.tar.gz
	@echo "=== Building Docker image ==="
	$(COMPOSE) build --no-cache

# Ensure tarball exists
aiquila.tar.gz:
	@$(MAKE) build-tarball

# Start everything (builds if needed)
up: aiquila.tar.gz
	$(COMPOSE) up -d --build
	@echo ""
	@echo "=== Starting... ==="
	@echo "Follow logs:  make logs-follow"
	@echo "Nextcloud:    http://localhost:8080"
	@echo "MCP Server:   http://localhost:3339"
	@echo "MailHog:      http://localhost:8025"
	@echo "Adminer:      http://localhost:8081"

# Stop all services
down:
	$(COMPOSE) down

# Restart all services
restart:
	$(COMPOSE) restart

# Show recent logs
logs:
	$(COMPOSE) logs --tail=100

# Follow all logs in real time
logs-follow:
	$(COMPOSE) logs -f

# Follow Nextcloud logs
logs-nc:
	$(COMPOSE) logs -f nextcloud

# Follow MCP server logs
logs-mcp:
	$(COMPOSE) logs -f mcp-server

# Show service status
status:
	$(COMPOSE) ps

# Open shell in Nextcloud container
shell:
	$(COMPOSE) exec nextcloud bash

# Open shell in MCP server container
shell-mcp:
	$(COMPOSE) exec mcp-server sh

# Open PostgreSQL shell
shell-db:
	$(COMPOSE) exec db psql -U nextcloud -d nextcloud

# Show Nextcloud application log
nc-log:
	$(COMPOSE) exec nextcloud tail -100 /var/www/html/data/nextcloud.log 2>/dev/null || echo "(no log file yet)"

# Installation verification
test:
	@echo "=== Verifying AIquila installation ==="
	@echo ""
	@echo "--- App status ---"
	@$(COMPOSE) exec nextcloud su -p www-data -s /bin/sh -c "php /var/www/html/occ app:list" 2>/dev/null | grep -A2 aiquila || echo "FAIL: aiquila not in app list"
	@echo ""
	@echo "--- File checks ---"
	@$(COMPOSE) exec nextcloud test -f /var/www/html/custom_apps/aiquila/appinfo/info.xml && echo "OK: appinfo/info.xml" || echo "FAIL: appinfo/info.xml missing"
	@$(COMPOSE) exec nextcloud test -f /var/www/html/custom_apps/aiquila/lib/AppInfo/Application.php && echo "OK: lib/AppInfo/Application.php" || echo "FAIL: lib/AppInfo/Application.php missing"
	@$(COMPOSE) exec nextcloud test -f /var/www/html/custom_apps/aiquila/js/aiquila-main.js && echo "OK: js/aiquila-main.js" || echo "FAIL: js/aiquila-main.js missing"
	@$(COMPOSE) exec nextcloud test -f /var/www/html/custom_apps/aiquila/vendor/autoload.php && echo "OK: vendor/autoload.php" || echo "FAIL: vendor/autoload.php missing"
	@echo ""
	@echo "--- HTTP check ---"
	@curl -s -o /dev/null -w "HTTP status: %{http_code}\n" http://localhost:8080/status.php || echo "FAIL: Nextcloud not responding"

# Run MCP server tests
test-mcp:
	$(COMPOSE) exec mcp-server npm test

# Clean up (stop + remove volumes + delete tarball)
clean:
	$(COMPOSE) down -v --remove-orphans
	rm -f aiquila.tar.gz
	@echo "Cleaned up."

# Full reset: rebuild everything from scratch
reset: clean build-tarball
	$(COMPOSE) up -d --build
	@echo ""
	@echo "Full reset complete. Follow logs: make logs-follow"

# Standalone MCP server (connects to external Nextcloud)
standalone-up:
	$(MAKE) -C ../standalone up

standalone-down:
	$(MAKE) -C ../standalone down

standalone-logs:
	$(MAKE) -C ../standalone logs-follow

standalone-test:
	$(MAKE) -C ../standalone test
