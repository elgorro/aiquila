# CI/CD Workflows

AIquila uses GitHub Actions for continuous integration and deployment.

## Workflows Overview

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `test.yml` | PR to main | Run tests, per component |
| `lint.yml` | PR to main | ESLint + Prettier on the MCP server |
| `claude-code-review.yml` | PR to main | Automatic code review (internal PRs and bots) |
| `manual-code-review.yml` | Manual dispatch | Maintainer-triggered review, for fork PRs |
| `claude.yml` | `@claude` mention | Respond to a mention on an issue or PR |
| `mcp-release.yml` | Version change in `mcp-server/` | Auto-release MCP server (GitHub Release + Docker + npm + MCP Registry) |
| `nc-release.yml` | Version change in `nextcloud-app/` | Auto-release & publish NC app (nightly + stable) |
| `hetzner-release.yml` | Version change in `hetzner/` | Auto-release Hetzner CLI (GitHub Release + cosign) |
| `hetzner-integration-test.yml` | Manual dispatch | Full two-server E2E integration test |

## Test Workflow (`test.yml`)

Runs on every pull request to `main`. Three component jobs, each of which only does
work when its component actually changed.

A `changes` job diffs the PR against its base commit and publishes one boolean per
component; each test job then gates its steps on the relevant one.

| Job (status check) | Runs when | Does |
|---|---|---|
| `MCP Server Tests` | `mcp-server/**` changed (excluding `*.md`) | `npm ci`, `npm test`, `npm run build` |
| `Nextcloud App Tests` | `nextcloud-app/**` changed (excluding `*.md`) | Composer install, `composer test`, `composer psalm`, regenerate the OpenAPI spec and fail if it differs |
| `Hetzner CLI Tests` | `hetzner/**` changed (excluding `*.md`) | `go vet ./...`, `go test ./...` |

Two rules worth knowing:

- **Markdown-only changes under a component do not trigger its tests.**
- **Any change under `.github/workflows/` sets every component to true**, so CI
  revalidates itself whenever a workflow is edited.

A docs-only PR reports all three jobs green in seconds without installing anything.

### Why the jobs are not filtered off the trigger

`MCP Server Tests` and `Nextcloud App Tests` are **required status checks**. A
required job that does not run is never *reported*, and GitHub then blocks the PR
forever on `Expected — Waiting for status to be reported`. A `paths:` filter on the
trigger, or a job-level `if:`, both cause this. That is why the jobs always run and
gate their **steps** instead. Keep that shape when adding a job that becomes required.

List the current required checks with:

```bash
gh api repos/elgorro/aiquila/branches/main/protection --jq '.required_status_checks.contexts'
```

## Lint Workflow (`lint.yml`)

Runs on every pull request to `main`. The `ESLint & Prettier` job is a required
status check, so it always runs, and uses the same inline change detection: it lints
only when a non-Markdown file under `mcp-server/` outside `tests/` changed.

**Checks:**
- ESLint for TypeScript errors (warnings are allowed, errors fail)
- Prettier for formatting consistency

**Fix locally before pushing:**
```bash
cd mcp-server
npm run lint:fix
npm run format
```

## Dependency Updates (`.github/dependabot.yml`)

Dependabot runs weekly across every ecosystem in the repo:

| Ecosystem | Directory | Commit prefix |
|---|---|---|
| npm | `/mcp-server` | `chore(mcp)` |
| npm | `/nextcloud-app` | `chore(nextcloud)` |
| composer | `/nextcloud-app` | `chore(nextcloud)` |
| gomod | `/hetzner` | `chore(hetzner)` |
| docker | each `docker/` and `hetzner/docker/` stack | `chore` |
| github-actions | `/` | `chore` |

Minor and patch updates are grouped into a single PR per ecosystem; majors
arrive as individual PRs so they can be evaluated on their own. Each ecosystem
is capped at 5 open PRs.

Two things follow from this:

- **Pin third-party images to a tag.** Dependabot cannot propose an update for
  a service pinned to `:latest`, so an unpinned image is silently excluded from
  dependency tracking. First-party images (`aiquila-mcp`, `aiquila-nextcloud`)
  are the deliberate exception.
- **Transitive vulnerabilities usually need a lockfile refresh, not a bump.**
  Direct dependencies tend to declare their own dependencies as ranges, so the
  fix is often `npm audit fix` plus committing the lockfile. Check the alert's
  dependency path before assuming a direct dependency is at fault.

## Code Review Workflows

### Automatic (`claude-code-review.yml`)

Runs on every pull request to `main` and posts inline comments.

