# Contributing to AIquila

Thanks for your interest in AIquila. This guide covers what we expect from a
contribution so your time isn't wasted on rework.

Code isn't the only way to help: reporting issues, improving the docs and
[funding the project](#supporting-the-project) all count.

## Before you write code

**Open an issue first, or comment on an existing one to claim it.**

This is the one request we'd make of every contributor. A short comment saying
what you intend to do lets us confirm the approach before you invest the effort,
and avoids two people solving the same problem twice. For a one-line typo fix,
go straight to a pull request.

If you comment on an issue, we can assign it to you — GitHub only permits
assigning people who are repo collaborators or who have commented on that
specific issue.

## Labels

Issues are classified on three independent axes. There is a template for each
type — bug report, feature request, chore, documentation — and each one applies
its type and a default priority for you, so opening an issue the normal way needs
no label picking.

| Axis | Labels | Who sets it |
|------|--------|-------------|
| Type — what kind of work (exactly one) | `type:bug`, `type:feature`, `type:docs`, `type:chore` | The issue template |
| Component — what it touches (one or more) | `component:mcp`, `component:nextcloud`, `component:infrastructure` | A maintainer during triage |
| Priority — how urgent (exactly one) | `priority:critical`, `priority:high`, `priority:medium`, `priority:low` | A maintainer |

Two more labels cut across all three: `security` marks anything with a security
impact, and `question` marks an issue whose approach is still being decided
rather than implemented.

**Don't set your own priority.** Templates default every new issue to
`priority:medium`; a maintainer adjusts it during triage against everything else
in flight. Nothing is lost by leaving it — if something is genuinely urgent, say
so in the issue body, which carries far more than a label does.

`dependencies`, `javascript`, `go`, `docker` and `github_actions` are applied
automatically by Dependabot. `good first issue` and `help wanted` mark work we'd
welcome an outside contributor picking up.

## Repository layout

A monorepo with two independently versioned components:

| Path | What it is |
|------|------------|
| `mcp-server/` | TypeScript/Node.js MCP server (npm package `aiquila-mcp`) |
| `nextcloud-app/` | Nextcloud app — PHP 8.5, floor 8.4 |
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

## Documentation

**Checking the documentation is part of every change, not an optional extra.**

If your change alters a command, flag, environment variable, endpoint, default or
workflow, update the page that describes it in the same pull request. A change that
leaves `docs/` describing the old behaviour is not finished. If nothing needs
changing, say so in the pull request — that tells a reviewer you looked.

Not sure which page? Ask in the issue and we will point you at it.

**Keep documentation general.** The docs describe how AIquila works now, for someone
reading them a year from now. They are not a changelog or a record of what went
wrong. Please leave out:

- issue or pull request numbers, and "fixed in #504" style narrative
- dated workarounds, "currently broken" notes, or migration detail for one release
- anything that stops being true once the ticket closes

That context is valuable — it just belongs in the GitHub issue or a
[discussion](https://github.com/elgorro/aiquila/discussions), which is where people
will look for it. Link to the issue from the docs if it genuinely helps, rather than
copying its contents in.

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

## Supporting the project

AIquila is free software, developed in the open, and the running costs behind it
are real — test servers, CI minutes, domains and the App Store presence. If the
project is useful to you and you would rather contribute money than time:

- [GitHub Sponsors](https://github.com/sponsors/elgorro)
- [Liberapay](https://liberapay.com/elgorro/donate)

Both are linked from the **Sponsor** button at the top of the repository.
Sponsorship buys no influence over the roadmap and no priority in review — a
well-argued issue does more for either. Supporters who want to be named are
listed in [ACKNOWLEDGMENTS.md](ACKNOWLEDGMENTS.md#supporters).

## Questions

Open a [discussion](https://github.com/elgorro/aiquila/discussions) or ask in the
issue you're working on.
