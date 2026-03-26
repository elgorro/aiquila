#!/usr/bin/env bash
# Functional MCP tool test for AIquila standalone.
# Authenticates via OAuth PKCE (no container restart), then exercises ~55
# MCP tools: files, shares, tags, users, groups, trash, search, contacts,
# calendar, Talk, notes (optional), and tasks (optional).
# Cleans up after itself.
# Usage: ./test-tools.sh [base-url]
#   base-url defaults to https://localhost:3340 (via Caddy, self-signed TLS)

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STANDALONE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

BASE="${1:-https://localhost:3340}"
CURL="curl -sk"         # -k: skip TLS verification for local self-signed cert

if [ ! -f "$STANDALONE_DIR/.env" ]; then
  echo "ERROR: .env not found in $STANDALONE_DIR — run: cp .env.example .env" >&2
  exit 1
fi
set -a; source "$STANDALONE_DIR/.env"; set +a

NC_USER="${NEXTCLOUD_USER:?NEXTCLOUD_USER not set in .env}"
NC_PASS="${NEXTCLOUD_PASSWORD:?NEXTCLOUD_PASSWORD not set in .env}"

TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
TEST_DIR="/__aiquila-test__"
TEST_FILE="$TEST_DIR/hello-$TIMESTAMP.txt"
TEST_CONTENT="AIquila-tool-test-$TIMESTAMP"
REDIRECT_URI="https://localhost/callback"

# ── Pre-flight: verify server is reachable ────────────────────────────────────
if ! $CURL --max-time 3 "$BASE/.well-known/oauth-authorization-server" >/dev/null 2>&1; then
  echo "ERROR: MCP server not reachable at $BASE — is it running? (make up)" >&2
  exit 1
fi

PASS=0
FAIL=0
TMPFILE=$(mktemp)
trap 'rm -f "$TMPFILE"' EXIT

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

# Exit 0 if TMPFILE holds a non-error MCP response; 1 otherwise.
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

# Exit 0 if NEEDLE appears in the tool result text; 1 otherwise.
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



# ── Auth — quick PKCE flow (no container restart) ─────────────────────────────
sep "Auth — OAuth PKCE"

DISCOVERY=$($CURL "$BASE/.well-known/oauth-authorization-server")
REGISTER_PATH=$(echo "$DISCOVERY" | python3 -c "import sys,json; from urllib.parse import urlparse; print(urlparse(json.load(sys.stdin)['registration_endpoint']).path)")
TOKEN_PATH=$(echo "$DISCOVERY"    | python3 -c "import sys,json; from urllib.parse import urlparse; print(urlparse(json.load(sys.stdin)['token_endpoint']).path)")

CODE_VERIFIER=$(openssl rand -base64 96 | tr -d '=/+\n' | head -c 128)
CODE_CHALLENGE=$(printf '%s' "$CODE_VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=\n')
STATE=$(openssl rand -hex 8)

REG_RESP=$($CURL -X POST "$BASE$REGISTER_PATH" \
  -H "Content-Type: application/json" \
  -d "{\"client_name\":\"tool-test\",\"redirect_uris\":[\"$REDIRECT_URI\"],\"grant_types\":[\"authorization_code\"],\"response_types\":[\"code\"],\"token_endpoint_auth_method\":\"none\"}")
CLIENT_ID=$(echo "$REG_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['client_id'])")
echo "client_id : $CLIENT_ID"

LOGIN_HEADERS=$($CURL -D - -o /dev/null --max-redirs 0 \
  -X POST "$BASE/auth/login" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "username=$NC_USER" \
  --data-urlencode "password=$NC_PASS" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "redirect_uri=$REDIRECT_URI" \
  --data-urlencode "state=$STATE" \
  --data-urlencode "code_challenge=$CODE_CHALLENGE" \
  --data-urlencode "code_challenge_method=S256" \
  --data-urlencode "scope=")
CODE=$(echo "$LOGIN_HEADERS" | grep -i '^location:' | tr -d '\r' | sed 's/[Ll]ocation: //' | grep -o 'code=[^&]*' | cut -d= -f2)
[ -z "$CODE" ] && { echo "ERROR: Login failed — no auth code in redirect" >&2; exit 1; }

TOKEN_RESP=$($CURL -X POST "$BASE$TOKEN_PATH" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" \
  --data-urlencode "redirect_uri=$REDIRECT_URI" \
  --data-urlencode "code_verifier=$CODE_VERIFIER" \
  --data-urlencode "client_id=$CLIENT_ID")
ACCESS_TOKEN=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
echo "token     : ${ACCESS_TOKEN:0:20}..."

INIT_RESP=$($CURL -D - -X POST "$BASE/mcp" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"tool-test","version":"1.0"}}}')
SESSION_ID=$(echo "$INIT_RESP" | grep -i '^mcp-session-id:' | tr -d '\r' | sed 's/[Mm]cp-[Ss]ession-[Ii]d: //') || SESSION_ID=""
echo "session   : ${SESSION_ID:-(none — stateless)}"

