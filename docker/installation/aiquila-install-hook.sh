#!/bin/bash
set -euo pipefail

echo "=== AIquila post-installation hook ==="
echo "[INFO] Running as: $(id)"

TARBALL="/tmp/aiquila.tar.gz"
APPS_DIR="/var/www/html/custom_apps"

occ() {
    if [ "$(id -u)" = "0" ]; then
        su -p www-data -s /bin/sh -c "php /var/www/html/occ $*"
    else
        php /var/www/html/occ "$@"
    fi
}

# --- Extract tarball ---
if [ ! -f "$TARBALL" ]; then
    echo "[ERROR] Tarball not found: $TARBALL"
    exit 1
fi

echo "[INFO] Tarball size: $(du -h "$TARBALL" | cut -f1)"
mkdir -p "$APPS_DIR"
[ -d "$APPS_DIR/aiquila" ] && rm -rf "$APPS_DIR/aiquila"
tar -xzf "$TARBALL" -C "$APPS_DIR"
# Only chown if running as root; if already www-data files are already owned correctly
[ "$(id -u)" = "0" ] && chown -R www-data:www-data "$APPS_DIR/aiquila"
echo "[OK] AIquila extracted to $APPS_DIR/aiquila"

# --- Enable app ---
echo "[OCC] Enabling AIquila app..."
occ app:enable aiquila
echo "[OK] AIquila enabled"

# --- Install optional apps for full test coverage ---
# Installing an app that is already there fails, and this hook also runs on an
# existing stack.
echo "[OCC] Installing Talk, Notes, Tasks, Tables and Team folders..."
for app in spreed notes tasks tables groupfolders; do
    occ app:install "$app" 2>/dev/null || occ app:enable "$app"
done
echo "[OK] Talk, Notes, Tasks, Tables and Team folders installed"

# --- Background jobs via the nextcloud-cron service ---
occ background:cron
echo "[OK] Background jobs set to cron"

# --- Optional: create test user ---
if [ -n "${AIQUILA_TEST_USER:-}" ] && [ -n "${AIQUILA_TEST_PASSWORD:-}" ]; then
    echo "[SETUP] Creating test user: $AIQUILA_TEST_USER"
    OC_PASS="$AIQUILA_TEST_PASSWORD" occ user:add --password-from-env --display-name "Test User" --group users "$AIQUILA_TEST_USER" || true
fi

# --- Optional: configure Claude API key ---
if [ -n "${AIQUILA_CLAUDE_API_KEY:-}" ]; then
    echo "[SETUP] Setting Claude API key..."
    occ config:app:set aiquila api_key --value="$AIQUILA_CLAUDE_API_KEY" || true
fi

# --- Debug settings ---
occ config:system:set debug --value=true --type=boolean 2>/dev/null || true
occ config:system:set loglevel --value=0 --type=integer 2>/dev/null || true

echo "=== AIquila post-installation hook complete ==="
