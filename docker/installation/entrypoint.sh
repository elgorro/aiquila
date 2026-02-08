#!/bin/bash
set -e

echo "=== AIquila Installation Test Environment ==="
echo ""

# Run the original Nextcloud entrypoint in the background
/entrypoint.sh apache2-foreground &
APACHE_PID=$!

# Wait for Nextcloud to be ready
echo "[WAIT] Waiting for Nextcloud to initialize..."
TIMEOUT=120
ELAPSED=0
until curl -s http://localhost/status.php > /dev/null 2>&1; do
    sleep 2
    ELAPSED=$((ELAPSED + 2))
    if [ $ELAPSED -ge $TIMEOUT ]; then
        echo "[ERROR] Nextcloud failed to start within ${TIMEOUT}s"
        exit 1
    fi
done

echo "[OK] Nextcloud is ready (took ~${ELAPSED}s)"

# Helper function for occ commands with logging (preserves argument quoting)
run_occ() {
    local cmd="php /var/www/html/occ"
    for arg in "$@"; do
        cmd="$cmd $(printf '%q' "$arg")"
    done
    echo "[OCC] Running: occ $*"
    su -p www-data -s /bin/sh -c "$cmd"
}

# Wait for complete initialization
sleep 5

# === INSTALLATION FROM TARBALL ===
echo ""
echo "=== Installing AIquila from tarball ==="

TARBALL="/tmp/aiquila.tar.gz"
APPS_DIR="/var/www/html/custom_apps"

if [ ! -f "$TARBALL" ]; then
    echo "[ERROR] Tarball not found at $TARBALL"
    exit 1
fi

echo "[INFO] Tarball: $TARBALL"
echo "[INFO] Size: $(du -h "$TARBALL" | cut -f1)"
echo "[INFO] Target: $APPS_DIR"

# Create custom_apps directory if it doesn't exist
mkdir -p "$APPS_DIR"

# Remove existing installation if present (for re-runs with persistent volume)
if [ -d "$APPS_DIR/aiquila" ]; then
    echo "[INSTALL] Removing previous installation..."
    rm -rf "$APPS_DIR/aiquila"
fi

# Extract tarball
echo "[INSTALL] Extracting tarball..."
tar -xzf "$TARBALL" -C "$APPS_DIR"

# Verify extraction
if [ ! -d "$APPS_DIR/aiquila" ]; then
    echo "[ERROR] Extraction failed - aiquila directory not found"
    ls -la "$APPS_DIR/"
    exit 1
fi

echo "[INSTALL] Extracted contents:"
ls -la "$APPS_DIR/aiquila/"

# Set ownership (critical for Nextcloud)
echo "[INSTALL] Setting file ownership..."
chown -R www-data:www-data "$APPS_DIR/aiquila"

# Verify key files
echo ""
echo "[VERIFY] Checking required files..."
MISSING=0
for f in appinfo/info.xml lib/AppInfo/Application.php js/aiquila-main.js composer.json; do
    if [ -f "$APPS_DIR/aiquila/$f" ]; then
        echo "[VERIFY]   OK: $f"
    else
        echo "[VERIFY]   MISSING: $f"
        MISSING=$((MISSING + 1))
    fi
done

# Check vendor separately (this is the critical one often missing)
if [ -f "$APPS_DIR/aiquila/vendor/autoload.php" ]; then
    echo "[VERIFY]   OK: vendor/autoload.php"
else
    echo "[VERIFY]   MISSING: vendor/autoload.php (Anthropic SDK will not work!)"
    MISSING=$((MISSING + 1))
fi

if [ $MISSING -gt 0 ]; then
    echo ""
    echo "[WARNING] $MISSING required file(s) missing from tarball!"
fi

# Enable the app via occ
echo ""
echo "[INSTALL] Enabling AIquila app via occ..."
if run_occ app:enable aiquila; then
    echo "[OK] AIquila app enabled successfully!"
else
    ENABLE_EXIT=$?
    echo "[ERROR] Failed to enable AIquila app (exit code: $ENABLE_EXIT)"
    echo ""
    echo "[DEBUG] === App list ==="
    run_occ app:list 2>&1 || true
    echo ""
    echo "[DEBUG] === Nextcloud log (last 50 lines) ==="
    tail -50 /var/www/html/data/nextcloud.log 2>/dev/null || echo "(no log file yet)"
    echo ""
    echo "[DEBUG] === PHP error log ==="
    tail -20 /var/log/apache2/error.log 2>/dev/null || true
fi

# Show final status
echo ""
echo "=== Installation Status ==="
run_occ app:list 2>&1 | grep -A2 aiquila || echo "(aiquila not found in app list)"
echo ""

# Create test user if configured
if [ -n "${AIQUILA_TEST_USER:-}" ] && [ -n "${AIQUILA_TEST_PASSWORD:-}" ]; then
    if ! run_occ user:list 2>/dev/null | grep -q "$AIQUILA_TEST_USER"; then
        echo "[SETUP] Creating test user: $AIQUILA_TEST_USER"
        export OC_PASS="$AIQUILA_TEST_PASSWORD"
        run_occ user:add --password-from-env --display-name "Test User" --group "users" "$AIQUILA_TEST_USER" || true
    fi
fi

# Configure Claude API key if provided
if [ -n "${AIQUILA_CLAUDE_API_KEY:-}" ]; then
    echo "[SETUP] Configuring Claude API key..."
    run_occ config:app:set aiquila api_key --value="$AIQUILA_CLAUDE_API_KEY" || true
fi

# Enable debug settings
echo "[SETUP] Applying debug settings..."
run_occ config:system:set debug --value=true --type=boolean 2>/dev/null || true
run_occ config:system:set loglevel --value=0 --type=integer 2>/dev/null || true

echo ""
echo "=== AIquila Installation Test Ready ==="
echo "  Nextcloud:  http://localhost:8090"
echo "  Admin user: ${NEXTCLOUD_ADMIN_USER:-admin}"
echo ""

# Keep running
wait $APACHE_PID
