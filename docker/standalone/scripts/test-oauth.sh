#!/usr/bin/env bash
# Full OAuth 2.0 PKCE flow test for AIquila MCP server.
# Reads credentials from .env in the same directory.
# Usage: ./test-oauth.sh [base-url]
#   base-url defaults to https://localhost:3340 (via Caddy, self-signed cert)

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STANDALONE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# ── Config ────────────────────────────────────────────────────────────────────
BASE="${1:-https://localhost:3340}"
CURL="curl -sk"         # -k: skip TLS verification for local self-signed cert

# Load .env from standalone dir
if [ ! -f "$STANDALONE_DIR/.env" ]; then
  echo "ERROR: .env not found in $STANDALONE_DIR — run: cp .env.example .env" >&2
  exit 1
fi
set -a; source "$STANDALONE_DIR/.env"; set +a

NC_USER="${NEXTCLOUD_USER:?NEXTCLOUD_USER not set in .env}"
NC_PASS="${NEXTCLOUD_PASSWORD:?NEXTCLOUD_PASSWORD not set in .env}"

REDIRECT_URI="https://localhost/callback"  # dummy — we just capture the redirect

sep() { echo ""; echo "──────────────────────────────────────────"; echo "  $*"; echo "──────────────────────────────────────────"; }

# ── Step 0: Restart MCP container ─────────────────────────────────────────────
sep "Step 0 — Restart MCP container for clean state"
docker compose -f "$STANDALONE_DIR/docker-compose.yml" restart mcp-server
echo -n "Waiting for MCP server to be ready..."
for i in $(seq 1 20); do
  if curl -skf "$BASE/.well-known/oauth-authorization-server" >/dev/null 2>&1; then
    echo " ready."
    break
  fi
  echo -n "."
  sleep 1
done

# ── Step 1: Discovery ─────────────────────────────────────────────────────────
sep "Step 1 — OAuth discovery"
DISCOVERY=$($CURL "$BASE/.well-known/oauth-authorization-server")
echo "$DISCOVERY" | python3 -m json.tool

# Extract endpoints from discovery (strip host, use BASE for direct HTTP access)
REGISTER_PATH=$(echo "$DISCOVERY" | python3 -c "import sys,json; from urllib.parse import urlparse; print(urlparse(json.load(sys.stdin)['registration_endpoint']).path)")
TOKEN_PATH=$(echo "$DISCOVERY"    | python3 -c "import sys,json; from urllib.parse import urlparse; print(urlparse(json.load(sys.stdin)['token_endpoint']).path)")
echo "register path : $REGISTER_PATH"
echo "token path    : $TOKEN_PATH"

# ── Step 2: Generate PKCE ─────────────────────────────────────────────────────
sep "Step 2 — Generate PKCE + state"
CODE_VERIFIER=$(openssl rand -base64 96 | tr -d '=/+\n' | head -c 128)
CODE_CHALLENGE=$(printf '%s' "$CODE_VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=\n')
STATE=$(openssl rand -hex 8)
echo "code_verifier  : ${CODE_VERIFIER:0:20}..."
echo "code_challenge : $CODE_CHALLENGE"
echo "state          : $STATE"

# ── Step 3: Dynamic client registration ───────────────────────────────────────
sep "Step 3 — Register OAuth client"
REG_RESP=$($CURL -X POST "$BASE$REGISTER_PATH" \
  -H "Content-Type: application/json" \
  -d "{
    \"client_name\": \"curl-test\",
    \"redirect_uris\": [\"$REDIRECT_URI\"],
    \"grant_types\": [\"authorization_code\", \"refresh_token\"],
    \"response_types\": [\"code\"],
    \"token_endpoint_auth_method\": \"none\"
  }")
echo "$REG_RESP" | python3 -m json.tool
CLIENT_ID=$(echo "$REG_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['client_id'])")
echo "client_id: $CLIENT_ID"

# ── Step 4: Login → capture auth code from redirect ───────────────────────────
sep "Step 4 — POST /auth/login (Nextcloud credentials → auth code)"
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

LOCATION=$(echo "$LOGIN_HEADERS" | grep -i '^location:' | tr -d '\r' | sed 's/[Ll]ocation: //')
echo "Redirect location: $LOCATION"

CODE=$(echo "$LOCATION" | grep -o 'code=[^&]*' | cut -d= -f2)
if [ -z "$CODE" ]; then
  echo ""
  echo "ERROR: No auth code in redirect — login failed." >&2
  echo "Full response headers:" >&2
  echo "$LOGIN_HEADERS" >&2
  exit 1
fi
echo "auth code: ${CODE:0:12}..."

# ── Step 5: Exchange code for token ───────────────────────────────────────────
sep "Step 5 — POST $TOKEN_PATH (code → access_token)"
TOKEN_RESP=$($CURL -X POST "$BASE$TOKEN_PATH" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" \
  --data-urlencode "redirect_uri=$REDIRECT_URI" \
  --data-urlencode "code_verifier=$CODE_VERIFIER" \
  --data-urlencode "client_id=$CLIENT_ID")
echo "$TOKEN_RESP" | python3 -m json.tool
ACCESS_TOKEN=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
echo "access_token: ${ACCESS_TOKEN:0:20}..."

MCP_HEADERS=(-H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -H "Authorization: Bearer $ACCESS_TOKEN")

# Parse JSON whether it came back as plain JSON or as an SSE stream (data: {...}\n\n)
parse_mcp() {
  python3 -c "
import sys, json
raw = sys.stdin.read().strip()
# SSE: extract last 'data:' line
if raw.startswith('data:') or '\ndata:' in raw:
    lines = [l[5:].strip() for l in raw.splitlines() if l.startswith('data:')]
    raw = lines[-1] if lines else raw
json.dump(json.loads(raw), sys.stdout, indent=4)
print()
"
}

# ── Step 6: MCP — initialize (capture session ID) ─────────────────────────────
sep "Step 6 — MCP initialize"
INIT_RESP=$($CURL -D - -X POST "$BASE/mcp" "${MCP_HEADERS[@]}" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl-test","version":"1.0"}}}')
SESSION_ID=$(echo "$INIT_RESP" | grep -i '^mcp-session-id:' | tr -d '\r' | sed 's/[Mm]cp-[Ss]ession-[Ii]d: //')
INIT_BODY=$(echo "$INIT_RESP" | sed '1,/^\r$/d')
echo "$INIT_BODY" | parse_mcp
if [ -n "$SESSION_ID" ]; then
  echo "session-id: $SESSION_ID"
  MCP_HEADERS+=(-H "Mcp-Session-Id: $SESSION_ID")
fi

# ── Step 7: MCP — list tools ──────────────────────────────────────────────────
sep "Step 7 — MCP tools/list"
$CURL -X POST "$BASE/mcp" "${MCP_HEADERS[@]}" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | parse_mcp

sep "All steps passed"
echo "Re-use token for more calls:"
echo "  export MCP_TOKEN=$ACCESS_TOKEN"
echo "  curl -s -X POST $BASE/mcp -H 'Content-Type: application/json' -H \"Authorization: Bearer \$MCP_TOKEN\" -d '{...}' | python3 -m json.tool"
