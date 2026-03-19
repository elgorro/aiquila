#!/usr/bin/env bash
# Comprehensive test suite for AIquila dev environment.
# Tests Nextcloud installation + MCP server tools (no auth — dev mode).
# Usage: ./test.sh  (run from docker/installation/)

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
INSTALL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

NC_URL="http://localhost:8080"
MCP_URL="http://localhost:3339"
COMPOSE="docker compose"

# Load .env for test credentials
if [ -f "$INSTALL_DIR/.env" ]; then
  set -a; source "$INSTALL_DIR/.env"; set +a
fi

NC_USER="${NEXTCLOUD_TEST_USER:-testuser}"
NC_PASS="${NEXTCLOUD_TEST_PASSWORD:-AIquila2026!dev}"

TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
TEST_DIR="/__aiquila-test__"
TEST_FILE="$TEST_DIR/hello-$TIMESTAMP.txt"
TEST_CONTENT="AIquila-tool-test-$TIMESTAMP"

PASS=0
FAIL=0
TMPFILE=$(mktemp)
trap 'rm -f "$TMPFILE"' EXIT

# ── Helpers ──────────────────────────────────────────────────────────────────

sep() { echo ""; echo "──────────────────────────────────────────"; echo "  $*"; echo "──────────────────────────────────────────"; }

parse_mcp() {
  python3 -c "
import sys, json
raw = sys.stdin.read().strip()
if raw.startswith('data:') or '\ndata:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
json.dump(json.loads(raw), sys.stdout, indent=4)
print()"
}

check_ok() {
  python3 -c "
import sys, json
raw = open('$TMPFILE').read().strip()
if 'data:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
d = json.loads(raw)
if 'error' in d:
    print('ERROR: ' + json.dumps(d['error']), file=sys.stderr); sys.exit(1)
if d.get('result', {}).get('isError'):
    text = ''.join(c.get('text','') for c in d['result'].get('content',[]))
    print('TOOL ERROR: ' + text, file=sys.stderr); sys.exit(1)
"
}

check_text() {
  local needle="$1"
  python3 -c "
import sys, json
raw = open('$TMPFILE').read().strip()
if 'data:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
d = json.loads(raw)
if 'error' in d or d.get('result',{}).get('isError'):
    sys.exit(1)
text = ''.join(c.get('text','') for c in d.get('result',{}).get('content',[]))
needle = sys.argv[1]
if needle not in text:
    print(f'ERROR: {needle!r} not found in output:\n{text[:400]}', file=sys.stderr)
    sys.exit(1)
" "$needle"
}

step_ok()   { echo "  ✓ PASS"; PASS=$((PASS+1)); }
step_fail() { echo "  ✗ FAIL: $1"; FAIL=$((FAIL+1)); }

occ() {
  $COMPOSE -f "$INSTALL_DIR/docker-compose.yml" exec -T nextcloud \
    su -p www-data -s /bin/sh -c "php /var/www/html/occ $*"
}

mcp_tool() {
  local id="$1" name="$2" args="$3"
  curl -sf -X POST "$MCP_URL/mcp" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json, text/event-stream" \
    ${SESSION_ID:+-H "Mcp-Session-Id: $SESSION_ID"} \
    -d "{\"jsonrpc\":\"2.0\",\"id\":$id,\"method\":\"tools/call\",\"params\":{\"name\":\"$name\",\"arguments\":$args}}" \
    > "$TMPFILE"
  cat "$TMPFILE" | parse_mcp
}

# ── Readiness wait ───────────────────────────────────────────────────────────
sep "Waiting for services"

MAX_WAIT=90
ELAPSED=0

echo -n "  Nextcloud: "
while ! curl -sf --max-time 3 "$NC_URL/status.php" >/dev/null 2>&1; do
  if [ "$ELAPSED" -ge "$MAX_WAIT" ]; then
    echo "TIMEOUT (${MAX_WAIT}s)"
    echo "ERROR: Nextcloud not ready at $NC_URL" >&2
    exit 1
  fi
  sleep 3
  ELAPSED=$((ELAPSED+3))
  echo -n "."
done
echo " ready (${ELAPSED}s)"

echo -n "  MCP server: "
while ! curl -sf --max-time 3 "$MCP_URL/mcp" -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"readiness-check","version":"1.0"}}}' \
  >/dev/null 2>&1; do
  if [ "$ELAPSED" -ge "$MAX_WAIT" ]; then
    echo "TIMEOUT (${MAX_WAIT}s)"
    echo "ERROR: MCP server not ready at $MCP_URL" >&2
    exit 1
  fi
  sleep 3
  ELAPSED=$((ELAPSED+3))
  echo -n "."
done
echo " ready (${ELAPSED}s)"

# Switch to manual pass/fail tracking
set +e

# ══════════════════════════════════════════════════════════════════════════════
#  PART 1 — Nextcloud checks
# ══════════════════════════════════════════════════════════════════════════════

