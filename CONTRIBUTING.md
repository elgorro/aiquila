# Contributing to AIquila

Thanks for your interest in AIquila. This guide covers what we expect from a
contribution so your time isn't wasted on rework.

Code isn't the only way to help: reporting issues, improving the docs and
[funding the project](#supporting-the-project) all count.

**Found a security problem?** Don't open an issue — report it privately as
described in [SECURITY.md](SECURITY.md). How maintainers keep dependencies current
and respond to security releases is in
[Updates and security releases](docs/dev/update-plan.md).

## Before you write code

**Open an issue first, or comment on an existing one to claim it.**

This is the one request we'd make of every contributor. A short comment saying
what you intend to do lets us confirm the approach before you invest the effort,
and avoids two people solving the same problem twice. For a one-line typo fix,
go straight to a pull request.

**Make the case.** An issue says why it's worth doing and, if you have an approach in
mind, why it would work. A pull request says both. One or two plain sentences are
enough, backed by something we can check: a reproduction, a log line, an upstream
reference, a trial run. You don't need a solution to report a problem.

If you comment on an issue, we can assign it to you — GitHub only permits
assigning people who are repo collaborators or who have commented on that
specific issue.

## Labels

Issues are classified on three independent axes. There is a form for each
type — bug report, feature request, chore, documentation — and each one applies
its type and a default priority for you, so opening an issue the normal way needs
no label picking. Questions and setup help go to
[Q&A](https://github.com/elgorro/aiquila/discussions/new?category=q-a), early ideas to
[Ideas](https://github.com/elgorro/aiquila/discussions/new?category=ideas).

| Axis | Labels | Who sets it |
|------|--------|-------------|
| Type — what kind of work (exactly one) | `type:bug`, `type:feature`, `type:docs`, `type:chore` | The issue form |
| Component — what it touches (one or more) | `component:mcp`, `component:nextcloud`, `component:infrastructure` | A maintainer during triage |
| Priority — how urgent (exactly one) | `priority:critical`, `priority:high`, `priority:medium`, `priority:low` | A maintainer |

Two more labels cut across all three: `security` marks anything with a security
impact, and `question` marks an issue whose approach is still being decided
rather than implemented.

**Don't set your own priority.** Forms default every new issue to
`priority:medium`; a maintainer adjusts it during triage against everything else
in flight. Nothing is lost by leaving it — if something is genuinely urgent, say
so in the issue body, which carries far more than a label does.

`dependencies`, `javascript`, `php`, `go`, `docker` and `github_actions` are applied
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
npm run lint                # eslint (warnings ok, errors fail CI; `any` is an error outside tests)
npm run typecheck           # tsc --noEmit (the build skips type checking)
npm run format              # prettier --write src/
npx prettier --check src/   # check only — run before committing
npm run catalog             # regenerate nextcloud-app/lib/Resources/sequence-catalog.json after changing a tool
```

**CI requires `npm run lint`, `npm run typecheck` and `npx prettier --check src/` to pass.**
Always run `npm run format` after editing TypeScript.

Tests live in `mcp-server/src/__tests__/`. New behaviour needs a test that fails
without your change — if a test passes both before and after, it isn't testing
your fix.

Do not use `--no-verify` to skip hooks.

## Nextcloud app

Psalm runs over `lib/` at `errorLevel=3` and **CI fails on any error** from it or from
the tests:

```bash
cd nextcloud-app
composer test
composer psalm
```

There is no baseline — fix findings rather than adding one.

After editing a controller annotated with `#[OpenAPI]`, regenerate and commit the
spec (CI fails on a mismatch):

```bash
cd nextcloud-app && composer openapi   # updates openapi.json, openapi-administration.json, openapi-full.json
```

The specs are OpenAPI 3.1.0. `composer openapi` runs the generator and then rewrites
its output into 3.1 form. Running `vendor/bin/generate-spec` on its own writes 3.0.3,
and CI reports a mismatch.

## Hetzner CLI

```bash
cd hetzner
go vet ./...
go test ./...
```

## Documentation

**Checking the documentation is part of every change, not an optional extra.**

If your change alters a command, flag, environment variable, endpoint, default or
workflow, update the page that describes it in the same pull request. A change that
leaves `docs/` describing the old behaviour is not finished. If nothing needs
changing, say so in the pull request — that tells a reviewer you looked.

Where each kind of change is documented:

| Changed | Update |
|---|---|
| Workflows, CI behaviour | `docs/dev/ci-cd.md` |
| MCP tools | `docs/mcp/tools/**`, the tool table in `docs/mcp/README.md`, and the category's row in `docs/mcp/upstream-apis.md` |
| Env vars, transports, auth | `docs/mcp/setup.md` |
| Nextcloud endpoints | `docs/dev/openapi.md`, plus regenerating the spec |
| Install or deploy steps | `docs/installation/**`, `docs/hetzner/**` |
| How to propose a change | `CONTRIBUTING.md` |

Not sure which page? Ask in the issue and we will point you at it.

### Upstream APIs

Most of AIquila wraps APIs that belong to someone else: Nextcloud server components,
Nextcloud apps, and the LLM providers. [`docs/mcp/upstream-apis.md`](docs/mcp/upstream-apis.md)
maps each MCP tool category to the app and API it calls, the upstream reference it
follows, when it was `Introduced`, and when it was `Last verified`.

Before working on a tool category, read its row:

- Check your implementation against the linked reference for the upstream version
  you target, not against the existing code. A `Last verified` of `—`, or one older
  than the current Nextcloud major, means the code may follow an API that has moved.
- In the same pull request, update `Last verified` (the app version from
  `occ app:list`, the Nextcloud version from `occ status`, and the date) for every
  category whose behaviour you exercised, and fix the reference link if it moved.
- A new category gets a new row, with `Introduced` filled in.

Where behaviour follows a protocol or format (OAuth, HTTP, WebDAV, iCalendar, vCard,
JSON Schema, MCP), check it against the specification itself rather than another
client's behaviour, and cite the section in a code comment or the pull request.

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

Fill in the pull request template. A few words per topic is plenty, and a draft is a good
way to ask for feedback early. It asks for:

| Section | Why |
|---|---|
| Why it's worth it | The problem, who has it, and why solving it justifies code someone has to maintain |
| Why this approach works | How it solves the cause rather than the symptom, what you ruled out, and where its limits are |
| What users will notice | Someone has to upgrade to your change. Say what is different for them, and what they have to do |
| How I checked it | Concrete evidence, such as the failing output before your fix and the passing run after, says more than a ticked box |
| Docs | Which pages changed, or that you looked and none needed to |
| AI involvement | See [AI-assisted contributions](#ai-assisted-contributions) |
| Worth a closer look | Where a mistake would cost users most: their data, their access, their setup. A few words on each topic that applies, delete the rest |

The merged commit message is taken from the pull request title and description, so give
the title the same [Conventional Commits](#branches-and-commits) form.

Put a **closing keyword** in the description so the issue closes on merge:

```
Closes #184      (or Fixes #184 / Resolves #184)
```

A bare mention like `(#184)` links the issue but does **not** close it.

### A note on CI for external contributions

Pull requests from forks need a maintainer to approve the workflow runs before
CI starts, so there may be a short delay before checks appear.

The automatic code review cannot run on a fork pull request — GitHub does not
issue the credentials it needs in that context, so you may see that one check
red through no fault of your own. It does not block your pull request. A
maintainer triggers the review manually instead, so there is nothing you need to
do about it.

## AI-assisted contributions

These are welcome, from autocomplete to agents working on their own. Just mark it: the
issue forms and the pull request template ask for one of four levels.

| Level | Meaning |
|---|---|
| No AI | None, or only autocomplete and spell-checking |
| Assisted | You did the work, AI helped |
| Semi-attended | AI did the work, you checked it |
| Unattended | AI did the work, nobody checked it yet |

The level is context for the reviewer, not a mark against the contribution: it tells us
how closely to check. A few words on the tool and what it did are a nice extra. Tests,
docs and review are the same at every level.

Coding agents will find their instructions in [AGENTS.md](AGENTS.md).

## Licence

The Nextcloud app (`nextcloud-app/`) is AGPL-3.0-or-later, everything else is MIT. Your
contribution is licensed under the terms of the directory it changes, and you keep your
copyright. There is no CLA to sign. Please only submit work you have the right to
contribute, and name the source and licence of any code you copied.

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