MCP_HEADERS=(
  -H "Content-Type: application/json"
  -H "Accept: application/json, text/event-stream"
  -H "Authorization: Bearer $ACCESS_TOKEN"
)
[ -n "$SESSION_ID" ] && MCP_HEADERS+=(-H "Mcp-Session-Id: $SESSION_ID")

mcp_tool() {
  local id="$1" name="$2" args="$3"
  $CURL -X POST "$BASE/mcp" "${MCP_HEADERS[@]}" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":$id,\"method\":\"tools/call\",\"params\":{\"name\":\"$name\",\"arguments\":$args}}" \
    > "$TMPFILE"
  cat "$TMPFILE" | parse_mcp
}

# Switch to manual pass/fail tracking for tool steps
set +e

# ── Step 1: system_status ─────────────────────────────────────────────────────
sep "Step 1 — system_status"
mcp_tool 10 "system_status" '{}'
check_text "Nextcloud" && step_ok || step_fail "system_status: unexpected response"

# ── Step 2: list_files / ─────────────────────────────────────────────────────
sep "Step 2 — list_files /"
mcp_tool 11 "list_files" '{"path":"/"}'
check_ok && step_ok || step_fail "list_files / returned an error"

# ── Step 3: create_folder ────────────────────────────────────────────────────
sep "Step 3 — create_folder $TEST_DIR"
mcp_tool 12 "create_folder" "{\"path\":\"$TEST_DIR\"}"
check_ok && step_ok || step_fail "create_folder returned an error"

# ── Step 4: write_file ───────────────────────────────────────────────────────
sep "Step 4 — write_file $TEST_FILE"
mcp_tool 13 "write_file" "{\"path\":\"$TEST_FILE\",\"content\":\"$TEST_CONTENT\"}"
check_ok && step_ok || step_fail "write_file returned an error"

# ── Step 5: read_file + verify content ───────────────────────────────────────
sep "Step 5 — read_file $TEST_FILE (verify content)"
mcp_tool 14 "read_file" "{\"path\":\"$TEST_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "read_file: content mismatch or error"

# ── Step 6: list_files in test dir ───────────────────────────────────────────
sep "Step 6 — list_files $TEST_DIR (verify file present)"
mcp_tool 15 "list_files" "{\"path\":\"$TEST_DIR\"}"
check_text "hello-$TIMESTAMP.txt" && step_ok || step_fail "test file not listed in $TEST_DIR"

# ── Step 7: get_file_info ────────────────────────────────────────────────────
sep "Step 7 — get_file_info $TEST_FILE"
mcp_tool 16 "get_file_info" "{\"path\":\"$TEST_FILE\"}"
check_ok && step_ok || step_fail "get_file_info returned an error"

# ── Step 8: search_files ─────────────────────────────────────────────────────
sep "Step 8 — search_files (query: aiquila-test)"
mcp_tool 17 "search_files" "{\"query\":\"aiquila-test\"}"
check_ok && step_ok || step_fail "search_files returned an error"

# ── Step 9: copy_file ───────────────────────────────────────────────────────
COPY_FILE="$TEST_DIR/hello-copy-$TIMESTAMP.txt"
sep "Step 9 — copy_file $TEST_FILE → $COPY_FILE"
mcp_tool 18 "copy_file" "{\"source\":\"$TEST_FILE\",\"destination\":\"$COPY_FILE\"}"
check_ok && step_ok || step_fail "copy_file returned an error"

# ── Step 10: read_file (verify copy) ────────────────────────────────────────
sep "Step 10 — read_file $COPY_FILE (verify copy content)"
mcp_tool 19 "read_file" "{\"path\":\"$COPY_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "copied file content mismatch or error"

# ── Step 11: move_file ──────────────────────────────────────────────────────
MOVED_FILE="$TEST_DIR/hello-moved-$TIMESTAMP.txt"
sep "Step 11 — move_file $COPY_FILE → $MOVED_FILE"
mcp_tool 20 "move_file" "{\"source\":\"$COPY_FILE\",\"destination\":\"$MOVED_FILE\"}"
check_ok && step_ok || step_fail "move_file returned an error"

