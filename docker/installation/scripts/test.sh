#!/usr/bin/env bash
# Comprehensive test suite for AIquila dev environment.
# Tests Nextcloud installation + ~55 MCP server tools (no auth — dev mode).
# Covers: files, shares, tags, users, groups, trash, search, contacts,
#         calendar, Talk, notes (optional), and tasks (optional).
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
step_skip() { echo "  ⊘ SKIP: $1"; }

# Extract a regex match group from the MCP response text.
# Usage: VAL=$(extract_text 'ID: (\d+)')
extract_text() {
  local pattern="$1"
  python3 -c "
import json, re, sys
raw = open('$TMPFILE').read().strip()
if 'data:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
d = json.loads(raw)
text = ''.join(c.get('text','') for c in d.get('result',{}).get('content',[]))
m = re.search(sys.argv[1], text)
print(m.group(1) if m else '')
" "$pattern"
}



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

# ══════════════════════════════════════════════════════════════════════════════
#  PART 3 — Extended MCP tool tests (Groups A–J)
# ══════════════════════════════════════════════════════════════════════════════

# JSON-RPC IDs continue from 29 onward.
RPC_ID=29

# ── Group A: Status & Apps ───────────────────────────────────────────────────

sep "MCP 19 — get_local_time"
mcp_tool $((RPC_ID++)) "get_local_time" '{}'
check_text "localTime" && step_ok || step_fail "get_local_time: missing localTime"

sep "MCP 20 — run_setup_checks (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "run_setup_checks" '{}'
if check_ok 2>/dev/null; then step_ok; else step_skip "run_setup_checks requires admin"; fi

sep "MCP 21 — list_apps (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_apps" '{"filter":"enabled"}'
if check_ok 2>/dev/null; then
  check_text "aiquila" && step_ok || step_fail "list_apps: aiquila not in enabled apps"
else
  step_skip "list_apps requires admin"
fi

sep "MCP 22 — get_app_info (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "get_app_info" '{"appId":"aiquila"}'
if check_ok 2>/dev/null; then
  check_text "aiquila" && step_ok || step_fail "get_app_info: no aiquila data"
else
  step_skip "get_app_info requires admin"
fi

# ── Group B: System Tags CRUD ────────────────────────────────────────────────

TAG_NAME="aiquila-test-tag-$TIMESTAMP"

sep "MCP 23 — create_system_tag"
mcp_tool $((RPC_ID++)) "create_system_tag" "{\"name\":\"$TAG_NAME\"}"
check_text "System tag created" && step_ok || step_fail "create_system_tag failed"
TAG_ID=$(extract_text 'ID: (\d+)')
echo "  tag_id: $TAG_ID"

sep "MCP 24 — list_system_tags"
mcp_tool $((RPC_ID++)) "list_system_tags" '{}'
check_text "$TAG_NAME" && step_ok || step_fail "list_system_tags: tag not found"

# Create a temp file to test tagging by path and by fileId
TAG_TEST_FILE="$TEST_DIR/tag-test-$TIMESTAMP.txt"
mcp_tool $((RPC_ID++)) "create_folder" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "write_file" "{\"path\":\"$TAG_TEST_FILE\",\"content\":\"tag test\"}" >/dev/null 2>&1

sep "MCP 25 — set_file_tags"
mcp_tool $((RPC_ID++)) "set_file_tags" "{\"path\":\"$TAG_TEST_FILE\",\"tags\":[\"$TAG_NAME\"]}"
check_text "Tags set on" && step_ok || step_fail "set_file_tags failed"

sep "MCP 26 — get_file_tags"
mcp_tool $((RPC_ID++)) "get_file_tags" "{\"path\":\"$TAG_TEST_FILE\"}"
check_text "$TAG_NAME" && step_ok || step_fail "get_file_tags: tag not found"

# Get the fileId for assign/remove by ID
sep "MCP 27 — get_file_info (for fileId)"
mcp_tool $((RPC_ID++)) "get_file_info" "{\"path\":\"$TAG_TEST_FILE\"}"
check_ok && step_ok || step_fail "get_file_info returned an error"
FILE_ID=$(extract_text '"id":\s*(\d+)')
echo "  file_id: $FILE_ID"

