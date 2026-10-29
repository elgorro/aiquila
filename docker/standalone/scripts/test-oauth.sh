#!/usr/bin/env bash
# Full OAuth 2.1 flow test for AIquila MCP server, acting as an OAuth 2.1
# client (draft-ietf-oauth-v2-1): PKCE S256, a resource indicator (RFC 8707),
# no redirect_uri at the token endpoint (§10.2), then refresh rotation,
# reuse detection (RFC 9700 §4.14.2) and revocation (RFC 7009).
# Reads credentials from .env in the same directory.
# Usage: ./test-oauth.sh [base-url]
#   base-url defaults to https://localhost:3340 (via Caddy, self-signed cert)

set -euo pipefail
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
ISSUER=$(echo "$DISCOVERY"        | python3 -c "import sys,json; print(json.load(sys.stdin)['issuer'])")
REVOKE_PATH=$(echo "$DISCOVERY"   | python3 -c "import sys,json; from urllib.parse import urlparse; print(urlparse(json.load(sys.stdin)['revocation_endpoint']).path)")
# The resource indicator is the protected resource's own identifier (RFC 9728 §3.2).
RESOURCE=$($CURL "$BASE/.well-known/oauth-protected-resource/mcp" | python3 -c "import sys,json; print(json.load(sys.stdin)['resource'])")
echo "resource      : $RESOURCE"
echo "register path : $REGISTER_PATH"
echo "token path    : $TOKEN_PATH"
echo "issuer        : $ISSUER"

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
# The login form carries the authorization request back with a MAC, so it has
# to come from /authorize; its hidden fields are posted back unchanged.
AUTHZ_HTML=$($CURL -G "$BASE/authorize" \
  --data-urlencode "response_type=code" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "redirect_uri=$REDIRECT_URI" \
  --data-urlencode "state=$STATE" \
  --data-urlencode "code_challenge=$CODE_CHALLENGE" \
  --data-urlencode "code_challenge_method=S256" \
  --data-urlencode "resource=$RESOURCE")