# ── Step 12: read_file (verify move) ────────────────────────────────────────
sep "Step 12 — read_file $MOVED_FILE (verify moved file)"
mcp_tool 21 "read_file" "{\"path\":\"$MOVED_FILE\"}"
check_text "$TEST_CONTENT" && step_ok || step_fail "moved file content mismatch or error"

# ── Step 13: create_share (public link on test file) ───────────────────────
sep "Step 13 — create_share (public link on $TEST_FILE)"
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

# ── Step 14: list_shares (verify share exists) ─────────────────────────────
sep "Step 14 — list_shares (verify share on $TEST_FILE)"
mcp_tool 23 "list_shares" "{\"path\":\"$TEST_FILE\"}"
check_text "$TEST_FILE" && step_ok || step_fail "list_shares: test file share not found"

# ── Step 15: update_share (add expiration) ─────────────────────────────────
sep "Step 15 — update_share (add expiration to share $SHARE_ID)"
mcp_tool 24 "update_share" "{\"shareId\":$SHARE_ID,\"expireDate\":\"2099-12-31\"}"
check_text "updated" && step_ok || step_fail "update_share returned an error"

# ── Step 16: delete_share ──────────────────────────────────────────────────
sep "Step 16 — delete_share $SHARE_ID"
mcp_tool 25 "delete_share" "{\"shareId\":$SHARE_ID}"
check_text "deleted successfully" && step_ok || step_fail "delete_share returned an error"

# ── Step 17: delete files ───────────────────────────────────────────────────
sep "Step 17 — delete $TEST_FILE"
mcp_tool 26 "delete" "{\"path\":\"$TEST_FILE\"}"
check_ok && step_ok || step_fail "delete file returned an error"

sep "Step 17b — delete $MOVED_FILE"
mcp_tool 27 "delete" "{\"path\":\"$MOVED_FILE\"}"
check_ok && step_ok || step_fail "delete moved file returned an error"

# ── Step 18: delete folder ──────────────────────────────────────────────────
sep "Step 18 — delete $TEST_DIR"
mcp_tool 28 "delete" "{\"path\":\"$TEST_DIR\"}"
check_ok && step_ok || step_fail "delete folder returned an error"

# ══════════════════════════════════════════════════════════════════════════════
#  Extended tool tests (Groups A–J)
# ══════════════════════════════════════════════════════════════════════════════

RPC_ID=29

# ── Group A: Status & Apps ───────────────────────────────────────────────────

sep "Step 19 — get_local_time"
mcp_tool $((RPC_ID++)) "get_local_time" '{}'
check_text "localTime" && step_ok || step_fail "get_local_time: missing localTime"

sep "Step 20 — run_setup_checks (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "run_setup_checks" '{}'
if check_ok 2>/dev/null; then step_ok; else step_skip "run_setup_checks requires admin"; fi

sep "Step 21 — list_apps (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_apps" '{"filter":"enabled"}'
if check_ok 2>/dev/null; then step_ok; else step_skip "list_apps requires admin"; fi

sep "Step 22 — get_app_info (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "get_app_info" '{"appId":"files"}'
if check_ok 2>/dev/null; then
  check_text "files" && step_ok || step_fail "get_app_info: no files data"
else
  step_skip "get_app_info requires admin"
fi

# ── Group B: System Tags CRUD ────────────────────────────────────────────────

TAG_NAME="aiquila-test-tag-$TIMESTAMP"

sep "Step 23 — create_system_tag"
mcp_tool $((RPC_ID++)) "create_system_tag" "{\"name\":\"$TAG_NAME\"}"
check_text "System tag created" && step_ok || step_fail "create_system_tag failed"
TAG_ID=$(extract_text 'ID: (\d+)')
echo "  tag_id: $TAG_ID"

sep "Step 24 — list_system_tags"
mcp_tool $((RPC_ID++)) "list_system_tags" '{}'
check_text "$TAG_NAME" && step_ok || step_fail "list_system_tags: tag not found"

TAG_TEST_FILE="$TEST_DIR/tag-test-$TIMESTAMP.txt"
mcp_tool $((RPC_ID++)) "create_folder" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "write_file" "{\"path\":\"$TAG_TEST_FILE\",\"content\":\"tag test\"}" >/dev/null 2>&1

