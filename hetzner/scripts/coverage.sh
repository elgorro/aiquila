#!/usr/bin/env bash
# Run the tests with coverage and fail when total statement coverage drops
# below coverage-baseline.txt. The baseline is a ratchet: raise it when a
# change raises coverage. Policy: docs/dev/ci-cd.md#coverage.
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p coverage
go test -coverprofile=coverage/cover.raw ./...
# The fakes under internal/ are test helpers, not code the CLI ships.
grep -v -E '/internal/(hcloudtest|sshtest)/' coverage/cover.raw > coverage/cover.out
go tool cover -func=coverage/cover.out > coverage/func.txt

total=$(awk '/^total:/ { sub("%", "", $NF); print $NF }' coverage/func.txt)
baseline=$(tr -d '[:space:]' < coverage-baseline.txt)
echo "Hetzner CLI statement coverage: ${total}% (baseline ${baseline}%)"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  echo "**Hetzner CLI coverage:** ${total}% of statements (baseline ${baseline}%)" >> "$GITHUB_STEP_SUMMARY"
fi

if awk -v t="$total" -v b="$baseline" 'BEGIN { exit !(t < b) }'; then
  echo "::error::Hetzner CLI coverage ${total}% is below the baseline ${baseline}%. Add tests for the code this change adds or touches."
  exit 1
fi
if awk -v t="$total" -v b="$baseline" 'BEGIN { exit !(t >= b + 1) }'; then
  echo "::notice::Hetzner CLI coverage rose to ${total}%. Raise hetzner/coverage-baseline.txt to lock it in."
fi