`claude-code-action` refuses non-human actors unless they are listed in
`allowed_bots`. `dependabot[bot]` is allow-listed; any other bot needs adding there
or its PRs fail with `Workflow initiated by non-human actor`. The allow-list
lowercases and strips a trailing `[bot]`, so either spelling matches.

### Fork pull requests

**The automatic review cannot run on a fork PR.** GitHub does not mint an OIDC token
for a `pull_request` event from a fork, so the job fails with
`Could not fetch an OIDC token` no matter what permissions the workflow declares.
That red check is infrastructure, not a verdict on the code, and should not block a
merge.

Two other things follow from the same rule, and are worth understanding before
approving anything:

- A fork's workflow runs park at `action_required` until a maintainer approves them,
  so no checks appear at first. Approving releases the **compute**, not the secrets —
  repository secrets are never passed to a fork's `pull_request` run.
- Never check out a fork branch and build or test it on your own machine. `npm ci`
  alone executes arbitrary lifecycle scripts with your SSH keys, npm tokens and cloud
  credentials in reach. Let CI run it, or use a throwaway container.

There is deliberately **no `pull_request_target` trigger** in this repository. That is
the trigger that *would* hand secrets to fork-controlled code. Keep it that way.

### Manual (`manual-code-review.yml`)

The review path for fork PRs. Triggered by a maintainer:

```bash
gh workflow run manual-code-review.yml -f pr=504
```

- `workflow_dispatch` can only be started by an account with **write access**, so an
  outside contributor cannot trigger a review of their own pull request.
- It runs in the base-repository context, which is exactly what the `pull_request`
  event denies a fork — so the credentials are available.
- **Fork code is never executed.** The job checks out this repository's default
  branch, *not* the PR head, and Claude is given only the inline-comment tool: no
  shell, no install, no build, no test run. The diff is read as data.
- Permissions are `contents: read` plus `pull-requests: write`, so the worst case for
  a hostile diff attempting prompt injection is an unwanted comment.

## Automated Release Workflows

AIquila uses automated release workflows that trigger when you bump the version number and push to `main`.

### MCP Server Release (`mcp-release.yml`)

**Triggers:** Automatically when version changes in `mcp-server/package.json` on push to `main`

**What it does:**
1. Detects version change in `package.json`
2. Runs tests and builds the project
3. Creates tag `mcp-vX.X.X`
4. Packages: `dist/`, `package.json`, `README.md`
5. Creates GitHub release with `aiquila-mcp-vX.X.X.tar.gz`
6. In parallel (after release):
   - Pushes Docker image to GHCR (`ghcr.io/elgorro/aiquila-mcp`)
   - Publishes npm package (`aiquila-mcp` on npmjs.com)
   - Publishes to MCP Registry (`io.github.elgorro/aiquila-mcp`)

**How to release:**
```bash
# 1. Update version in mcp-server/package.json
vim mcp-server/package.json  # Change version to 0.1.1

# 2. Commit and push to main
git add mcp-server/package.json
git commit -m "Bump MCP server to v0.1.1"
git push origin main

# 3. Workflow runs automatically and creates:
#    - Tag: mcp-v0.1.1
#    - GitHub Release with tarball
```

### Nextcloud App Release (`nc-release.yml`)

**Triggers:** Automatically when version changes in `nextcloud-app/appinfo/info.xml` on push to `main`

**What it does:**
1. Detects version change in `info.xml`
2. Runs composer tests
3. Builds frontend (`npm run build`)
4. Creates tag `nc-vX.X.X`
5. Packages app (excludes: tests/, vendor/, node_modules/, src/)
6. Signs package (if `NC_SIGN_KEY` secret exists)
7. Creates GitHub release with `aiquila.tar.gz` and signature
8. **Publishes nightly** to the Nextcloud App Store beta channel (`"nightly": true`) — no approval needed
9. **Waits for manual approval** (via GitHub environment protection)
10. Publishes stable release to Nextcloud App Store

**How to release:**
```bash
# 1. Update version in BOTH files
vim nextcloud-app/appinfo/info.xml     # Change <version>0.1.1</version>
vim nextcloud-app/package.json         # Change version to 0.1.1

# 2. Update CHANGELOG.md
vim nextcloud-app/CHANGELOG.md

# 3. Commit and push to main
git add nextcloud-app/
git commit -m "Bump Nextcloud app to v0.1.1"
git push origin main

# 4. Workflow runs automatically:
#    - Creates tag: nc-v0.1.1
#    - Creates GitHub Release
#    - Publishes nightly to App Store beta channel (automatic)
#    - Waits for your approval to publish stable to App Store

# 5. Approve stable app store publishing:
#    Go to: https://github.com/YOUR-REPO/actions
#    Click on the workflow run
#    Click "Review deployments" and approve "nextcloud-appstore"
```

