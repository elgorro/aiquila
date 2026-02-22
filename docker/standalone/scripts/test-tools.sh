#!/usr/bin/env bash
# Functional MCP tool test for AIquila standalone.
# Authenticates via OAuth PKCE (no container restart), then exercises core
# Nextcloud tools: system_status, list_files, create_folder, write_file,
# read_file, search_files, get_file_info, delete.  Cleans up after itself.
# Usage: ./test-tools.sh [base-url]
#   base-url defaults to http://localhost:3339 (direct, no TLS)

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STANDALONE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

BASE="${1:-http://localhost:3339}"
CURL="curl -s"

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
SESSION_ID=$(echo "$INIT_RESP" | grep -i '^mcp-session-id:' | tr -d '\r' | sed 's/[Mm]cp-[Ss]ession-[Ii]d: //')
echo "session   : $SESSION_ID"

MCP_HEADERS=(
  -H "Content-Type: application/json"
  -H "Accept: application/json, text/event-stream"
  -H "Authorization: Bearer $ACCESS_TOKEN"
  -H "Mcp-Session-Id: $SESSION_ID"
)

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

# ── Step 9: delete file ──────────────────────────────────────────────────────
sep "Step 9 — delete $TEST_FILE"
mcp_tool 18 "delete" "{\"path\":\"$TEST_FILE\"}"
check_ok && step_ok || step_fail "delete file returned an error"

# ── Step 10: delete folder ───────────────────────────────────────────────────
sep "Step 10 — delete $TEST_DIR"
mcp_tool 19 "delete" "{\"path\":\"$TEST_DIR\"}"
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
