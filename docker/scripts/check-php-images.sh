#!/usr/bin/env bash
# Check that every PHP-based image the stacks use ships the latest PHP release
# of its branch. Exits 1 if any image is behind.
#
# Image refs are read from the repo, so the check always follows the pins:
#   - FROM in docker/installation/Dockerfile
#   - adminer in docker/installation/docker-compose.yml
#   - nextcloud in hetzner/docker/{nextcloud,full}/docker-compose.yml
#
# For each image the PHP version comes from the PHP_VERSION env in the
# linux/amd64 image config (Docker Hub registry API, anonymous pull token);
# the latest release per branch comes from php.net's releases API.
#
# Usage: docker/scripts/check-php-images.sh [image:tag ...]
#   With arguments, checks those images instead of the repo pins.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

json() { python3 -I -c "import json,sys; d=json.load(sys.stdin); $1"; }

collect_refs() {
  sed -n 's/^FROM[[:space:]]\+\([^[:space:]]*\).*/\1/p' "$ROOT/docker/installation/Dockerfile"
  grep -hoE 'image:[[:space:]]*(adminer|nextcloud):[^[:space:]]+' \
    "$ROOT/docker/installation/docker-compose.yml" \
    "$ROOT/hetzner/docker/nextcloud/docker-compose.yml" \
    "$ROOT/hetzner/docker/full/docker-compose.yml" \
    | sed 's/^image:[[:space:]]*//'
}

php_version_of() {
  local ref=$1 repo tag token auth index digest manifest config
  repo=${ref%%:*}
  tag=${ref#*:}
  [[ $repo == */* ]] || repo="library/$repo"

  token=$(curl -fsS "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$repo:pull" \
    | json 'print(d["token"])')
  auth="Authorization: Bearer $token"

  index=$(curl -fsS -H "$auth" \
    -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
    "https://registry-1.docker.io/v2/$repo/manifests/$tag")
  digest=$(json 'print(next(m["digest"] for m in d["manifests"] if m.get("platform", {}).get("architecture") == "amd64" and m["platform"].get("os") == "linux"))' <<<"$index")

  manifest=$(curl -fsS -H "$auth" \
    -H 'Accept: application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
    "https://registry-1.docker.io/v2/$repo/manifests/$digest")
  config=$(json 'print(d["config"]["digest"])' <<<"$manifest")

  curl -fsSL -H "$auth" "https://registry-1.docker.io/v2/$repo/blobs/$config" \
    | json 'print(next(e.split("=", 1)[1] for e in d["config"]["Env"] if e.startswith("PHP_VERSION=")))'
}

latest_php() {
  curl -fsS "https://www.php.net/releases/?json&version=$1" | json 'print(d["version"])'
}

if [ $# -gt 0 ]; then
  refs=$(printf '%s\n' "$@")
else
  refs=$(collect_refs | sort -u)
fi

summary=${GITHUB_STEP_SUMMARY:-/dev/null}
{
  echo "| Image | PHP in image | Latest on branch | Status |"
  echo "|---|---|---|---|"
} >>"$summary"

failed=0
while read -r ref; do
  [ -n "$ref" ] || continue
  have=$(php_version_of "$ref")
  want=$(latest_php "${have%.*}")
  if [ "$(printf '%s\n%s\n' "$have" "$want" | sort -V | head -n1)" = "$want" ]; then
    status=ok
  else
    status=OUTDATED
    failed=1
  fi
  printf '%-28s PHP %-8s latest %-8s %s\n' "$ref" "$have" "$want" "$status"
  echo "| \`$ref\` | $have | $want | $status |" >>"$summary"
done <<<"$refs"

if [ "$failed" -ne 0 ]; then
  echo "One or more images ship an outdated PHP. Bump the pin, or wait for the upstream rebuild." >&2
  exit 1
fi
