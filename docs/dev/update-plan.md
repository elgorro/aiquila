# Updates and security releases

How the maintainers keep AIquila's runtimes, images and dependencies current, and how
they respond when a security fix cannot wait. Reporting a vulnerability is covered in
[SECURITY.md](../../SECURITY.md); this page is what happens after a report, or after an
upstream project ships a security release.

- [Inventory](#inventory): everything that needs updates, and how each update surfaces
- [Routine updates](#routine-updates): cadence, order, verification and releases
- [Emergency plan](#emergency-plan): security releases upstream and vulnerabilities in AIquila
- [Checklist](#checklist): to copy into a tracking issue

## Inventory

| What | Pinned in | How a bump surfaces |
|---|---|---|
| Nextcloud + PHP (dev image) | `docker/installation/Dockerfile` `FROM nextcloud:<major>-apache` | Floating tag, rebuilt upstream for PHP fixes. [PHP Images](ci-cd.md#php-images-workflow-php-imagesyml) flags lag; Dependabot (`docker`) proposes majors |
| Nextcloud + PHP (Hetzner) | `hetzner/docker/{nextcloud,full}/docker-compose.yml` | As above; Dependabot (`docker-compose`) |
| Node.js (MCP image) | `mcp-server/Dockerfile`, `Dockerfile.dev` `FROM node:<major>-alpine` | Floating tag, rebuilt upstream. Dependabot proposes majors only |
| Node.js floor | `engines` in `mcp-server/package.json` and `nextcloud-app/package.json` | Manual: raise to the patched release after a Node security release |
| Other compose images | `image:` lines in `docker/{installation,standalone,e2e}/` and `hetzner/docker/*/` | Exact tags; Dependabot (`docker-compose`). PostgreSQL majors are ignored |
| Images on running Hetzner servers | `/opt/aiquila/docker-compose.yml` on each server | Not automatic: see [Getting fixes to deployments](#getting-fixes-to-deployments) |
| Go toolchain | `go` line in `hetzner/go.mod` (CI reads it through `go-version-file`) | Manual: Go security releases and the end of a major's support |
| Go modules | `hetzner/go.mod`, `go.sum` | Dependabot (`gomod`); `govulncheck ./...` |
| npm (MCP server) | `mcp-server/package.json`, `package-lock.json` | Dependabot (`npm`); `npm audit` |
| npm (Nextcloud frontend) | `nextcloud-app/package.json`, `package-lock.json` | Dependabot (`npm`); `npm audit` |
| Composer | `nextcloud-app/composer.json`, `composer.lock` | Dependabot (`composer`); `composer audit` |
| GitHub Actions | `uses:` lines in `.github/workflows/` | Dependabot (`github-actions`) |
| PHP in CI | `php-version` in `test.yml`, `nc-release.yml` | Manual; follows the supported range in `appinfo/info.xml` |
| Node in CI | `node-version` in `test.yml`, `lint.yml`, `mcp-release.yml`, `nc-release.yml`, `hetzner-integration-test.yml` | Manual: a major line; `setup-node` resolves the latest patch |
| Nextcloud range | `<nextcloud min-version max-version>` in `appinfo/info.xml` | Manual: follows the Nextcloud maintenance schedule |

Which images use exact and which floating tags is set by the
[image tag policy](ci-cd.md#docker-image-tags). What Dependabot covers is described
under [Dependency Updates](ci-cd.md#dependency-updates-githubdependabotyml).

## Routine updates

### Cadence

- **Weekly, automatic:** Dependabot PRs for every ecosystem in the inventory, and the
  PHP Images workflow. Review and merge them as they come; grouped minor/patch PRs
  are low risk once CI is green, majors get read on their own.
- **Monthly, manual sweep:** what no bot sees.
  - Rebuild and pull the floating-tag images, so their upstream rebuilds reach the dev
    stack and the published MCP image (`make up` in `docker/installation/` builds with
    `--pull`, see [Updating base images](docker-setup.md#updating-base-images); the MCP
    release build pulls its base).
  - The Go `go` line, the Node `engines` floor and the CI `php-version` / `node-version`
    values against the current upstream releases and support windows.
  - `npm audit`, `composer audit` and `govulncheck ./...` for advisories in transitive
    dependencies, which often need a lockfile refresh rather than a bump.
  - The supported Nextcloud range against Nextcloud's maintenance schedule.

### Order and grouping

Open one tracking issue per round, with one sub-issue and one PR per component:

1. Runtimes with a security release (PHP images, Go toolchain, Node).
2. Docker image pins.
3. Composer, then npm.
4. Anything that changes a supported range (a new PHP or Nextcloud major).

Merge in that order where PRs touch the same files. A PR that bumps pins the Hetzner
CLI embeds goes in before the PR that bumps `hetzner/VERSION`, so the release carries
them.

### When an update cuts a release

| Component | Release trigger | Cut one when |
|---|---|---|
| MCP server (npm, `ghcr.io` image, MCP registry) | `version` in `mcp-server/package.json` | A runtime or dependency fix reaches the published package or image |
| Nextcloud app (app store) | `<version>` in `appinfo/info.xml` and `package.json` | A Composer or frontend fix ships in the tarball |
| Hetzner CLI | `hetzner/VERSION` | The Go toolchain or modules change, or the embedded compose pins change |

Both components share one version number ([Version Bumps](../../CLAUDE.md#version-bumps)),
and an update round is a patch release. Lockfile-only changes to dev dependencies do
not need a release.

### Verification

| Component | Checks |
|---|---|
| MCP server | CI (`npm run lint`, `typecheck`, `npm test`); `npm audit`; for image changes, `make test-tools` against the standalone stack |
| Nextcloud app | CI (PHPUnit, Psalm); `composer audit`; reinstall in the dev stack ([Updating the Nextcloud App](docker-setup.md#updating-the-nextcloud-app)) and check `make nc-log` for level 3/4 entries |
| Hetzner CLI | `go vet ./...`, `go test ./...`, `govulncheck ./...`; the [Hetzner Integration Test](ci-cd.md#hetzner-integration-test-hetzner-integration-testyml) for toolchain or compose changes |
| Images | PHP Images workflow; `node -v` / `php -v` inside the rebuilt containers; the [Docker E2E](ci-cd.md#docker-e2e-e2eyml) run |

## Emergency plan

For a security release upstream (PHP, Node.js, Go, Nextcloud, a dependency) or a
vulnerability reported in AIquila itself.

### Triage

1. **Which components does it reach?** Map the affected package or runtime to the
   [inventory](#inventory). Check how it is used, not only that it is present:
   - PHP-FPM fixes do not affect the `*-apache` images, which run mod_php.
   - A Node.js fix in an API the MCP server never calls is low priority.
   - A Go advisory matters when `govulncheck` reports it as reachable, not only imported.
   - A dev-only dependency (build tools, test runners) does not reach any release.
2. **Severity.** Start from the advisory's rating, then adjust for AIquila's default
   configuration and exposure: the MCP server is internet-facing with OAuth on, the
   Nextcloud app runs inside a Nextcloud that is usually internet-facing too.
3. **Target time to a released fix:**

   | Severity | Example | Released fix within |
   |---|---|---|
   | Critical | Remote code execution, authentication bypass, token theft in the default config | 2 days |
   | High | Exploitable with a valid account, or only in a common non-default config | 7 days |
   | Medium | Needs unusual configuration or local access | Next patch release, at most 30 days |
   | Low | Defence in depth, hardening | Next routine round |

For a vulnerability in AIquila, work in a private
[security advisory](https://docs.github.com/en/code-security/security-advisories/working-with-repository-security-advisories)
and its temporary private fork, not in public branches or issues.

### Fast path

- Open the tracking issue (public for an upstream release; inside the advisory for
  AIquila's own) and a PR per affected component, in parallel.
- Run in parallel: CI on each PR, the PHP Images workflow, `govulncheck`, `npm audit`
  / `composer audit`, and the dev-stack smoke checks. The Hetzner integration test
  and Docker E2E run once the PRs are merged, before announcing.
- One maintainer review per PR. For a critical fix the author may merge after CI is
  green and review afterwards, noting that in the PR.
- Release each affected artifact:

  | Artifact | Steps |
  |---|---|
  | npm `aiquila-mcp`, `ghcr.io/elgorro/aiquila-mcp`, MCP registry | Bump `mcp-server/package.json` (and `server.json`'s image tag); `mcp-release.yml` publishes all three ([MCP Server Release](ci-cd.md#mcp-server-release-mcp-releaseyml)) |
  | Image-only fix (base image rebuilt upstream) | No version bump needed when the published tags already run the fixed runtime: check with `docker run --rm --entrypoint node ghcr.io/elgorro/aiquila-mcp:<tag> -v`. Otherwise cut a patch release |
  | Nextcloud app store tarball | Bump `info.xml` and `package.json`; `nc-release.yml`, then approve the `nextcloud-appstore` environment ([Nextcloud App Release](ci-cd.md#nextcloud-app-release-nc-releaseyml)) |
  | `aiquila-hetzner` binaries | Bump `hetzner/VERSION`; `hetzner-release.yml` ([Hetzner CLI Release](ci-cd.md#hetzner-cli-release-hetzner-releaseyml)) |

### Getting fixes to deployments

A release does not change anything already running.

| Deployment | How it picks up the fix |
|---|---|
| Dev stack (`docker/installation/`) | `make up` (builds with `--pull`) |
| Docker standalone (`docker/standalone/`) | `make update` ([Updating](../mcp/standalone-docker.md#updating)) |
| Hetzner, floating-tag rebuild (Nextcloud/PHP, socket proxy) | `aiquila-hetzner deploy` pulls every image and recreates the stack |
| Hetzner, new AIquila release | `aiquila-hetzner upgrade` ([`upgrade`](../hetzner/commands.md#upgrade)) |
| Hetzner, new third-party pins | `aiquila-hetzner upgrade --images` ([Third-party images](../hetzner/commands.md#third-party-images)) |
| Nextcloud app store users | Nextcloud's app updater; admins see the update under *Apps*, or run `occ app:update aiquila` |
| npm / MCP registry users | Their client fetches the new version; pinned installs need a manual bump |

### Communication

- **AIquila vulnerability:** publish the GitHub security advisory once the fix is
  released, request a CVE where it applies, and credit the reporter
  ([SECURITY.md](../../SECURITY.md#what-to-expect)).
- **Release notes** of each affected component name the fix and what operators must
  do (pull, `upgrade`, `deploy`).
- **Upstream security release:** release notes are enough. A pinned advisory or an
  announcement in GitHub Discussions is warranted when operators must act and the
  severity is high or critical. The app store and MCP registry have no channel to
  notify users beyond the new version.

### Rollback

If a patched image or dependency breaks things:

- **Hetzner:** `upgrade` prints the rollback commands and keeps the previous
  `.env`, `docker-compose.yml` and images in `/opt/aiquila/backups/` and the local
  image cache.
- **MCP image:** run the previous version tag (`ghcr.io/elgorro/aiquila-mcp:<previous>`);
  every release stays published.
- **Nextcloud app:** install the previous release tarball from the GitHub release
  (`nc-v<previous>`); restore the database dump if its migrations already ran.
- **Repository:** revert the PR. Do not revert a security fix without a replacement;
  pin the broken dependency's previous version alongside the fix instead.

## Checklist

Copy into the tracking issue and tick as you go:

```markdown
- [ ] Triage: affected components, reachability, severity, target date
- [ ] (AIquila vulnerability) private security advisory opened, reporter invited
- [ ] PR per component, each green
  - [ ] PHP / Nextcloud images (PHP Images workflow green)
  - [ ] Go toolchain / modules (`govulncheck ./...` clean)
  - [ ] Node.js (`engines` floor, published image runs the fixed version)
  - [ ] Image pins
  - [ ] Composer (`composer audit` clean)
  - [ ] npm (`npm audit` clean in both packages)
- [ ] Docs updated where behaviour or requirements changed
- [ ] Merged in order; releases cut: MCP / Nextcloud app / Hetzner CLI
- [ ] Hetzner integration test and Docker E2E green on the release
- [ ] Deployments refreshed: dev stack, standalone, Hetzner (`deploy` / `upgrade`)
- [ ] Advisory published / release notes name the fix and operator steps
- [ ] Follow-ups filed (anything the round found that is out of scope)
```