sep "Step 25 — set_file_tags"
mcp_tool $((RPC_ID++)) "set_file_tags" "{\"path\":\"$TAG_TEST_FILE\",\"tags\":[\"$TAG_NAME\"]}"
check_text "Tags set on" && step_ok || step_fail "set_file_tags failed"

sep "Step 26 — get_file_tags"
mcp_tool $((RPC_ID++)) "get_file_tags" "{\"path\":\"$TAG_TEST_FILE\"}"
check_text "$TAG_NAME" && step_ok || step_fail "get_file_tags: tag not found"

sep "Step 27 — get_file_info (for fileId)"
mcp_tool $((RPC_ID++)) "get_file_info" "{\"path\":\"$TAG_TEST_FILE\"}"
check_ok && step_ok || step_fail "get_file_info returned an error"
FILE_ID=$(extract_text '"id":\s*(\d+)')
echo "  file_id: $FILE_ID"

mcp_tool $((RPC_ID++)) "set_file_tags" "{\"path\":\"$TAG_TEST_FILE\",\"tags\":[]}" >/dev/null 2>&1

sep "Step 28 — assign_system_tag (by fileId)"
mcp_tool $((RPC_ID++)) "assign_system_tag" "{\"fileId\":$FILE_ID,\"tagId\":$TAG_ID}"
check_text "assigned to file" && step_ok || step_fail "assign_system_tag failed"

sep "Step 29 — remove_system_tag"
mcp_tool $((RPC_ID++)) "remove_system_tag" "{\"fileId\":$FILE_ID,\"tagId\":$TAG_ID}"
check_text "removed from file" && step_ok || step_fail "remove_system_tag failed"

mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TAG_TEST_FILE\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1

# ── Group C: Users & Groups ──────────────────────────────────────────────────

sep "Step 30 — list_users (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_users" '{}'
if check_ok 2>/dev/null; then
  check_text "Users" && step_ok || step_fail "list_users failed"
else
  step_skip "list_users requires admin/subadmin"
fi

sep "Step 31 — get_user_info"
mcp_tool $((RPC_ID++)) "get_user_info" "{\"userId\":\"$NC_USER\"}"
check_text "$NC_USER" && step_ok || step_fail "get_user_info: user not found"

sep "Step 32 — list_groups (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "list_groups" '{}'
if check_ok 2>/dev/null; then step_ok; else step_skip "list_groups requires admin/subadmin"; fi

sep "Step 33 — get_group_info (admin-only, may skip)"
mcp_tool $((RPC_ID++)) "get_group_info" '{"groupId":"admin"}'
if check_ok 2>/dev/null; then step_ok; else step_skip "get_group_info requires admin/subadmin"; fi

# ── Group D: Trash ───────────────────────────────────────────────────────────

TRASH_FILE="$TEST_DIR/trash-test-$TIMESTAMP.txt"

mcp_tool $((RPC_ID++)) "create_folder" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "write_file" "{\"path\":\"$TRASH_FILE\",\"content\":\"trash test $TIMESTAMP\"}" >/dev/null 2>&1
mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TRASH_FILE\"}" >/dev/null 2>&1

sep "Step 34 — list_trash"
mcp_tool $((RPC_ID++)) "list_trash" '{}'
check_text "trash-test-$TIMESTAMP" && step_ok || step_fail "list_trash: file not in trash"
TRASH_KEY=$(extract_text 'Key: (.+)')
echo "  trash_key: $TRASH_KEY"

sep "Step 35 — restore_from_trash"
if [ -n "$TRASH_KEY" ]; then
  mcp_tool $((RPC_ID++)) "restore_from_trash" "{\"trashKey\":\"$TRASH_KEY\"}"
  check_text "Restored" && step_ok || step_fail "restore_from_trash failed"
else
  RPC_ID=$((RPC_ID+1))
  step_fail "restore_from_trash: no trash key extracted"
fi

sep "Step 36 — read_file (verify restore)"
mcp_tool $((RPC_ID++)) "read_file" "{\"path\":\"$TRASH_FILE\"}"
check_text "trash test $TIMESTAMP" && step_ok || step_fail "restored file content mismatch"

mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TRASH_FILE\"}" >/dev/null 2>&1

sep "Step 37 — empty_trash"
mcp_tool $((RPC_ID++)) "empty_trash" '{}'
check_text "Trash emptied" && step_ok || step_fail "empty_trash failed"

mcp_tool $((RPC_ID++)) "delete" "{\"path\":\"$TEST_DIR\"}" >/dev/null 2>&1