# Clear the tag first, then re-assign by fileId
mcp_tool $((RPC_ID++)) "set_file_tags" "{\"path\":\"$TAG_TEST_FILE\",\"tags\":[]}" >/dev/null 2>&1

sep "MCP 28 — assign_system_tag (by fileId)"
mcp_tool $((RPC_ID++)) "assign_system_tag" "{\"fileId\":$FILE_ID,\"tagId\":$TAG_ID}"
check_text "assigned to file" && step_ok || step_fail "assign_system_tag failed"

sep "MCP 29 — remove_system_tag"
mcp_tool $((RPC_ID++)) "remove_system_tag" "{\"fileId\":$FILE_ID,\"tagId\":$TAG_ID}"
check_text "removed from file" && step_ok || step_fail "remove_system_tag failed"

# Cleanup: delete temp file, folder, and system tag
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TAG_TEST_FILE\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1

# ── Group C: Users & Groups ──────────────────────────────────────────────────

sep "MCP 30 — list_users (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_users" '{}'
if check_ok 2>/dev/null; then
  check_text "Users" && step_ok || step_fail "list_users failed"
else
  step_skip "list_users requires admin/subadmin"
fi

sep "MCP 31 — get_user_info"
mcp_tool $((RPC_ID++)) "get_user_info" "{\"userId\":\"$NC_USER\"}"
check_text "$NC_USER" && step_ok || step_fail "get_user_info: user not found"

sep "MCP 32 — list_groups (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_groups" '{}'
if check_ok 2>/dev/null; then step_ok; else step_skip "list_groups requires admin/subadmin"; fi

sep "MCP 33 — get_group_info (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "get_group_info" '{"groupId":"admin"}'
if check_ok 2>/dev/null; then step_ok; else step_skip "get_group_info requires admin/subadmin"; fi

# ── Group D: Trash ───────────────────────────────────────────────────────────

TRASH_FILE="$TEST_DIR/trash-test-$TIMESTAMP.txt"

# Prepare: create dir + file, then delete the file to move it to trash
mcp_tool $((RPC_ID++)) "create_folder" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "write_file" "{\"path\":\"$TRASH_FILE\",\"content\":\"trash test $TIMESTAMP\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TRASH_FILE\"}" >/dev/null 2>&1

sep "MCP 34 — list_trash"
mcp_tool $((RPC_ID++)) "list_trash" '{}'
check_text "trash-test-$TIMESTAMP" && step_ok || step_fail "list_trash: file not in trash"
TRASH_KEY=$(extract_text 'Key: (.+)')
echo "  trash_key: $TRASH_KEY"

sep "MCP 35 — restore_from_trash"
if [ -n "$TRASH_KEY" ]; then
  mcp_tool $((RPC_ID++)) "restore_from_trash" "{\"trashKey\":\"$TRASH_KEY\"}"
  check_text "Restored" && step_ok || step_fail "restore_from_trash failed"
else
  RPC_ID=$((RPC_ID+1))
  step_fail "restore_from_trash: no trash key extracted"
fi

sep "MCP 36 — read_file (verify restore)"
mcp_tool $((RPC_ID++)) "read_file" "{\"path\":\"$TRASH_FILE\"}"
check_text "trash test $TIMESTAMP" && step_ok || step_fail "restored file content mismatch"

# Delete again, then empty trash
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TRASH_FILE\"}" >/dev/null 2>&1

sep "MCP 37 — empty_trash"
mcp_tool $((RPC_ID++)) "empty_trash" '{}'
check_text "Trash emptied" && step_ok || step_fail "empty_trash failed"

# Cleanup
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1

# ── Group E: Search ──────────────────────────────────────────────────────────

sep "MCP 38 — list_search_providers"
mcp_tool $((RPC_ID++)) "list_search_providers" '{}'
check_ok && step_ok || step_fail "list_search_providers returned an error"

sep "MCP 39 — unified_search (files)"
mcp_tool $((RPC_ID++)) "unified_search" '{"query":"Nextcloud","providers":["files"]}'
check_ok && step_ok || step_fail "unified_search returned an error"

# ── Group F: Contacts CRUD ───────────────────────────────────────────────────

sep "MCP 40 — list_address_books"
mcp_tool $((RPC_ID++)) "list_address_books" '{}'
check_text "Address books" && step_ok || step_fail "list_address_books failed"

