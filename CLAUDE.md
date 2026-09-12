# AIquila — Claude Code Configuration

## Project Overview

Monorepo with two independently versioned components:
- **`mcp-server/`** — TypeScript/Node.js MCP server (npm package: `aiquila-mcp`, admin account: `aiquila` on npmjs.com; versioned in `package.json`)
- **`nextcloud-app/`** — PHP 8.4 Nextcloud app (Composer, versioned in `appinfo/info.xml`)

Docs live in `docs/`. Docker environments in `docker/` (dev: `installation/`, prod: `standalone/`).

## Key Commands

All MCP server commands run from `mcp-server/`:

```bash
npm run build      # compile TypeScript → dist/
npm run dev        # hot reload via tsx (stdio transport by default)
npm test           # vitest unit tests
npm run lint       # eslint (warnings ok, errors fail CI)
npm run format     # prettier --write src/  (auto-fix formatting)
npx prettier --check src/   # check only — run before committing
```

**CI requires both `npm run lint` and `npx prettier --check src/` to pass.**
Always run `npm run format` after editing TypeScript files.

HTTP transport locally:
```bash
MCP_TRANSPORT=http npm run dev   # serves at http://localhost:3339/mcp
```

Docker (standalone — external Nextcloud):
```bash
cd docker/standalone && make up
make logs-follow
make test
```

## Nextcloud app — static analysis

Psalm runs over `lib/` at `errorLevel=3` and **CI fails on any error**:

```bash
cd nextcloud-app && composer psalm
```

`OCP\*` resolves through `vendor/nextcloud/ocp` (parsed via `<extraFiles>`); the private
`OC\` classes the app touches are stubbed in `tests/stubs/`. There is no baseline — fix
findings rather than adding one.

## Nextcloud app — OpenAPI spec

After editing a controller annotated with `#[OpenAPI]`, regenerate and commit the spec
(CI fails on a mismatch):

```bash
cd nextcloud-app && vendor/bin/generate-spec   # updates openapi.json + openapi-full.json
```

## Nextcloud app — local dev testing

To test working-tree changes in the dev stack (`docker/installation/`) and check for
runtime errors:

```bash
cd docker/installation
make build-tarball            # repackage nextcloud-app/ from the working tree
make up                       # IMPORTANT: rebuilds the image with the new tarball
# The post-install hook only runs on first install, so re-extract manually after a recreate:
docker compose exec -T nextcloud bash /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh
docker compose exec -T nextcloud su -p www-data -s /bin/sh -c "php /var/www/html/occ upgrade"   # if migrations changed
```

Gotchas:
- `make build-tarball` alone does **not** update the container — the install hook extracts
  `/tmp/aiquila.tar.gz` which is baked into the image, so `make up` (image rebuild) is required.
- `make build-tarball` builds in a scratch dir inside the container from a read-only mount of
  `nextcloud-app/`; it never touches your working tree (`vendor/`, `node_modules/`, `js/dist/`
  and `composer.lock` are left alone).

Check logs / status codes (level 3 = ERROR, 4 = FATAL; `serverDI` level-0 lines are noise):

```bash
make nc-log                   # tail data/nextcloud.log
docker compose exec -T nextcloud sh -c "grep '\"level\":[34]' /var/www/html/data/nextcloud.log | tail"
# Exercise dashboard widgets (server-rendered get_items):
curl -s -u testuser:'AIquila2026!dev' -H 'OCS-APIRequest: true' \
  "http://localhost:8080/ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=aiquila_usage"
```

## Workflow

**Always work on a branch — never commit directly to main.**

```bash
git checkout -b feat/<short-description>   # or fix/, chore/, docs/
# ... make changes ...
git add <specific files>
git commit -m "feat(mcp): ..."
gh pr create
```

Commit messages follow **Conventional Commits** with scope:
- `feat(mcp):` / `fix(mcp):` / `chore(mcp):` — MCP server changes
- `feat(nextcloud):` / `fix(nextcloud):` — Nextcloud app changes
- `docs:` / `chore:` — cross-cutting changes

To auto-close the issue a PR resolves, put a **closing keyword** in the PR description —
`Closes #184` (or `Fixes #184` / `Resolves #184`). A bare mention like `(#184)` or
`Implements GH #184` links the issue but does **not** close it on merge.

## Reviewing pull requests

Contributor-facing rules live in `CONTRIBUTING.md`. This section is the maintainer
side: how to get a PR verified and merged, and which red checks are real.

### Verify claims, don't trust the description

A PR that says "tests pass" and "these tests are load-bearing" is a claim, not
evidence. How you check it depends on who wrote it.