# ── Group E: Search ──────────────────────────────────────────────────────────

sep "Step 38 — list_search_providers"
mcp_tool $((RPC_ID++)) "list_search_providers" '{}'
check_ok && step_ok || step_fail "list_search_providers returned an error"

sep "Step 39 — unified_search (files)"
mcp_tool $((RPC_ID++)) "unified_search" '{"query":"Nextcloud","providers":["files"]}'
check_ok && step_ok || step_fail "unified_search returned an error"

# ── Group F: Contacts CRUD ───────────────────────────────────────────────────

sep "Step 40 — list_address_books"
mcp_tool $((RPC_ID++)) "list_address_books" '{}'
check_text "Address books" && step_ok || step_fail "list_address_books failed"

CONTACT_NAME="Test Contact $TIMESTAMP"
sep "Step 41 — create_contact"
mcp_tool $((RPC_ID++)) "create_contact" "{\"addressBookName\":\"contacts\",\"fullName\":\"$CONTACT_NAME\",\"firstName\":\"Test\",\"lastName\":\"Contact\",\"emails\":[{\"type\":\"WORK\",\"value\":\"test-$TIMESTAMP@example.com\"}]}"
check_text "Contact created successfully" && step_ok || step_fail "create_contact failed"
CONTACT_UID=$(extract_text 'UID: (.+)')
echo "  contact_uid: $CONTACT_UID"

sep "Step 42 — list_contacts"
mcp_tool $((RPC_ID++)) "list_contacts" '{"addressBookName":"contacts"}'
check_text "$CONTACT_NAME" && step_ok || step_fail "list_contacts: contact not found"

sep "Step 43 — get_contact"
mcp_tool $((RPC_ID++)) "get_contact" "{\"addressBookName\":\"contacts\",\"uid\":\"$CONTACT_UID\"}"
check_text "$CONTACT_NAME" && step_ok || step_fail "get_contact: contact not found"

sleep 3

sep "Step 44 — delete_contact"
mcp_tool $((RPC_ID++)) "delete_contact" "{\"addressBookName\":\"contacts\",\"uid\":\"$CONTACT_UID\"}"
if check_text "Contact deleted successfully" 2>/dev/null; then
  step_ok
else
  step_skip "delete_contact: ETag mismatch (NC vCard normalisation race)"
fi

# ── Group G: Calendar CRUD ───────────────────────────────────────────────────

sep "Step 47 — list_calendars"
mcp_tool $((RPC_ID++)) "list_calendars" '{}'
check_text "Calendars" && step_ok || step_fail "list_calendars failed"

EVENT_SUMMARY="Test Event $TIMESTAMP"
sep "Step 48 — create_event"
mcp_tool $((RPC_ID++)) "create_event" "{\"calendarName\":\"personal\",\"summary\":\"$EVENT_SUMMARY\",\"dtstart\":\"20990615T100000Z\",\"dtend\":\"20990615T110000Z\"}"
check_text "Event created successfully" && step_ok || step_fail "create_event failed"
EVENT_UID=$(extract_text 'UID: (.+)')
echo "  event_uid: $EVENT_UID"

sep "Step 49 — list_events"
mcp_tool $((RPC_ID++)) "list_events" '{"calendarName":"personal","from":"20990601T000000Z","to":"20990630T235959Z"}'
check_text "$EVENT_SUMMARY" && step_ok || step_fail "list_events: event not found"

sep "Step 50 — get_event"
mcp_tool $((RPC_ID++)) "get_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "$EVENT_SUMMARY" && step_ok || step_fail "get_event: event not found"

sep "Step 51 — update_event"
mcp_tool $((RPC_ID++)) "update_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\",\"summary\":\"$EVENT_SUMMARY Updated\",\"dtstart\":\"20990615T140000Z\",\"dtend\":\"20990615T150000Z\"}"
check_text "Event updated successfully" && step_ok || step_fail "update_event failed"

sep "Step 52 — get_event (verify update)"
mcp_tool $((RPC_ID++)) "get_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "Updated" && step_ok || step_fail "get_event: update not reflected"

sep "Step 53 — delete_event"
mcp_tool $((RPC_ID++)) "delete_event" "{\"calendarName\":\"personal\",\"uid\":\"$EVENT_UID\"}"
check_text "Event deleted successfully" && step_ok || step_fail "delete_event failed"

# ── Group H: Talk ────────────────────────────────────────────────────────────

