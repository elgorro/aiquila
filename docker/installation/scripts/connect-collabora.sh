#!/bin/sh
# Connect the dev stack's Nextcloud to its Collabora container.
set -eu
cd "$(dirname "$0")/.."

URL=http://collabora:9980

occ() {
    docker compose exec -T -u www-data nextcloud php occ "$@"
}

echo "Waiting for Collabora..."
tries=0
until docker compose exec -T nextcloud curl -fs -o /dev/null "$URL/hosting/discovery"; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "Collabora did not answer at $URL" >&2
        exit 1
    fi
    sleep 2
done

occ app:install richdocuments 2>/dev/null || occ app:enable richdocuments
occ config:app:set richdocuments wopi_url --value="$URL"
occ richdocuments:activate-config
