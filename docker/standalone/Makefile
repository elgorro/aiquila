.PHONY: help up down restart logs logs-follow status shell build clean test test-oauth test-tools test-mcp-connector

COMPOSE := docker compose

# Default target
help:
	@echo "AIquila MCP Server - Standalone Mode"
	@echo ""
	@echo "Runs ONLY the MCP server (+ HTTPS proxy)."
	@echo "Connects to an EXTERNAL Nextcloud instance."
	@echo ""
	@echo "Setup:"
	@echo "  cp .env.example .env   - Create config from template"
	@echo "  nano .env              - Set your Nextcloud URL/credentials"
	@echo "  make up                - Start MCP server"
	@echo ""
	@echo "Available commands:"
	@echo "  make up           - Start MCP server and Caddy proxy"
	@echo "  make down         - Stop all services"
	@echo "  make restart      - Restart all services"
	@echo "  make logs         - View logs (last 100 lines)"
	@echo "  make logs-follow  - Follow logs in real time"
	@echo "  make status       - Show service status"
	@echo "  make shell        - Open shell in MCP server container"
	@echo "  make build        - Rebuild MCP server container"
	@echo "  make clean        - Stop and remove containers and volumes"
	@echo "  make test         - Test MCP server connectivity"
	@echo "  make test-oauth   - Full OAuth PKCE flow test (auth enabled)"
	@echo "  make test-tools   - Functional tool test (file CRUD, search, status)"
	@echo "  make test-mcp-connector - MCP-Connector integration test (Messages API beta)"
	@echo ""
	@echo "Access points:"
	@echo "  HTTPS: https://localhost:3340/mcp (self-signed cert)"
	@echo "  HTTP:  http://localhost:3339/mcp"

# Start services
up:
	@if [ ! -f .env ]; then \
		echo "❌ ERROR: .env file not found."; \
		echo ""; \
		echo "Run:  cp .env.example .env"; \
		echo "Then: edit .env with your Nextcloud credentials"; \
		exit 1; \
	fi
	@echo "🚀 Starting AIquila MCP server (standalone)..."
	$(COMPOSE) up -d
	@echo "✅ MCP server started!"
	@echo ""
	@echo "Access points:"
	@echo "  HTTPS: https://localhost:3340/mcp"
	@echo "  HTTP:  http://localhost:3339/mcp"

# Stop services
down:
	@echo "🛑 Stopping MCP server..."
	$(COMPOSE) down
	@echo "✅ MCP server stopped"

# Restart services
restart:
	@echo "🔄 Restarting MCP server..."
	$(COMPOSE) restart
	@echo "✅ MCP server restarted"

# Show recent logs
logs:
	$(COMPOSE) logs --tail=100

# Follow logs in real time
logs-follow:
	$(COMPOSE) logs -f

# Show service status
status:
	$(COMPOSE) ps

# Open shell in MCP server container
shell:
	$(COMPOSE) exec mcp-server sh

# Rebuild containers
build:
	@echo "🔨 Building MCP server container..."
	$(COMPOSE) build
	@echo "✅ Build complete"

# Clean up (stop + remove volumes)
clean:
	@echo "🧹 Cleaning up..."
	$(COMPOSE) down -v --remove-orphans
	@echo "✅ Cleanup complete"

# Full OAuth PKCE flow test (requires MCP_AUTH_ENABLED=true)
test-oauth:
	@bash scripts/test-oauth.sh

# Functional tool test: file CRUD, search, system status (requires MCP_AUTH_ENABLED=true)
test-tools:
	@bash scripts/test-tools.sh

# MCP-Connector integration test: OAuth PKCE → Messages API beta → Claude calls AIquila tools
# Requires: MCP_AUTH_ENABLED=true, ANTHROPIC_API_KEY, NEXTCLOUD_USER, NEXTCLOUD_PASSWORD in .env
test-mcp-connector:
	@set -a; . ./.env; set +a; \
	cd ../../mcp-server/scripts && npm install --silent && \
	MCP_URL=http://localhost:3339 tsx test-mcp-connector.ts

# Test MCP server connectivity
test:
	@echo "🧪 Testing MCP server..."
	@echo ""
	@echo "--- MCP endpoint (HTTP) ---"
	@curl -sf -X POST http://localhost:3339/mcp \
		-H "Content-Type: application/json" \
		-d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}' \
		| head -c 200 && echo "" \
		|| echo "❌ FAIL: MCP server not responding on port 3339"
	@echo ""
	@echo "--- Nextcloud connectivity (from container) ---"
	@$(COMPOSE) exec mcp-server sh -c 'curl -sf -o /dev/null -w "HTTP %{http_code}" -u "$$NEXTCLOUD_USER:$$NEXTCLOUD_PASSWORD" "$$NEXTCLOUD_URL/status.php"' \
		&& echo " ✅" \
		|| echo "❌ FAIL: Cannot reach Nextcloud from MCP container"