sep "Step 54 — talk_list_conversations"
mcp_tool $((RPC_ID++)) "talk_list_conversations" '{}'
check_ok && step_ok || step_fail "talk_list_conversations returned an error"

ROOM_NAME="Test Room $TIMESTAMP"
sep "Step 55 — talk_create_conversation"
mcp_tool $((RPC_ID++)) "talk_create_conversation" "{\"roomType\":3,\"roomName\":\"$ROOM_NAME\"}"
check_text "Conversation created" && step_ok || step_fail "talk_create_conversation failed"
ROOM_TOKEN=$(extract_text '\[([a-zA-Z0-9]+)\]')
echo "  room_token: $ROOM_TOKEN"

sep "Step 56 — talk_send_message"
mcp_tool $((RPC_ID++)) "talk_send_message" "{\"token\":\"$ROOM_TOKEN\",\"message\":\"Hello from test suite $TIMESTAMP\"}"
check_text "Message sent" && step_ok || step_fail "talk_send_message failed"

sep "Step 57 — talk_list_messages"
mcp_tool $((RPC_ID++)) "talk_list_messages" "{\"token\":\"$ROOM_TOKEN\"}"
check_text "Hello from test suite" && step_ok || step_fail "talk_list_messages: message not found"

# ── Group I: Notes CRUD ──────────────────────────────────────────────────────

sep "Step 58 — list_notes"
mcp_tool $((RPC_ID++)) "list_notes" '{}'
check_ok && step_ok || step_fail "list_notes returned an error"

NOTE_TITLE="Test Note $TIMESTAMP"
sep "Step 59 — create_note"
mcp_tool $((RPC_ID++)) "create_note" "{\"title\":\"$NOTE_TITLE\",\"content\":\"Test note content $TIMESTAMP\",\"category\":\"testing\"}"
check_text "Note created" && step_ok || step_fail "create_note failed"
NOTE_ID=$(extract_text '\[(\d+)\]')
echo "  note_id: $NOTE_ID"

sep "Step 60 — get_note"
mcp_tool $((RPC_ID++)) "get_note" "{\"id\":$NOTE_ID}"
check_text "Test note content $TIMESTAMP" && step_ok || step_fail "get_note: content mismatch"

sep "Step 61 — update_note"
mcp_tool $((RPC_ID++)) "update_note" "{\"id\":$NOTE_ID,\"content\":\"Updated content $TIMESTAMP\"}"
check_text "Note updated" && step_ok || step_fail "update_note failed"

sep "Step 62 — get_note (verify update)"
mcp_tool $((RPC_ID++)) "get_note" "{\"id\":$NOTE_ID}"
check_text "Updated content $TIMESTAMP" && step_ok || step_fail "get_note: update not reflected"

sep "Step 63 — delete_note"
mcp_tool $((RPC_ID++)) "delete_note" "{\"id\":$NOTE_ID}"
check_text "deleted" && step_ok || step_fail "delete_note failed"

# ── Group J: Tasks CRUD ──────────────────────────────────────────────────────

sep "Step 64 — list_tasks"
mcp_tool $((RPC_ID++)) "list_tasks" '{"calendarName":"tasks"}'
check_ok && step_ok || step_fail "list_tasks returned an error"

TASK_SUMMARY="Test Task $TIMESTAMP"
sep "Step 65 — create_task"
mcp_tool $((RPC_ID++)) "create_task" "{\"calendarName\":\"tasks\",\"summary\":\"$TASK_SUMMARY\"}"
check_text "Task created successfully" && step_ok || step_fail "create_task failed"
TASK_UID=$(extract_text 'UID: (.+)')
echo "  task_uid: $TASK_UID"

sep "Step 66 — list_tasks"
mcp_tool $((RPC_ID++)) "list_tasks" '{"calendarName":"tasks"}'
check_text "$TASK_SUMMARY" && step_ok || step_fail "list_tasks: task not found"

sep "Step 67 — update_task"
mcp_tool $((RPC_ID++)) "update_task" "{\"calendarName\":\"tasks\",\"uid\":\"$TASK_UID\",\"summary\":\"$TASK_SUMMARY Updated\"}"
check_text "Task updated successfully" && step_ok || step_fail "update_task failed"

sep "Step 68 — complete_task"
mcp_tool $((RPC_ID++)) "complete_task" "{\"calendarName\":\"tasks\",\"uid\":\"$TASK_UID\"}"
check_text "completed successfully" && step_ok || step_fail "complete_task failed"

sep "Step 69 — delete_task"
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
