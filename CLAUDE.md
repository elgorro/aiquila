# AIquila — Claude Code Configuration

## Project Overview

Monorepo with two independently versioned components:
- **`mcp-server/`** — TypeScript/Node.js MCP server (npm, versioned in `package.json`)
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

## Version Bumps

- MCP server version: `mcp-server/package.json` → `"version"`
- Nextcloud app version: `nextcloud-app/appinfo/info.xml` → `<version>`
- Both are versioned independently, but always bump to **one above whichever component is currently highest** — so they stay on the same number after each release.

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
