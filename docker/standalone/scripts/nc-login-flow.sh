# shellcheck shell=bash
# Sourced by test-oauth.sh and test-tools.sh: plays the browser through the
# sign-in /authorize starts. The MCP server shows a page linking to a Nextcloud
# Login Flow v2; the user signs in there and grants access; the page, reloaded,
# redirects to the client with the code.
#
# Nextcloud renders its login pages from base64 JSON "initial state" and takes
# a CSRF token from /csrftoken, so this follows what its scripts do rather
# than parsing forms.
#
# Expects: $CURL (e.g. "curl -sk").

# Prints the decoded initial-state value KEY from an HTML file.
_nc_state() {
  python3 -c '
import base64, re, sys
m = re.search(r"id=\"initial-state-core-%s\" value=\"([^\"]*)\"" % sys.argv[1], open(sys.argv[2]).read())
print(base64.b64decode(m.group(1)).decode() if m else "")' "$1" "$2"
}

# Prints field FIELD of the JSON on stdin.
_nc_json() { python3 -c 'import json,sys; print(json.load(sys.stdin).get(sys.argv[1], ""))' "$1"; }

# The current TOTP code for a base32 secret (RFC 6238, SHA-1, 30 s, 6 digits).
_nc_totp() {
  python3 -c '
import base64, hashlib, hmac, struct, sys, time
s = sys.argv[1].upper(); key = base64.b32decode(s + "=" * (-len(s) % 8))
h = hmac.new(key, struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
o = h[-1] & 15
print("%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7fffffff) % 1000000))' "$1"
}

# nc_login_flow LOGIN_URL USER PASSWORD [TOTP_SECRET]
# Signs in on Nextcloud and grants the flow access. PASSWORD may be an app
# password (granted through Nextcloud's app-password route, no 2FA needed) or
# the account password (the web login, with a TOTP code when the account
# has 2FA). Returns non-zero when Nextcloud did not grant access.
nc_login_flow() {
  local login_url=$1 user=$2 password=$3 totp=${4:-}
  local origin dir jar auth grant_url state rt next
  origin=$(python3 -c 'import sys; from urllib.parse import urlsplit; u = urlsplit(sys.argv[1]); print(f"{u.scheme}://{u.netloc}")' "$login_url")
  dir=$(mktemp -d)
  jar="$dir/cookies"
  local nc=($CURL -c "$jar" -b "$jar" -A "Mozilla/5.0 (aiquila-test)" -H "Origin: $origin")
  _rt() { "${nc[@]}" "$origin/index.php/csrftoken" | _nc_json token; }

  "${nc[@]}" -L "$login_url" -o "$dir/flow.html"
  auth=$(_nc_state loginFlowAuth "$dir/flow.html")
  if [ -z "$auth" ]; then
    echo "ERROR: $login_url is not a Nextcloud Login Flow v2 page" >&2
    rm -rf "$dir"; return 1
  fi
  state=$(echo "$auth" | _nc_json stateToken)
  echo "Nextcloud asks to grant access to: $(echo "$auth" | _nc_json client)"

  # An app password goes through the flow's own app-password route.
  "${nc[@]}" -X POST "$(echo "$auth" | _nc_json appTokenUrl)" \
    --data-urlencode "user=$user" --data-urlencode "password=$password" \
    --data-urlencode "stateToken=$state" --data-urlencode "requesttoken=$(_rt)" \
    -o "$dir/done.html"
  if [ "$(_nc_state loginFlowState "$dir/done.html")" = '"done"' ]; then
    echo "Granted with the app password."
    rm -rf "$dir"; return 0
  fi

  # Otherwise the account password: the web login, then the grant page. The
  # refused attempt ended the flow's session state, so open the flow again.
  "${nc[@]}" -L "$login_url" -o "$dir/flow.html"
  auth=$(_nc_state loginFlowAuth "$dir/flow.html")
  grant_url=$(echo "$auth" | _nc_json loginRedirectUrl)
  next=$("${nc[@]}" -X POST "$origin/index.php/login" \
    --data-urlencode "user=$user" --data-urlencode "password=$password" \
    --data-urlencode "requesttoken=$(_rt)" --data-urlencode "redirect_url=${grant_url#"$origin"}" \
    --data-urlencode "timezone=UTC" --data-urlencode "timezone_offset=0" \
    -o /dev/null -w '%{redirect_url}')
  if [[ "$next" == *"/login/challenge"* || "$next" == *"/login/selectchallenge"* ]]; then
    if [ -z "$totp" ]; then
      echo "ERROR: $user has two-factor authentication; set TEST_NC_TOTP_SECRET or use an app password" >&2
      rm -rf "$dir"; return 1
    fi
    "${nc[@]}" "$next" -o /dev/null
    "${nc[@]}" -X POST "$origin/index.php/login/challenge/totp" \
      --data-urlencode "challenge=$(_nc_totp "$totp")" --data-urlencode "requesttoken=$(_rt)" \
      --data-urlencode "redirect_url=${grant_url#"$origin"}" -o /dev/null
  fi
  "${nc[@]}" "$grant_url" -o "$dir/grant.html"
  grant=$(_nc_state loginFlowGrant "$dir/grant.html")
  if [ -z "$grant" ]; then
    echo "ERROR: Nextcloud sign-in failed for $user" >&2
    rm -rf "$dir"; return 1
  fi
  "${nc[@]}" -X POST "$(echo "$grant" | _nc_json actionUrl)" \
    --data-urlencode "stateToken=$(echo "$grant" | _nc_json stateToken)" \
    --data-urlencode "requesttoken=$(_rt)" -o "$dir/done.html"
  if [ "$(_nc_state loginFlowState "$dir/done.html")" != '"done"' ]; then
    echo "ERROR: Nextcloud did not grant access" >&2
    rm -rf "$dir"; return 1
  fi
  echo "Granted after signing in as $user."
  rm -rf "$dir"
}

# mcp_authorize BASE COOKIE_JAR AUTHORIZE_PARAMS... — runs /authorize and the
# Nextcloud sign-in, and prints the Location the sign-in page redirected to.
# Needs NC_USER, NC_PASS and optionally NC_TOTP.
mcp_authorize() {
  local base=$1 jar=$2; shift 2
  local page login flow location i
  page=$($CURL -c "$jar" -b "$jar" -G "$base/authorize" "$@")
  login=$(echo "$page" | grep -o 'class="button" href="[^"]*"' | sed 's/.*href="//;s/"$//;s/&amp;/\&/g')
  flow=$(echo "$page" | grep -o 'url=/auth/flow/[A-Za-z0-9_-]*' | sed 's#url=/auth/flow/##')
  if [ -z "$login" ] || [ -z "$flow" ]; then
    echo "ERROR: /authorize did not render the Nextcloud sign-in page" >&2
    echo "$page" >&2
    return 1
  fi
  nc_login_flow "$login" "$NC_USER" "$NC_PASS" "${NC_TOTP:-}" >&2 || return 1
  # The page reloads itself until the server has collected the grant.
  for i in 1 2 3 4 5; do
    sleep 1.2
    location=$($CURL -b "$jar" -D - -o /dev/null "$base/auth/flow/$flow" | grep -i '^location:' | tr -d '\r' | sed 's/[Ll]ocation: //')
    [ -n "$location" ] && { echo "$location"; return 0; }
  done
  echo "ERROR: the sign-in page never redirected" >&2
  return 1
}
