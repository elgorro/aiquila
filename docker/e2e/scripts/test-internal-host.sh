#!/usr/bin/env bash
# End-to-end test of the MCP server reaching Nextcloud by its container name
# (NEXTCLOUD_URL=http://nc) while browsers use NEXTCLOUD_PUBLIC_URL — the
# topology of the Hetzner full stack and most single-host Docker setups.
#
# For a non-admin user and a TOTP user, it drives the real OAuth 2.1 + Login
# Flow v2 sign-in and checks that:
#   - the sign-in link uses NEXTCLOUD_PUBLIC_URL, never the internal host;
#   - polling and the code exchange succeed over the internal URL;
#   - list_files acts as the signed-in user, and list_users (admin-only) is refused;
#   - revoking the refresh token (RFC 7009 §2.1) removes the "AIquila MCP" device
#     in Nextcloud, and the access token then gets 401.
# Finally, with NEXTCLOUD_PUBLIC_URL unset, the server must warn at startup.
#
# Usage: docker compose up -d --build && ./scripts/test-internal-host.sh
# Extra compose files can be passed through COMPOSE_FILE, as with docker compose.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
E2E_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$E2E_DIR"

NC_PORT="${E2E_NC_PORT:-18080}"
MCP_PORT="${E2E_MCP_PORT:-13339}"
PUBLIC="http://localhost:$NC_PORT"
BASE="http://localhost:$MCP_PORT"
INTERNAL="http://nc"
CURL="curl -s"
REDIRECT_URI="http://localhost/callback"   # never followed; the code is read from the redirect

# shellcheck source=../../standalone/scripts/nc-login-flow.sh
source "$E2E_DIR/../standalone/scripts/nc-login-flow.sh"

sep() { echo ""; echo "── $*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
occ() { docker compose exec -T -u www-data nc php occ "$@"; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {}, {'d': d}))" "$1"; }

wait_for() { # URL PATTERN SECONDS
  local i
  for ((i = 0; i < $3; i += 3)); do
    $CURL "$1" 2>/dev/null | grep -q "$2" && return 0
    sleep 3
  done
  fail "$1 did not answer with $2 within $3 s"
}

# ── Setup ─────────────────────────────────────────────────────────────────────
sep "Waiting for Nextcloud and the MCP server"
wait_for "$PUBLIC/status.php" '"installed":true' 600
wait_for "$BASE/health" 'ok' 120

sep "Creating alice (password) and bob (password + TOTP)"
declare -A PASS=([alice]='e2e-Alice-Passw0rd!x' [bob]='e2e-Bob-Passw0rd!x')
declare -A TOTP=([alice]='' [bob]='')
for u in alice bob; do
  if ! occ user:info "$u" >/dev/null 2>&1; then
    docker compose exec -T -u www-data -e NC_PASS="${PASS[$u]}" nc php occ user:add --password-from-env "$u" >/dev/null
  fi
  # A file only this user owns, to tell whose files list_files returns.
  $CURL -f -o /dev/null -u "$u:${PASS[$u]}" -T - "$PUBLIC/remote.php/dav/files/$u/e2e-$u.txt" <<<"$u" \
    || fail "upload of e2e-$u.txt as $u failed"
done
occ app:enable twofactor_totp >/dev/null
# Enrolment goes through the app's own settings route: state 1 creates a
# secret, state 2 confirms it with a current code.
enable_totp() {
  $CURL -u "bob:${PASS[bob]}" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
    -X POST -d "$1" "$PUBLIC/index.php/apps/twofactor_totp/settings/enable"
}
TOTP[bob]=$(enable_totp '{"state":1}' | _nc_json secret)
[ -n "${TOTP[bob]}" ] || fail "TOTP enrolment for bob returned no secret"
[ "$(enable_totp "{\"state\":2,\"code\":\"$(_nc_totp "${TOTP[bob]}")\"}" | _nc_json state)" = 2 ] \
  || fail "TOTP enrolment for bob was not confirmed"
# Nextcloud refuses a TOTP code twice, so the sign-in must wait for a new one.
TOTP_USED_WINDOW=$(($(date +%s) / 30))
[ "$($CURL -o /dev/null -w '%{http_code}' -u "bob:${PASS[bob]}" -X PROPFIND "$PUBLIC/remote.php/dav/files/bob/")" = 401 ] \
  || fail "bob's plain password still works over WebDAV — 2FA is not active"
echo "bob has TOTP; his password alone is refused"

# ── Discovery ─────────────────────────────────────────────────────────────────
DISCOVERY=$($CURL "$BASE/.well-known/oauth-authorization-server")
REGISTER=$(echo "$DISCOVERY" | json 'd["registration_endpoint"]')
TOKEN=$(echo "$DISCOVERY" | json 'd["token_endpoint"]')
REVOKE=$(echo "$DISCOVERY" | json 'd["revocation_endpoint"]')
RESOURCE=$($CURL "$BASE/.well-known/oauth-protected-resource/mcp" | json 'd["resource"]')

META='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"e2e","version":"1.0"},"io.modelcontextprotocol/clientCapabilities":{}}'
# tools/call NAME ARGS_JSON with ACCESS_TOKEN; prints the JSON-RPC response.
call_tool() {
  $CURL -X POST "$BASE/mcp" -H "Authorization: Bearer $ACCESS_TOKEN" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/call' -H "Mcp-Name: $1" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2,$META}}" \
    | python3 -c '
import json, sys
raw = sys.stdin.read().strip()
if raw.startswith("data:") or "\ndata:" in raw:
    raw = [l[5:].strip() for l in raw.splitlines() if l.startswith("data:")][-1]
print(raw)'
}
mcp_status() {
  $CURL -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" -H "Authorization: Bearer $1" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":9,"method":"tools/list","params":{}}'
}
aiquila_devices() { occ user:auth-tokens:list "$1" --output=json | json 'sum(1 for t in d if "AIquila MCP" in t.get("name", ""))'; }

