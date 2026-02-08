.PHONY: help build-tarball build up down logs logs-follow status shell test clean reset

SCRIPTS_DIR := ../scripts
COMPOSE := docker compose

# Default target
help:
	@echo "AIquila Installation Test Environment"
	@echo ""
	@echo "Workflow:"
	@echo "  make build-tarball  - Build aiquila.tar.gz from source (runs on host)"
	@echo "  make build          - Build Docker image (embeds tarball)"
	@echo "  make up             - Start services (builds tarball + image if needed)"
	@echo "  make down           - Stop services"
	@echo "  make test           - Run installation verification"
	@echo "  make clean          - Stop services, remove volumes, delete tarball"
	@echo "  make reset          - Full reset (rebuild tarball + image from scratch)"
	@echo ""
	@echo "Debugging:"
	@echo "  make logs           - Show logs (last 100 lines)"
	@echo "  make logs-follow    - Follow logs in real time"
	@echo "  make shell          - Open shell in Nextcloud container"
	@echo "  make status         - Show service status"
	@echo "  make nc-log         - Show Nextcloud application log"
	@echo ""
	@echo "Access: http://localhost:8090  (admin / admin123)"

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
	@echo "Access:       http://localhost:8090"

# Stop services
down:
	$(COMPOSE) down

# Show recent logs
logs:
	$(COMPOSE) logs --tail=100

# Follow logs in real time
logs-follow:
	$(COMPOSE) logs -f

# Show service status
status:
	$(COMPOSE) ps

# Open shell in Nextcloud container
shell:
	$(COMPOSE) exec nextcloud bash

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
	@curl -s -o /dev/null -w "HTTP status: %{http_code}\n" http://localhost:8090/status.php || echo "FAIL: Nextcloud not responding"

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