### Hetzner CLI Release (`hetzner-release.yml`)

**Triggers:** Automatically when version changes in `hetzner/VERSION` on push to `main`

**What it does:**
1. Detects version change in `hetzner/VERSION`
2. Builds Linux amd64/arm64 Go binaries
3. Generates SHA-256 checksums
4. Signs all artifacts with cosign (GitHub OIDC — no secrets needed)
5. Creates tag `hetzner-vX.X.X` and GitHub Release

**How to release:**
```bash
# 1. Bump version in hetzner/VERSION
echo "1.2.0" > hetzner/VERSION

# 2. Commit and push to main
git add hetzner/VERSION
git commit -m "chore(hetzner): bump CLI to v1.2.0"
git push origin main

# 3. Workflow runs automatically and creates:
#    - Tag: hetzner-v1.2.0
#    - GitHub Release with signed binaries + checksums
```

### Hetzner Integration Test (`hetzner-integration-test.yml`)

**Triggers:** Manual dispatch (`workflow_dispatch`)

**What it does:**
1. Provisions two Hetzner cloud servers:
   - Nextcloud server (`cpx21`) — Nextcloud with the AIquila app installed
   - MCP server (`cpx11`) — MCP server pointing at the Nextcloud instance
2. Runs 6 test groups: `oauth`, `tools`, `mcp_protocol`, `nc_app`, `connector` (off by default), `infra`
3. Always destroys servers on completion (orphan cleanup ensures no leaked resources)

**Required secrets:** `HCLOUD_TOKEN`, `ANTHROPIC_API_KEY`

See [`docs/hetzner/ci-flow.md`](../hetzner/ci-flow.md) for the detailed timeline and cost breakdown.

### Secrets

#### For MCP Server Publishing (One-time setup — no secrets required)

Both the npm publish and MCP Registry publish jobs authenticate via **GitHub OIDC** — no
long-lived tokens to store or rotate.