FORM_FIELDS=()
while IFS= read -r field; do FORM_FIELDS+=(--data-urlencode "$field"); done < <(
  echo "$AUTHZ_HTML" | python3 -c "
import sys, re, html
for name, value in re.findall(r'<input type=\"hidden\" name=\"([^\"]+)\" value=\"([^\"]*)\"', sys.stdin.read()):
    print(f'{name}={html.unescape(value)}')
")
[ ${#FORM_FIELDS[@]} -eq 0 ] && { echo "ERROR: /authorize did not render the login form" >&2; echo "$AUTHZ_HTML" >&2; exit 1; }

LOGIN_HEADERS=$($CURL -D - -o /dev/null --max-redirs 0 \
  -X POST "$BASE/auth/login" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "username=$NC_USER" \
  --data-urlencode "password=$NC_PASS" \
  "${FORM_FIELDS[@]}")

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

# The metadata advertises authorization_response_iss_parameter_supported, so the
# redirect must carry iss equal to the metadata issuer (RFC 9207 §2, §2.4).
ISS=$(python3 -c "import sys; from urllib.parse import urlparse, parse_qs; print(parse_qs(urlparse(sys.argv[1]).query).get('iss', [''])[0])" "$LOCATION")
if [ "$ISS" != "$ISSUER" ]; then
  echo "ERROR: redirect iss '$ISS' does not match metadata issuer '$ISSUER'" >&2
  exit 1
fi
echo "iss      : $ISS"

# ── Step 5: Exchange code for token ───────────────────────────────────────────
sep "Step 5 — POST $TOKEN_PATH (code → access_token)"
# OAuth 2.1 §10.2: no redirect_uri here — PKCE binds the code to this client.
TOKEN_RESP=$($CURL -X POST "$BASE$TOKEN_PATH" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" \
  --data-urlencode "code_verifier=$CODE_VERIFIER" \
  --data-urlencode "client_id=$CLIENT_ID" \
  --data-urlencode "resource=$RESOURCE")
echo "$TOKEN_RESP" | python3 -m json.tool
ACCESS_TOKEN=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
REFRESH_TOKEN=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['refresh_token'])")
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
# The server is stateless and normally sends no session ID; grep's no-match
# exit status must not trip `set -o pipefail`.
SESSION_ID=$(echo "$INIT_RESP" | { grep -i '^mcp-session-id:' || true; } | tr -d '\r' | sed 's/[Mm]cp-[Ss]ession-[Ii]d: //')
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

# ── Step 8: MCP 2026-07-28 — no handshake, version on every request ───────────
# The same endpoint serves the per-request era: each request carries its version
# in _meta and mirrors it (plus the method) into headers. No session.
MODERN_META='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"curl-test","version":"1.0"},"io.modelcontextprotocol/clientCapabilities":{}}'
MODERN_HEADERS=(-H "Content-Type: application/json" -H "Accept: application/json, text/event-stream"
  -H "Authorization: Bearer $ACCESS_TOKEN" -H "MCP-Protocol-Version: 2026-07-28")

sep "Step 8 — MCP server/discover (2026-07-28)"
DISCOVER=$($CURL -X POST "$BASE/mcp" "${MODERN_HEADERS[@]}" -H "Mcp-Method: server/discover" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"server/discover\",\"params\":{$MODERN_META}}" | parse_mcp)
echo "$DISCOVER"
echo "$DISCOVER" | python3 -c "import sys,json; r=json.load(sys.stdin)['result']; assert '2026-07-28' in r['supportedVersions'], r"

sep "Step 9 — MCP tools/list (2026-07-28)"
$CURL -X POST "$BASE/mcp" "${MODERN_HEADERS[@]}" -H "Mcp-Method: tools/list" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/list\",\"params\":{$MODERN_META}}" \
  | parse_mcp | python3 -c "import sys,json; r=json.load(sys.stdin)['result']; print(f\"{len(r['tools'])} tools, cacheScope={r['cacheScope']}\")"

# ── Steps 10–13: refresh rotation, revocation, reuse detection ────────────────
refresh() {
  $CURL -X POST "$BASE$TOKEN_PATH" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    --data-urlencode "grant_type=refresh_token" \
    --data-urlencode "refresh_token=$1" \
    --data-urlencode "client_id=$CLIENT_ID"
}
# HTTP status of an MCP tools/list call made with the given access token
mcp_status() {
  $CURL -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" \
    -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
    -H "Authorization: Bearer $1" \
    -d '{"jsonrpc":"2.0","id":9,"method":"tools/list","params":{}}'
}
fail() { echo "ERROR: $*" >&2; exit 1; }

sep "Step 10 — refresh (rotation)"
REFRESH_RESP=$(refresh "$REFRESH_TOKEN")
ACCESS_TOKEN_2=$(echo "$REFRESH_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])") \
  || fail "refresh failed: $REFRESH_RESP"
REFRESH_TOKEN_2=$(echo "$REFRESH_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['refresh_token'])")
[ "$REFRESH_TOKEN_2" != "$REFRESH_TOKEN" ] || fail "refresh token was not rotated"
[ "$(mcp_status "$ACCESS_TOKEN_2")" = 200 ] || fail "refreshed access token rejected"
echo "rotated; new access token works"

sep "Step 11 — revoke an access token (RFC 7009)"
$CURL -o /dev/null -X POST "$BASE$REVOKE_PATH" -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "token=$ACCESS_TOKEN" --data-urlencode "client_id=$CLIENT_ID"
[ "$(mcp_status "$ACCESS_TOKEN")" = 401 ] || fail "revoked access token still accepted"
[ "$(mcp_status "$ACCESS_TOKEN_2")" = 200 ] || fail "revoking one access token affected another"
echo "revoked token → 401; the other access token still works"

sep "Step 12 — reuse the rotated refresh token (RFC 9700 §4.14.2)"
REUSE_ERR=$(refresh "$REFRESH_TOKEN" | python3 -c "import sys,json; print(json.load(sys.stdin).get('error',''))")
[ "$REUSE_ERR" = invalid_grant ] || fail "reused refresh token: expected invalid_grant, got '$REUSE_ERR'"
echo "reuse → invalid_grant"

sep "Step 13 — the whole grant is gone"
LATEST_ERR=$(refresh "$REFRESH_TOKEN_2" | python3 -c "import sys,json; print(json.load(sys.stdin).get('error',''))")
[ "$LATEST_ERR" = invalid_grant ] || fail "current refresh token survived reuse detection"
[ "$(mcp_status "$ACCESS_TOKEN_2")" = 401 ] || fail "access token of the revoked grant still accepted"
echo "current refresh token → invalid_grant; grant's access token → 401"

sep "All steps passed"
echo "The tokens from this run were revoked by steps 11–13; run it again for a fresh grant."