# ── NC 1: App status ────────────────────────────────────────────────────────
sep "NC 1 — App status"
APP_LIST=$(occ "app:list" 2>/dev/null)
if echo "$APP_LIST" | grep -q "aiquila"; then
  VERSION=$(echo "$APP_LIST" | grep "aiquila" | head -1 | sed 's/.*: *//')
  echo "  aiquila version: $VERSION"
  step_ok
else
  step_fail "aiquila not found in app list"
fi

# ── NC 2: File checks ──────────────────────────────────────────────────────
sep "NC 2 — File checks"
NC_FAIL=0
for f in \
  appinfo/info.xml \
  lib/AppInfo/Application.php \
  js/aiquila-main.js \
  vendor/autoload.php; do
  if $COMPOSE -f "$INSTALL_DIR/docker-compose.yml" exec -T nextcloud \
    test -f "/var/www/html/custom_apps/aiquila/$f"; then
    echo "  OK: $f"
  else
    echo "  MISSING: $f"
    NC_FAIL=1
  fi
done
[ "$NC_FAIL" -eq 0 ] && step_ok || step_fail "one or more app files missing"

# ── NC 3: PHP version ──────────────────────────────────────────────────────
sep "NC 3 — PHP version"
PHP_VER=$($COMPOSE -f "$INSTALL_DIR/docker-compose.yml" exec -T nextcloud php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
echo "  PHP $PHP_VER"
if [[ "$PHP_VER" == 8.* ]]; then
  step_ok
else
  step_fail "expected PHP 8.x, got $PHP_VER"
fi

# ── NC 4: HTTP status ──────────────────────────────────────────────────────
sep "NC 4 — HTTP status"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$NC_URL/status.php")
echo "  status.php → HTTP $HTTP_CODE"
[ "$HTTP_CODE" = "200" ] && step_ok || step_fail "expected HTTP 200, got $HTTP_CODE"

# ── NC 5: App page loads (top-bar click) ──────────────────────────────────
sep "NC 5 — App page loads (/apps/aiquila/)"
APP_PAGE=$(curl -s -w "\n%{http_code}" -u "$NC_USER:$NC_PASS" "$NC_URL/apps/aiquila/" \
  -H "OCS-APIREQUEST: true")
APP_HTTP=$(echo "$APP_PAGE" | tail -1)
APP_BODY=$(echo "$APP_PAGE" | sed '$d')
echo "  /apps/aiquila/ → HTTP $APP_HTTP"
if [ "$APP_HTTP" != "200" ]; then
  step_fail "expected HTTP 200, got $APP_HTTP"
elif ! echo "$APP_BODY" | grep -q "aiquila-main"; then
  step_fail "page loaded but aiquila-main.js bundle not found in HTML"
else
  step_ok
fi

# ══════════════════════════════════════════════════════════════════════════════
#  PART 2 — MCP tool tests (no auth — dev mode)
# ══════════════════════════════════════════════════════════════════════════════

# ── MCP 0: Initialize session ──────────────────────────────────────────────
sep "MCP 0 — Initialize session"
INIT_RESP=$(curl -sf -D - -X POST "$MCP_URL/mcp" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"dev-test","version":"1.0"}}}')
SESSION_ID=$(echo "$INIT_RESP" | grep -i '^mcp-session-id:' | tr -d '\r' | sed 's/[Mm]cp-[Ss]ession-[Ii]d: //') || SESSION_ID=""
echo "  session: ${SESSION_ID:-(none — stateless)}"
if echo "$INIT_RESP" | grep -q '"serverInfo"'; then
  step_ok
else
  step_fail "initialize: no serverInfo in response"
fi

# ── MCP 1: system_status ───────────────────────────────────────────────────
sep "MCP 1 — system_status"
mcp_tool 10 "system_status" '{}'
check_text "Nextcloud" && step_ok || step_fail "system_status: unexpected response"

# ── MCP 2: list_files / ────────────────────────────────────────────────────
sep "MCP 2 — list_files /"
mcp_tool 11 "list_files" '{"path":"/"}'
check_ok && step_ok || step_fail "list_files / returned an error"

# ── MCP 3: create_folder ───────────────────────────────────────────────────
sep "MCP 3 — create_folder $TEST_DIR"
mcp_tool 12 "create_folder" "{\"path\":\"$TEST_DIR\"}"
check_ok && step_ok || step_fail "create_folder returned an error"

# ── MCP 4: write_file ──────────────────────────────────────────────────────
sep "MCP 4 — write_file $TEST_FILE"
mcp_tool 13 "write_file" "{\"path\":\"$TEST_FILE\",\"content\":\"$TEST_CONTENT\"}"
check_ok && step_ok || step_fail "write_file returned an error"

# ── MCP 5: read_file + verify content ──────────────────────────────────────
sep "MCP 5 — read_file $TEST_FILE (verify content)"
mcp_tool 14 "read_file" "{\"path\":\"$TEST_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "read_file: content mismatch or error"