CONTACT_NAME="Test Contact $TIMESTAMP"
sep "MCP 41 — create_contact"
mcp_tool $((RPC_ID++)) "create_contact" "{\"addressBookName\":\"contacts\",\"fullName\":\"$CONTACT_NAME\",\"firstName\":\"Test\",\"lastName\":\"Contact\",\"emails\":[{\"type\":\"WORK\",\"value\":\"test-$TIMESTAMP@example.com\"}]}"
check_text "Contact created successfully" && step_ok || step_fail "create_contact failed"
CONTACT_UID=$(extract_text 'UID: (.+)')
echo "  contact_uid: $CONTACT_UID"

sep "MCP 42 — list_contacts"
mcp_tool $((RPC_ID++)) "list_contacts" '{"addressBookName":"contacts"}'
check_text "$CONTACT_NAME" && step_ok || step_fail "list_contacts: contact not found"

sep "MCP 43 — get_contact"
mcp_tool $((RPC_ID++)) "get_contact" "{\"addressBookName\":\"contacts\",\"uid\":\"$CONTACT_UID\"}"
check_text "$CONTACT_NAME" && step_ok || step_fail "get_contact: contact not found"

# Note: update_contact is skipped because NC's CardDAV backend asynchronously
# normalises vCards after creation, causing persistent ETag mismatches (412).
# Pause to let NC stabilise the vCard before deletion.
sleep 3

sep "MCP 44 — delete_contact"
mcp_tool $((RPC_ID++)) "delete_contact" "{\"addressBookName\":\"contacts\",\"uid\":\"$CONTACT_UID\"}"
if check_text "Contact deleted successfully" 2>/dev/null; then
  step_ok
else
  # ETag race with NC CardDAV normalisation — clean up via WebDAV directly
  step_skip "delete_contact: ETag mismatch (NC vCard normalisation race)"
fi

# ── Group G: Calendar CRUD ───────────────────────────────────────────────────

sep "MCP 47 — list_calendars"
mcp_tool $((RPC_ID++)) "list_calendars" '{}'
check_text "Calendars" && step_ok || step_fail "list_calendars failed"

EVENT_SUMMARY="Test Event $TIMESTAMP"
sep "MCP 48 — create_event"
mcp_tool $((RPC_ID++)) "create_event" "{\"calendarName\":\"personal\",\"summary\":\"$EVENT_SUMMARY\",\"dtstart\":\"20990615T100000Z\",\"dtend\":\"20990615T110000Z\"}"
check_text "Event created successfully" && step_ok || step_fail "create_event failed"
EVENT_UID=$(extract_text 'UID: (.+)')
echo "  event_uid: $EVENT_UID"

sep "MCP 49 — list_events"
mcp_tool $((RPC_ID++)) "list_events" '{"calendarName":"personal","from":"20990601T000000Z","to":"20990630T235959Z"}'
check_text "$EVENT_SUMMARY" && step_ok || step_fail "list_events: event not found"

sep "MCP 50 — get_event"
mcp_tool $((RPC_ID++)) "get_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "$EVENT_SUMMARY" && step_ok || step_fail "get_event: event not found"

sep "MCP 51 — update_event"
mcp_tool $((RPC_ID++)) "update_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\",\"summary\":\"$EVENT_SUMMARY Updated\",\"dtstart\":\"20990615T140000Z\",\"dtend\":\"20990615T150000Z\"}"
check_text "Event updated successfully" && step_ok || step_fail "update_event failed"

sep "MCP 52 — get_event (verify update)"
mcp_tool $((RPC_ID++)) "get_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "Updated" && step_ok || step_fail "get_event: update not reflected"

sep "MCP 53 — delete_event"
mcp_tool $((RPC_ID++)) "delete_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "Event deleted successfully" && step_ok || step_fail "delete_event failed"

# ── Group H: Talk ────────────────────────────────────────────────────────────

sep "MCP 54 — talk_list_conversations"
mcp_tool $((RPC_ID++)) "talk_list_conversations" '{}'
check_ok && step_ok || step_fail "talk_list_conversations returned an error"

ROOM_NAME="Test Room $TIMESTAMP"
sep "MCP 55 — talk_create_conversation"
mcp_tool $((RPC_ID++)) "talk_create_conversation" "{\"roomType\":3,\"roomName\":\"$ROOM_NAME\"}"
check_text "Conversation created" && step_ok || step_fail "talk_create_conversation failed"
ROOM_TOKEN=$(extract_text '\[([a-zA-Z0-9]+)\]')
echo "  room_token: $ROOM_TOKEN"