**Never run an outside contributor's code on your workstation.** `npm ci` alone
executes arbitrary lifecycle scripts with your SSH keys, npm tokens and cloud
credentials in reach — a far worse exposure than an ephemeral runner. Do not check
out a fork branch and build or test it locally.

For a fork PR, verify at arm's length instead:

1. Read the diff as text — `gh pr diff <N>`.
2. Let CI run it: approve the parked workflow runs (below) and read the results.
3. Trigger **Manual Code Review** (`.github/workflows/manual-code-review.yml`) for
   the automated review, which the `pull_request` workflow cannot do on forks:

   ```bash
   gh workflow run manual-code-review.yml -f pr=<N>
   gh run watch "$(gh run list --workflow=manual-code-review.yml --limit 1 --json databaseId --jq '.[0].databaseId')"
   ```

If you genuinely must execute fork code, do it in a throwaway container with no
mounted credentials — not in your checkout.

For an **internal** PR from a trusted author, a local worktree is fine:

```bash
git fetch origin pull/<N>/head:pr<N>-check
git worktree add /tmp/pr<N> pr<N>-check          # isolated; never dirty the main tree
cd /tmp/pr<N>/mcp-server && npm ci && npx vitest run
```

To confirm a new test actually covers the fix, revert only the source files and
re-run — the new tests must go red:

```bash
git checkout origin/main -- mcp-server/src/<changed files>
npx vitest run src/__tests__/<new test file>
```

Clean up with `git worktree remove --force /tmp/pr<N>` and `git branch -D pr<N>-check`.

Also confirm the premise against upstream when a fix depends on third-party
behaviour (e.g. Nextcloud/spreed docs for a Talk status code) rather than taking
the PR's reading of it.

### Fork PRs

Workflow runs from a fork park at `action_required` until a maintainer approves
them, so `gh pr checks` reports nothing at first:

```bash
gh api repos/elgorro/aiquila/actions/runs --paginate \
  --jq '.workflow_runs[] | select(.head_branch=="<branch>" and .conclusion=="action_required") | "\(.id) \(.name)"'
gh api --method POST repos/elgorro/aiquila/actions/runs/<run_id>/approve
```

Approving releases the compute, **not** the secrets. GitHub does not pass repository
secrets or mint an OIDC token for `pull_request` runs from a fork, so fork builds
never see `CLAUDE_CODE_OAUTH_TOKEN` or anything else. Two consequences:

- Approving a fork run is safe. The runner is ephemeral, `GITHUB_TOKEN` is read-only,
  and no `pull_request_target` trigger exists in this repo — that is the trigger that
  *would* expose secrets to fork-controlled code, so keep it that way.
- **`claude-review` always fails on fork PRs** with `Could not fetch an OIDC token`,
  even though `id-token: write` is set. This is infrastructure, not a code signal, so
  do not let the red check block the merge. Run **Manual Code Review** instead
  (`gh workflow run manual-code-review.yml -f pr=<N>`) — `workflow_dispatch` is
  restricted to accounts with write access, so no outside contributor can trigger a
  review of their own PR, and it runs in the base-repo context where credentials
  exist. It checks out this repo's default branch rather than the PR head and grants
  no shell tool, so fork code is read as data and never executed.

The residual risk is ordinary: `npm ci` and the test suite execute fork-authored code
**on the runner** — which is the right place for it. Read the diff before approving,
and treat any change to `package.json`, lock files or `.github/**` as a reason to look
much harder.

### Bot PRs

`claude-code-action` rejects non-human actors unless they appear in `allowed_bots`
(`.github/workflows/claude-code-review.yml`). `dependabot[bot]` is allow-listed; a new
bot needs adding there or it fails with `Workflow initiated by non-human actor`.

### Assigning an issue to an external contributor

GitHub only accepts assignees who are repo collaborators **or** who have commented on
that specific issue. A `Fixes #N` on the PR does not count. `gh issue edit --add-assignee`
exits 0 and silently does nothing when this fails — verify instead of trusting it:

```bash
gh api repos/elgorro/aiquila/assignees/<user>     # 204 = assignable, 404 = not
gh issue view <N> --json assignees --jq '.assignees[].login'
```

Ask them to comment on the issue, or grant **Triage** (assignable, no write access).

### Merging

```bash
gh pr view <N> --json mergeable,mergeStateStatus,reviewDecision
gh api repos/elgorro/aiquila/compare/main...<owner>:<repo>:<branch> --jq '{ahead_by,behind_by}'
```