# ── MCP 6: list_files in test dir ──────────────────────────────────────────
sep "MCP 6 — list_files $TEST_DIR (verify file present)"
mcp_tool 15 "list_files" "{\"path\":\"$TEST_DIR\"}"
check_text "hello-$TIMESTAMP.txt" && step_ok || step_fail "test file not listed in $TEST_DIR"

# ── MCP 7: get_file_info ───────────────────────────────────────────────────
sep "MCP 7 — get_file_info $TEST_FILE"
mcp_tool 16 "get_file_info" "{\"path\":\"$TEST_FILE\"}"
check_ok && step_ok || step_fail "get_file_info returned an error"

# ── MCP 8: search_files ────────────────────────────────────────────────────
sep "MCP 8 — search_files (query: aiquila-test)"
mcp_tool 17 "search_files" "{\"query\":\"aiquila-test\"}"
check_ok && step_ok || step_fail "search_files returned an error"

# ── MCP 9: copy_file ───────────────────────────────────────────────────────
COPY_FILE="$TEST_DIR/hello-copy-$TIMESTAMP.txt"
sep "MCP 9 — copy_file $TEST_FILE → $COPY_FILE"
mcp_tool 18 "copy_file" "{\"source\":\"$TEST_FILE\",\"destination\":\"$COPY_FILE\"}"
check_ok && step_ok || step_fail "copy_file returned an error"

# ── MCP 10: read_file (verify copy) ────────────────────────────────────────
sep "MCP 10 — read_file $COPY_FILE (verify copy content)"
mcp_tool 19 "read_file" "{\"path\":\"$COPY_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "copied file content mismatch or error"

# ── MCP 11: move_file ──────────────────────────────────────────────────────
MOVED_FILE="$TEST_DIR/hello-moved-$TIMESTAMP.txt"
sep "MCP 11 — move_file $COPY_FILE → $MOVED_FILE"
mcp_tool 20 "move_file" "{\"source\":\"$COPY_FILE\",\"destination\":\"$MOVED_FILE\"}"
check_ok && step_ok || step_fail "move_file returned an error"

# ── MCP 12: read_file (verify move) ────────────────────────────────────────
sep "MCP 12 — read_file $MOVED_FILE (verify moved file)"
mcp_tool 21 "read_file" "{\"path\":\"$MOVED_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "moved file content mismatch or error"

# ── MCP 13: create_share (public link) ─────────────────────────────────────
sep "MCP 13 — create_share (public link on $TEST_FILE)"
mcp_tool 22 "create_share" "{\"path\":\"$TEST_FILE\",\"shareType\":3}"
check_text "Share created" && step_ok || step_fail "create_share returned an error"
SHARE_ID=$(python3 -c "
import json, re
raw = open('$TMPFILE').read().strip()
if 'data:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
d = json.loads(raw)
text = ''.join(c.get('text','') for c in d.get('result',{}).get('content',[]))
m = re.search(r'ID: (\d+)', text)
print(m.group(1) if m else '')
")
echo "  share_id: $SHARE_ID"

# ── MCP 14: list_shares ────────────────────────────────────────────────────
sep "MCP 14 — list_shares (verify share on $TEST_FILE)"
mcp_tool 23 "list_shares" "{\"path\":\"$TEST_FILE\"}"
check_text "$TEST_FILE" && step_ok || step_fail "list_shares: test file share not found"

# ── MCP 15: update_share ───────────────────────────────────────────────────
sep "MCP 15 — update_share (add expiration to share $SHARE_ID)"
mcp_tool 24 "update_share" "{\"shareId\":$SHARE_ID,\"expireDate\":\"2099-12-31\"}"
check_text "updated" && step_ok || step_fail "update_share returned an error"

# ── MCP 16: delete_share ───────────────────────────────────────────────────
sep "MCP 16 — delete_share $SHARE_ID"
mcp_tool 25 "delete_share" "{\"shareId\":$SHARE_ID}"
check_text "deleted successfully" && step_ok || step_fail "delete_share returned an error"

# ── MCP 17: Cleanup — delete files ─────────────────────────────────────────
sep "MCP 17 — Cleanup: delete test files"
mcp_tool 26 "delete" "{\"path\":\"$TEST_FILE\"}"
check_ok && step_ok || step_fail "delete file returned an error"

mcp_tool 27 "delete" "{\"path\":\"$MOVED_FILE\"}"
check_ok && step_ok || step_fail "delete moved file returned an error"

# ── MCP 18: Cleanup — delete folder ────────────────────────────────────────
sep "MCP 18 — Cleanup: delete $TEST_DIR"
mcp_tool 28 "delete" "{\"path\":\"$TEST_DIR\"}"
check_ok && step_ok || step_fail "delete folder returned an error"

# ── Summary ──────────────────────────────────────────────────────────────────
echo ""
echo "══════════════════════════════════════════"
if [ "$FAIL" -eq 0 ]; then
  echo "  All $PASS steps passed"
else
  echo "  $PASS passed, $FAIL failed"
fi
echo "══════════════════════════════════════════"
[ "$FAIL" -eq 0 ]
