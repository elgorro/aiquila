#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# AIquila Tarball Builder
# Replicates the CI packaging process (nc-release.yml) locally using Docker.
# Output: docker/installation/aiquila.tar.gz
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
APP_DIR="${PROJECT_ROOT}/nextcloud-app"
OUTPUT_DIR="${PROJECT_ROOT}/docker/installation"

echo "=== AIquila Tarball Builder ==="
echo "App source: ${APP_DIR}"
echo "Output:     ${OUTPUT_DIR}/aiquila.tar.gz"
echo ""

# Verify we're in the right place
if [ ! -f "${APP_DIR}/appinfo/info.xml" ]; then
    echo "ERROR: Cannot find nextcloud-app/appinfo/info.xml"
    echo "Run this script from the project root or via the Makefile."
    exit 1
fi

# Extract version for informational purposes
VERSION=$(grep -oP '(?<=<version>)[^<]+' "${APP_DIR}/appinfo/info.xml")
echo "App version: ${VERSION}"
echo ""

# Build inside Docker to avoid requiring composer/node on host
echo "[1/2] Building app inside Docker (composer + npm + vite)..."

docker run --rm \
    -v "${APP_DIR}:/app" \
    -v "${OUTPUT_DIR}:/output" \
    -w /app \
    node:20 \
    bash -c '
        set -euo pipefail

        echo "--- Installing PHP 8.4 + Composer ---"
        apt-get update -qq && apt-get install -y -qq apt-transport-https ca-certificates curl lsb-release gnupg > /dev/null 2>&1
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        apt-get update -qq && apt-get install -y -qq php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl unzip > /dev/null 2>&1
        curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
        export COMPOSER_ALLOW_SUPERUSER=1
        echo "PHP $(php -v | head -1 | cut -d" " -f2) + Composer installed."

        echo ""
        echo "--- [1/4] Installing PHP dependencies ---"
        composer update --no-dev --optimize-autoloader --quiet

        echo "--- [2/4] Installing Node dependencies ---"
        npm ci --silent

        echo "--- [3/4] Building frontend ---"
        npm run build

        echo "--- [4/4] Verifying build ---"
        test -f js/aiquila-main.js || { echo "ERROR: js/aiquila-main.js not found!"; exit 1; }
        test -d vendor || { echo "ERROR: vendor/ not found!"; exit 1; }
        echo "Build verified."

        echo ""
        echo "--- Assembling tarball ---"
        mkdir -p /tmp/aiquila

        for dir in appinfo lib js templates css img vendor; do
            [ -d "$dir" ] && cp -r "$dir" /tmp/aiquila/
        done

        cp composer.json /tmp/aiquila/
        [ -f LICENSE ] && cp LICENSE /tmp/aiquila/
        [ -f CHANGELOG.md ] && cp CHANGELOG.md /tmp/aiquila/

        cd /tmp
        tar -czf /output/aiquila.tar.gz aiquila

        echo ""
        echo "Tarball created."
    '

echo ""
echo "[2/2] Verifying tarball..."

if [ ! -f "${OUTPUT_DIR}/aiquila.tar.gz" ]; then
    echo "ERROR: Tarball was not created!"
    exit 1
fi

echo ""
echo "=== Build complete ==="
echo "Tarball: ${OUTPUT_DIR}/aiquila.tar.gz"
echo "Size:    $(du -h "${OUTPUT_DIR}/aiquila.tar.gz" | cut -f1)"
echo ""
echo "Contents:"
tar -tzf "${OUTPUT_DIR}/aiquila.tar.gz" | head -30 || true
echo "..."