- `BLOCKED` + `REVIEW_REQUIRED` — needs an approving review; `UNSTABLE` means only a
  non-required check is red (typically `claude-review` on a fork) and is mergeable.
- Squash-merge to match history (`fix(mcp): ... (#504)`); squash preserves the
  contributor as author.
- Check `behind_by` is 0 before merging.

## Version Bumps

All locations that must be updated on each release:

- **MCP server**: `mcp-server/package.json` → `"version"`
- **MCP registry**: `mcp-server/server.json` → `"version"` fields + `ghcr.io` image tag (auto-synced by `npm run build` except the image tag)
- **Nextcloud app**: `nextcloud-app/appinfo/info.xml` → `<version>`
- **Nextcloud frontend**: `nextcloud-app/package.json` → `"version"`
- **Lock file**: run `npm install` in `mcp-server/` to sync `package-lock.json`

Both components stay on the same version number.

## Architecture Notes

- **Transports**: stdio (Claude Desktop) or Streamable HTTP (Docker/Claude.ai)
- **Auth**: built-in OAuth 2.0 provider (`MCP_AUTH_ENABLED=true`), PKCE, JWT via `node:crypto`
- **Logging**: structured JSON to stderr via pino (`LOG_LEVEL` env var, default `info`)
- **Logging is always to stderr** — stdout is reserved for the MCP protocol (stdio transport)

See `docs/dev/mcp-server-architecture.md` for full architecture details.

## Environment Variables (MCP server)

| Variable | Required | Notes |
|----------|----------|-------|
| `NEXTCLOUD_URL` | Yes | trailing slash stripped automatically |
| `NEXTCLOUD_USER` | Yes | |
| `NEXTCLOUD_PASSWORD` | Yes | use app password |
| `MCP_TRANSPORT` | No | `stdio` (default) or `http` |
| `MCP_AUTH_ENABLED` | No | `true` to enable OAuth for Claude.ai |
| `MCP_AUTH_SECRET` | If auth | `openssl rand -hex 32` |
| `MCP_AUTH_ISSUER` | If auth | public HTTPS URL of this server |
| `MCP_AUTH_STATE_DIR` | No | Directory for persisted OAuth state (default `/app/state`) |
| `MCP_LAZY_AUTH` | No | `false` requires a bearer token on every JSON-RPC method. Default `true`: `initialize`, `tools/list` and `get_local_time` are served without one |
| `MCP_LOCALE` | No | BCP 47 locale tag for `localTime` in `get_local_time` (e.g. `sv-SE`); defaults to system locale |
| `MCP_MAX_FILE_SIZE` | No | Max `write_file` content length in bytes (default `1073741824` = 1 GB) |
| `MCP_ALLOWED_HOSTS` | No | Comma-separated extra hostnames for DNS rebinding protection (e.g. `mcp` for Docker service name) |
| `MCP_CORS_ORIGINS` | No | Comma-separated extra origins allowed to make browser requests. The `MCP_AUTH_ISSUER` origin is always allowed when auth is on |
| `MCP_OCC_ALLOWLIST` | No | Comma-separated list of allowed OCC commands for `run_occ`; overrides default allowlist |
| `LOG_LEVEL` | No | `trace`/`debug`/`info`/`warn`/`error`/`fatal` |

## Tests

```bash
cd mcp-server && npm test
```

Tests live in `mcp-server/src/__tests__/`. Auth tests cover provider, store, and token flows.
Do not use `--no-verify` to skip hooks.

### OAuth end-to-end test

`docker/standalone/scripts/test-oauth.sh` runs the full PKCE flow against a live server:
discovery → client registration → login → token exchange → MCP initialize → tools/list.

```bash
# Requires: docker/standalone/.env with NEXTCLOUD_USER + NEXTCLOUD_PASSWORD + auth vars
cd docker/standalone
./scripts/test-oauth.sh               # default: http://localhost:3339
./scripts/test-oauth.sh https://localhost:3340   # via Caddy (add -k to CURL in the script)
```

Reads credentials from `docker/standalone/.env`. The script restarts the MCP container first to ensure clean session state.

### Functional tool test

`docker/standalone/scripts/test-tools.sh` authenticates via OAuth PKCE (no restart) and exercises core MCP tools end-to-end: `system_status`, `list_files`, `create_folder`, `write_file`, `read_file`, `search_files`, `get_file_info`, `delete`. Cleans up after itself.

```bash
cd docker/standalone
make test-tools        # default: http://localhost:3339
```

Requires a running standalone stack (`make up`) and auth enabled in `.env`.