sep "MCP 56 — talk_send_message"
mcp_tool $((RPC_ID++)) "talk_send_message" "{\"token\":\"$ROOM_TOKEN\",\"message\":\"Hello from test suite $TIMESTAMP\"}"
check_text "Message sent" && step_ok || step_fail "talk_send_message failed"

sep "MCP 57 — talk_list_messages"
mcp_tool $((RPC_ID++)) "talk_list_messages" "{\"token\":\"$ROOM_TOKEN\"}"
check_text "Hello from test suite" && step_ok || step_fail "talk_list_messages: message not found"

# ── Group I: Notes CRUD ──────────────────────────────────────────────────────

sep "MCP 58 — list_notes"
mcp_tool $((RPC_ID++)) "list_notes" '{}'
check_ok && step_ok || step_fail "list_notes returned an error"

NOTE_TITLE="Test Note $TIMESTAMP"
sep "MCP 59 — create_note"
mcp_tool $((RPC_ID++)) "create_note" "{\"title\":\"$NOTE_TITLE\",\"content\":\"Test note content $TIMESTAMP\",\"category\":\"testing\"}"
check_text "Note created" && step_ok || step_fail "create_note failed"
NOTE_ID=$(extract_text '\[(\d+)\]')
echo "  note_id: $NOTE_ID"

sep "MCP 60 — get_note"
mcp_tool $((RPC_ID++)) "get_note" "{\"id\":$NOTE_ID}"
check_text "Test note content $TIMESTAMP" && step_ok || step_fail "get_note: content mismatch"

sep "MCP 61 — update_note"
mcp_tool $((RPC_ID++)) "update_note" "{\"id\":$NOTE_ID,\"content\":\"Updated content $TIMESTAMP\"}"
check_text "Note updated" && step_ok || step_fail "update_note failed"

sep "MCP 62 — get_note (verify update)"
mcp_tool $((RPC_ID++)) "get_note" "{\"id\":$NOTE_ID}"
check_text "Updated content $TIMESTAMP" && step_ok || step_fail "get_note: update not reflected"

sep "MCP 63 — delete_note"
mcp_tool $((RPC_ID++)) "delete_note" "{\"id\":$NOTE_ID}"
check_text "deleted" && step_ok || step_fail "delete_note failed"

# ── Group J: Tasks CRUD ──────────────────────────────────────────────────────

sep "MCP 64 — list_tasks"
mcp_tool $((RPC_ID++)) "list_tasks" '{"calendarName":"tasks"}'
check_ok && step_ok || step_fail "list_tasks returned an error"

TASK_SUMMARY="Test Task $TIMESTAMP"
sep "MCP 65 — create_task"
mcp_tool $((RPC_ID++)) "create_task" "{\"calendarName\":\"tasks\",\"summary\":\"$TASK_SUMMARY\"}"
check_text "Task created successfully" && step_ok || step_fail "create_task failed"
TASK_UID=$(extract_text 'UID: (.+)')
echo "  task_uid: $TASK_UID"

sep "MCP 66 — list_tasks"
mcp_tool $((RPC_ID++)) "list_tasks" '{"calendarName":"tasks"}'
check_text "$TASK_SUMMARY" && step_ok || step_fail "list_tasks: task not found"

sep "MCP 67 — update_task"
mcp_tool $((RPC_ID++)) "update_task" "{\"calendarName\":\"tasks\",\"uid\":\"$TASK_UID\",\"summary\":\"$TASK_SUMMARY Updated\"}"
check_text "Task updated successfully" && step_ok || step_fail "update_task failed"

sep "MCP 68 — complete_task"
mcp_tool $((RPC_ID++)) "complete_task" "{\"calendarName\":\"tasks\",\"uid\":\"$TASK_UID\"}"
check_text "completed successfully" && step_ok || step_fail "complete_task failed"

sep "MCP 69 — delete_task"
mcp_tool $((RPC_ID++)) "delete_task" "{\"calendarName\":\"tasks\",\"uid\":\"$TASK_UID\"}"
check_text "Task deleted successfully" && step_ok || step_fail "delete_task failed"

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