**npm Trusted Publisher (one-time setup on npmjs.com)**
- Go to: [npmjs.com](https://www.npmjs.com) → sign in → package `aiquila-mcp` → Settings →
  Trusted Publishers → Add a publisher
- Provider: **GitHub Actions**
- Repository: `elgorro/aiquila`
- Workflow filename: `mcp-release.yml`
- Environment: *(leave blank)*

Once configured, the `npm-publish` job authenticates via GitHub OIDC automatically.
No token to rotate or store.

The MCP Registry publish step also uses **GitHub OIDC** (`id-token: write` permission) — no extra secret needed.

### Setup Required GitHub Secrets

Navigate to: **Repository Settings → Secrets and variables → Actions → New repository secret**

#### For Nextcloud App Signing (Required)

1. **`NC_SIGN_KEY`** - Your private key content
   ```bash
   # Copy your private key content
   cat ~/.nextcloud/certificates/aiquila.key
   # Paste entire content (including BEGIN/END lines) into secret
   ```

2. **`NC_SIGN_CERT`** - Your certificate content (if needed)
   ```bash
   cat ~/.nextcloud/certificates/aiquila.crt
   ```

#### For Nextcloud App Store Publishing (Required for auto-publish)

3. **`NEXTCLOUD_APPSTORE_USERNAME`** - Your app store username

4. **`NEXTCLOUD_APPSTORE_TOKEN`** - Your app store API token
   - Get from: [apps.nextcloud.com](https://apps.nextcloud.com) → Account Settings → API Token

#### For Hetzner Integration Test (Required for E2E tests)

5. **`HCLOUD_TOKEN`** — Hetzner Cloud API token (used to provision/destroy test servers and DNS)

6. **`ANTHROPIC_API_KEY`** — Anthropic API key (used by the connector test group)

### Setup Manual Approval Gate

To enable manual approval before publishing the **stable** release to the app store
(nightly publishes automatically without approval):

1. Go to **Repository Settings → Environments**
2. Create new environment: `nextcloud-appstore`
3. Enable "Required reviewers"
4. Add yourself as a required reviewer
5. Save

Now the workflow will publish to the nightly/beta channel automatically, then wait
for your approval before publishing the stable release.

### Version Requirements

**Keep versions in sync:**
- MCP Server: Update `mcp-server/package.json`
- Nextcloud App: Update BOTH:
  - `nextcloud-app/appinfo/info.xml` (e.g., `<version>0.1.1</version>`)
  - `nextcloud-app/package.json` (e.g., `"version": "0.1.1"`)

## Local Development

### Running Tests Locally

**MCP Server:**
```bash
cd mcp-server
npm test           # Run once
npm run test:watch # Watch mode
```

**Nextcloud App:**
```bash
cd nextcloud-app
composer install
composer test
```

### Running Linters Locally

```bash
cd mcp-server
npm run lint       # Check for errors
npm run lint:fix   # Auto-fix errors
npm run format     # Format with Prettier
```

### Pre-commit Checklist

Before pushing:
1. `npm run lint` passes
2. `npm test` passes
3. `npx tsc --noEmit` passes (no type errors)

## Troubleshooting

### A check is stuck on "Expected — Waiting for status to be reported"

The check is **required** in branch protection, but its job never ran, so it never
reported. Nothing you push will clear it and there is no run to re-run.

The cause is almost always a `paths:` filter on the workflow's trigger, or a
job-level `if:`, that filtered the job out for this PR. Fix the workflow rather than
the PR: let the job always run and gate its steps instead — see
`.github/workflows/test.yml`. Removing the check from branch protection also clears
it, at the cost of the gate.

### `claude-review` is red on a fork PR

Expected, and not a verdict on the code — a fork gets no OIDC token, so the job
cannot authenticate. Do not let it block the merge. Run the manual review instead:

```bash
gh workflow run manual-code-review.yml -f pr=<N>
```

### A fork PR shows no checks at all

Its workflow runs are parked awaiting maintainer approval:

```bash
gh api repos/elgorro/aiquila/actions/runs --paginate \
  --jq '.workflow_runs[] | select(.head_branch=="<branch>" and .conclusion=="action_required") | "\(.id) \(.name)"'
gh api --method POST repos/elgorro/aiquila/actions/runs/<run_id>/approve
```

Read the diff before approving — the runner executes the fork's test code.

### Tests Failing in CI

- Check Node.js version matches (24)
- Check PHP version matches (8.4)
- Run tests locally to reproduce

### Lint Errors

```bash
# See what would change
npx prettier --check src/

# Auto-fix
npm run lint:fix && npm run format
```

### Release Not Triggering

- Ensure version actually changed in the correct file:
  - MCP: `mcp-server/package.json`
  - Nextcloud: `nextcloud-app/appinfo/info.xml`
- Check you pushed to `main` branch
- Check workflow runs in Actions tab
- Ensure changes are in the correct directory path
- Workflow ignores changes to `*.md` files and `tests/` directories

### npm Publish Fails

- Verify the **Trusted Publisher** is configured on npmjs.com for the `aiquila-mcp` package
  (see "For MCP Server Publishing" under Secrets above)
- If the job reports a 401 or "no authentication" error, confirm `permissions: id-token: write`
  is present in the `npm-publish` job and the Trusted Publisher entry matches the repository
  and workflow filename exactly
- Check that the version being published doesn't already exist on npmjs.com (re-publishing the same version fails)
- Run `npm pack` locally in `mcp-server/` to confirm the package builds cleanly before pushing

### MCP Registry Publish Fails

- The job uses GitHub OIDC (`id-token: write`) — no extra secret required, but the repository must be in the `io.github.elgorro` namespace for the OIDC claim to match
- If `mcp-publisher login github-oidc` fails, check that the job's `permissions` block includes `id-token: write`
- If `mcp-publisher publish` reports a version conflict, confirm `server.json` version update step ran successfully in the workflow logs

### App Store Publishing Fails

- Verify secrets are set: `NC_SIGN_KEY`, `NEXTCLOUD_APPSTORE_USERNAME`, `NEXTCLOUD_APPSTORE_TOKEN`
- Check signature is valid (download .tar.gz and .sig, verify locally)
- Ensure app is registered at apps.nextcloud.com
- Check app store API response in workflow logs

## Customization

### Adding New Test Jobs

Edit `.github/workflows/test.yml`. Add a boolean for the component to the `changes`
job, then gate the new job's steps on it — do **not** filter the job off the trigger
or skip it with a job-level `if:`, or it can never become a required check:

```yaml
jobs:
  changes:
    outputs:
      mynewthing: ${{ steps.filter.outputs.mynewthing }}
    # ... add `mynewthing=$(match '^my-new-thing/')` to the filter step

  my-new-thing:
    name: My New Thing Tests
    runs-on: ubuntu-latest
    needs: changes
    steps:
      - uses: actions/checkout@v5
        if: needs.changes.outputs.mynewthing == 'true'
      # ... further steps, each with the same `if:`

      - name: No My New Thing changes
        if: needs.changes.outputs.mynewthing != 'true'
        run: echo "Nothing to test." >> "$GITHUB_STEP_SUMMARY"
```

The job name is what branch protection matches, so keep it stable once the check is
required.

### Changing Node.js Version

Update in all workflows:
```yaml
- name: Setup Node.js
  uses: actions/setup-node@v7
  with:
    node-version: '24'  # Change version here
```