# ── Per user ──────────────────────────────────────────────────────────────────
for u in alice bob; do
  other=$([ "$u" = alice ] && echo bob || echo alice)
  sep "$u: register a client and open /authorize"
  CLIENT_ID=$($CURL -X POST "$REGISTER" -H 'Content-Type: application/json' -d "{
    \"client_name\":\"e2e-$u\",\"redirect_uris\":[\"$REDIRECT_URI\"],
    \"grant_types\":[\"authorization_code\",\"refresh_token\"],\"response_types\":[\"code\"],
    \"token_endpoint_auth_method\":\"none\"}" | json 'd["client_id"]')
  VERIFIER=$(openssl rand -base64 96 | tr -d '=/+\n' | head -c 64)
  CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=\n')
  AUTHZ=(--data-urlencode response_type=code --data-urlencode "client_id=$CLIENT_ID"
    --data-urlencode "redirect_uri=$REDIRECT_URI" --data-urlencode "state=$u"
    --data-urlencode "code_challenge=$CHALLENGE" --data-urlencode code_challenge_method=S256
    --data-urlencode "resource=$RESOURCE")

  # The page the user sees: the Nextcloud link must be on the public origin.
  PAGE=$($CURL -G "$BASE/authorize" "${AUTHZ[@]}")
  LINK=$(echo "$PAGE" | grep -o 'class="button" href="[^"]*"' | sed 's/.*href="//;s/"$//;s/&amp;/\&/g')
  [[ "$LINK" == "$PUBLIC/"* ]] || fail "sign-in link '$LINK' is not on NEXTCLOUD_PUBLIC_URL $PUBLIC"
  ! grep -q "$INTERNAL[/\"]" <<<"$PAGE" || fail "the sign-in page mentions the internal host $INTERNAL"
  echo "sign-in link: $LINK"

  sep "$u: sign in on Nextcloud (Login Flow v2), poll, exchange the code"
  if [ -n "${TOTP[$u]}" ]; then
    while [ $(($(date +%s) / 30)) = "$TOTP_USED_WINDOW" ]; do sleep 1; done
  fi
  JAR=$(mktemp)
  LOCATION=$(NC_USER=$u NC_PASS="${PASS[$u]}" NC_TOTP="${TOTP[$u]}" mcp_authorize "$BASE" "$JAR" "${AUTHZ[@]}")
  rm -f "$JAR"
  CODE=$(python3 -c "import sys; from urllib.parse import urlparse, parse_qs; print(parse_qs(urlparse(sys.argv[1]).query).get('code', [''])[0])" "$LOCATION")
  [ -n "$CODE" ] || fail "no code in the redirect: $LOCATION"
  TOKENS=$($CURL -X POST "$TOKEN" --data-urlencode grant_type=authorization_code \
    --data-urlencode "code=$CODE" --data-urlencode "code_verifier=$VERIFIER" \
    --data-urlencode "client_id=$CLIENT_ID" --data-urlencode "resource=$RESOURCE")
  ACCESS_TOKEN=$(echo "$TOKENS" | json 'd["access_token"]') || fail "code exchange failed: $TOKENS"
  REFRESH_TOKEN=$(echo "$TOKENS" | json 'd["refresh_token"]')
  [ "$(aiquila_devices "$u")" -ge 1 ] || fail "no AIquila MCP device for $u in Nextcloud"
  echo "signed in; Nextcloud lists an AIquila MCP device for $u"

  sep "$u: tools act as $u"
  FILES=$(call_tool list_files '{"path":"/"}')
  grep -q "e2e-$u.txt" <<<"$FILES" || fail "list_files does not show $u's file: $FILES"
  ! grep -q "e2e-$other.txt" <<<"$FILES" || fail "list_files shows $other's file to $u"
  echo "list_files shows e2e-$u.txt and not e2e-$other.txt"
  [ "$(call_tool list_users '{}' | json 'd.get("result", {}).get("isError", False) or "error" in d')" = True ] \
    || fail "list_users (admin-only) was not refused for $u"
  echo "list_users refused for the non-admin"

  sep "$u: revoke the refresh token"
  $CURL -o /dev/null -X POST "$REVOKE" --data-urlencode "token=$REFRESH_TOKEN" \
    --data-urlencode token_type_hint=refresh_token --data-urlencode "client_id=$CLIENT_ID"
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    [ "$(aiquila_devices "$u")" = 0 ] && break
    sleep 1
  done
  [ "$(aiquila_devices "$u")" = 0 ] || fail "the AIquila MCP device of $u survived revocation"
  [ "$(mcp_status "$ACCESS_TOKEN")" = 401 ] || fail "the access token of the revoked grant is still accepted"
  echo "device removed in Nextcloud; access token → 401"
done

# ── Negative case ─────────────────────────────────────────────────────────────
sep "Without NEXTCLOUD_PUBLIC_URL the server warns at startup"
E2E_PUBLIC_URL= docker compose up -d mcp >/dev/null 2>&1
wait_for "$BASE/health" 'ok' 60
docker compose logs mcp --since 2m 2>&1 | grep -q '"nc\\" looks internal' \
  || { docker compose logs mcp --tail 20 >&2; fail "no startup warning about the internal NEXTCLOUD_URL"; }
echo "startup warning logged"
docker compose up -d mcp >/dev/null 2>&1
wait_for "$BASE/health" 'ok' 60

sep "All checks passed"
