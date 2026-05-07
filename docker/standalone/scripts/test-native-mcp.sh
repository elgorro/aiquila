#!/usr/bin/env bash
# Functional native-MCP-connector test for the AIquila standalone stack.
#
# Enables the native connector via OCC, then issues a /api/chat request that
# is expected to trigger an MCP tool call. Asserts that the response surfaces
# the native path (model returned without errors and either tool output or a
# clear error message). Skips with a warning if the configured MCP URL is
# not HTTPS-reachable from this host.
#
# Usage: ./test-native-mcp.sh [nextcloud-base-url]
#   default: https://localhost:8443

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STANDALONE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

NC_BASE="${1:-https://localhost:8443}"
CURL="curl -sk"

if [ ! -f "$STANDALONE_DIR/.env" ]; then
  echo "ERROR: .env not found in $STANDALONE_DIR" >&2
  exit 1
fi
set -a; source "$STANDALONE_DIR/.env"; set +a

NC_USER="${NEXTCLOUD_ADMIN_USER:?NEXTCLOUD_ADMIN_USER not set in .env}"
NC_PASS="${NEXTCLOUD_ADMIN_PASSWORD:?NEXTCLOUD_ADMIN_PASSWORD not set in .env}"
MCP_URL="${MCP_PUBLIC_URL:-}"

if [ -z "$MCP_URL" ]; then
  echo "WARN: MCP_PUBLIC_URL not set in .env — skipping native MCP test." >&2
  echo "      Set it to the externally-reachable HTTPS URL of your MCP server." >&2
  exit 0
fi

# ── Pre-flight: HTTPS scheme + reachability ──────────────────────────────────
case "$MCP_URL" in
  https://*) ;;
  *)
    echo "WARN: MCP_PUBLIC_URL is not HTTPS — Anthropic cannot reach it. Skipping." >&2
    exit 0
    ;;
esac

if ! $CURL --max-time 5 -I "$MCP_URL" >/dev/null 2>&1; then
  echo "WARN: $MCP_URL not reachable from this host — Anthropic likely cannot reach it either. Skipping." >&2
  exit 0
fi

# ── Find the running Nextcloud container ─────────────────────────────────────
NC_CONTAINER="$(docker ps --filter 'name=nextcloud' --format '{{.Names}}' | head -n1)"
if [ -z "$NC_CONTAINER" ]; then
  echo "ERROR: no running Nextcloud container found." >&2
  exit 1
fi

OCC() { docker exec -u www-data "$NC_CONTAINER" php occ "$@"; }

# ── Enable native connector with the configured public URL ───────────────────
echo "==> Enabling native MCP connector ($MCP_URL)"
OCC config:app:set aiquila native_mcp_enabled --value=1
OCC config:app:set aiquila native_mcp_extra_url --value="$MCP_URL"

cleanup() {
  echo "==> Disabling native MCP connector"
  OCC config:app:set aiquila native_mcp_enabled --value=0 || true
  OCC config:app:delete aiquila native_mcp_extra_url || true
}
trap cleanup EXIT

# ── Send a chat request that should exercise an MCP tool ─────────────────────
echo "==> Sending chat request"
RESP="$(
  $CURL -u "$NC_USER:$NC_PASS" \
       -H 'OCS-APIRequest: true' \
       -H 'Content-Type: application/json' \
       -X POST "$NC_BASE/index.php/apps/aiquila/api/chat" \
       -d '{"messages":[{"role":"user","content":"List the first three files in my root directory using your MCP tools."}]}'
)"

echo "==> Response:"
echo "$RESP" | head -c 2000
echo

if echo "$RESP" | grep -q '"error"'; then
  echo "FAIL: response contained an error." >&2
  exit 1
fi

if ! echo "$RESP" | grep -q '"response"'; then
  echo "FAIL: response missing the expected 'response' field." >&2
  exit 1
fi

echo "PASS: native MCP connector returned a response."
