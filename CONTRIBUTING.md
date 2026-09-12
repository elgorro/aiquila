# Contributing to AIquila

Thanks for your interest in AIquila. This guide covers what we expect from a
contribution so your time isn't wasted on rework.

## Before you write code

**Open an issue first, or comment on an existing one to claim it.**

This is the one request we'd make of every contributor. A short comment saying
what you intend to do lets us confirm the approach before you invest the effort,
and avoids two people solving the same problem twice. For a one-line typo fix,
go straight to a pull request.

If you comment on an issue, we can assign it to you — GitHub only permits
assigning people who are repo collaborators or who have commented on that
specific issue.

## Repository layout

A monorepo with two independently versioned components:

| Path | What it is |
|------|------------|
| `mcp-server/` | TypeScript/Node.js MCP server (npm package `aiquila-mcp`) |
| `nextcloud-app/` | PHP 8.4 Nextcloud app |
| `hetzner/` | Go CLI (`aiquila-hetzner`) for provisioning |
| `docs/` | Documentation |
| `docker/` | Dev (`installation/`) and prod (`standalone/`) environments |

## Branches and commits

Always work on a branch — never commit directly to `main`.

```bash
git checkout -b feat/<short-description>   # or fix/, chore/, docs/
```

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)
with a scope:

- `feat(mcp):` / `fix(mcp):` / `chore(mcp):` — MCP server
- `feat(nextcloud):` / `fix(nextcloud):` — Nextcloud app
- `feat(hetzner):` / `fix(hetzner):` — Hetzner CLI
- `docs:` / `chore:` — cross-cutting

Prefer several focused commits in one pull request over splitting related work
across multiple pull requests.

## MCP server

All commands run from `mcp-server/`:

```bash
npm run build               # compile TypeScript → dist/
npm run dev                 # hot reload via tsx
npm test                    # vitest unit tests
npm run lint                # eslint (warnings ok, errors fail CI)
npm run format              # prettier --write src/
npx prettier --check src/   # check only — run before committing
```

**CI requires both `npm run lint` and `npx prettier --check src/` to pass.**
Always run `npm run format` after editing TypeScript.

Tests live in `mcp-server/src/__tests__/`. New behaviour needs a test that fails
without your change — if a test passes both before and after, it isn't testing
your fix.

Do not use `--no-verify` to skip hooks.

## Nextcloud app

Psalm runs over `lib/` at `errorLevel=3` and **CI fails on any error**:

```bash
cd nextcloud-app && composer psalm
```

There is no baseline — fix findings rather than adding one.

After editing a controller annotated with `#[OpenAPI]`, regenerate and commit the
spec (CI fails on a mismatch):

```bash
cd nextcloud-app && vendor/bin/generate-spec
```

## Pull requests

Fill in the pull request template, and put a **closing keyword** in the
description so the issue closes on merge:

```
Closes #184      (or Fixes #184 / Resolves #184)
```

A bare mention like `(#184)` links the issue but does **not** close it.

Describe how you verified the change. Concrete evidence — the failing output
before your fix, the passing run after — is much more useful than a checked box.

### A note on CI for external contributions

Pull requests from forks need a maintainer to approve the workflow runs before
CI starts, so there may be a short delay before checks appear.

The automatic code review cannot run on a fork pull request — GitHub does not
issue the credentials it needs in that context, so you may see that one check
red through no fault of your own. It does not block your pull request. A
maintainer triggers the review manually instead, so there is nothing you need to
do about it.

## AI-assisted contributions

These are welcome. If you used an AI assistant meaningfully, please say so in
the pull request description or commit trailers — it's useful context, not a
mark against the contribution.

The bar is the same either way: you are responsible for the code you submit.
Understand what it does, verify it actually works, and don't open a pull request
you couldn't explain or defend in review.

## Questions

Open a [discussion](https://github.com/elgorro/aiquila/discussions) or ask in the
issue you're working on.
