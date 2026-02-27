# Hetzner Integration Test

## Overview

The integration test provisions two real Hetzner Cloud servers — a Nextcloud server with the
AIquila app installed and an MCP server pointing at it — runs a full test suite against the live
HTTPS endpoint, then destroys both servers.

It is driven by `hetzner/scripts/integration-test.ts`, which uses the
`@anthropic-ai/claude-code-sdk` `query()` API so a Claude agent executes every step via bash
commands, streaming progress to stderr for human-readable CI logs.

---

## Test groups

The suite is split into six independently togglable groups. Five are **on by default**; the
connector test (which calls the Anthropic API a second time) is **off by default**.

| Group | Env var | Default | Steps |
|-------|---------|---------|-------|
| `oauth` | `RUN_OAUTH_TEST` | `true` | 8 — full OAuth PKCE flow |
| `tools` | `RUN_TOOLS_TEST` | `true` | 9 — MCP tool operations |
| `mcp_protocol` | `RUN_MCP_PROTOCOL_TEST` | `true` | 10 — raw JSON-RPC conformance assertions |
| `nc_app` | `RUN_NC_APP_TEST` | `true` | 11 — AIquila app REST endpoint smoke test |
| `connector` | `RUN_CONNECTOR_TEST` | `false` | 12 — MCP-Connector round-trip (Anthropic API) |
| `infra` | `RUN_INFRA_TEST` | `true` | 13–14 — TLS certificate + CrowdSec container |

Groups that are disabled emit `SKIP — <label>` so the agent marks them in the final summary.
The PASS/FAIL/SKIP summary determines the workflow exit code.

---

## Test steps

1. Build the `aiquila-hetzner` binary from source.
2. Choose a shared timestamp suffix (`nc-inttest-<ts>`, `mcp-inttest-<ts>`).
3. Provision the **Nextcloud server** (`--stack nextcloud`, server type `NC_SERVER_TYPE`).
4. Provision the **MCP server** (`--stack mcp`, server type `MCP_SERVER_TYPE`), pointed at the NC server.
5. Poll `https://<NC_DOMAIN>/status.php` until `{"installed":true}` (up to 5 min).
6. Verify AIquila app is installed on NC via OCS API.
7. Poll `https://<MCP_DOMAIN>/.well-known/oauth-authorization-server` until ready (up to 5 min).
8. **[oauth]** Run `docker/standalone/scripts/test-oauth.sh` — full PKCE flow.
9. **[tools]** Run `docker/standalone/scripts/test-tools.sh` — end-to-end MCP tool operations.
10. **[mcp_protocol]** Send raw JSON-RPC requests and assert on response fields:
    - `initialize` → assert `protocolVersion == "2025-03-26"`, `serverInfo.name == "aiquila"`, `capabilities.tools` exists
    - `tools/list` → assert non-empty array containing `system_status`, `list_files`, `create_folder`, `write_file`, `read_file`, `delete`
11. **[nc_app]** Verify AIquila app REST endpoints with NC basic auth:
    - `GET /apps/aiquila/api/settings` → HTTP 200 with JSON body
    - `GET /ocs/v2.php/apps/aiquila` → HTTP 200 containing `"aiquila"`
12. **[connector]** Run `mcp-server/scripts/test-mcp-connector.ts` — Anthropic API round-trip via MCP.
13. **[infra]** `curl -sI https://<MCP_DOMAIN>/mcp` → HTTP 4xx with valid TLS (no cert error).
14. **[infra]** SSH into MCP server → confirm `aiq-crowdsec` container is running.
15. Destroy both servers and their DNS records — **always runs, even if earlier steps fail**.

---

## How it works

`integration-test.ts` builds the prompt at runtime:

1. Each step body (`STEP_8_TEXT` … `STEP_13_14_TEXT`) is inserted via `.replace()` — or
   replaced with a `SKIP` message based on `testConfig`.
2. Domain and zone placeholders (`NC_DOMAIN`, `MCP_DOMAIN`, `DNS_ZONE`, …) are expanded via
   `.replaceAll()` over the fully assembled prompt.

The step texts are inserted **before** domain substitution so that domain placeholders inside
step bodies are also expanded correctly.

The agent is called with:

- `allowedTools: ["Bash"]` — shell commands only.
- `permissionMode: "bypassPermissions"` — no interactive confirmations.
- `cwd: "/workspace"` — the GitHub Actions checkout root.

Streamed message events:

- `assistant` → `text` blocks written to stderr (human-readable CI logs).
- `result` → `is_error: true` or result text containing `FAIL` → exit 1; otherwise exit 0.

A safety-net step in the workflow (`if: always()`) calls `aiquila-hetzner list` and destroys
any lingering `nc-inttest-*` / `mcp-inttest-*` servers in case the agent step crashes mid-run.

---

## Triggering via GitHub Actions

```
Actions → Hetzner Integration Test → Run workflow
```

### Infrastructure inputs

| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `nc_domain` | yes | — | FQDN for the Nextcloud test server |
| `mcp_domain` | yes | — | FQDN for the MCP test server |
| `dns_zone` | yes | — | Hetzner DNS zone (e.g. `example.com`) |
| `nc_server_type` | no | `cpx21` | Hetzner server type for NC (4 vCPU / 8 GB) |
| `mcp_server_type` | no | `cpx11` | Hetzner server type for MCP (2 vCPU / 2 GB) |

### Test group toggles

| Input | Default | Description |
|-------|---------|-------------|
| `run_oauth_test` | `true` | OAuth PKCE flow |
| `run_tools_test` | `true` | MCP tools functional test |
| `run_mcp_protocol_test` | `true` | MCP JSON-RPC conformance assertions |
| `run_nc_app_test` | `true` | NC AIquila app REST smoke test |
| `run_connector_test` | `false` | MCP-Connector (requires Anthropic API; adds cost) |
| `run_infra_test` | `true` | TLS certificate + CrowdSec checks |

---

## Required GitHub secrets

| Secret | Description |
|--------|-------------|
| `HCLOUD_TOKEN` | Hetzner Cloud API token |
| `HETZNER_DNS_TOKEN` | Hetzner DNS token (A record creation/deletion) |
| `ANTHROPIC_API_KEY` | Drives the Claude agent; also used by the connector test if enabled |

---

## Running locally

```bash
export HCLOUD_TOKEN=...
export HETZNER_DNS_TOKEN=...
export ANTHROPIC_API_KEY=...
export NC_DOMAIN=nc-inttest.example.com
export MCP_DOMAIN=mcp-inttest.example.com
export DNS_ZONE=example.com
export NC_SERVER_TYPE=cpx21   # optional, default cpx21
export MCP_SERVER_TYPE=cpx11  # optional, default cpx11

# Override group defaults if needed
export RUN_CONNECTOR_TEST=true   # off by default
export RUN_INFRA_TEST=false      # skip for quick dev runs

cd hetzner/scripts
npm install
npx tsx integration-test.ts
```

> **Note:** The agent's `cwd` is hard-coded to `/workspace` (the GitHub Actions checkout path).
> When running locally, run the command from the repository root so that relative paths like
> `docker/standalone/scripts/test-oauth.sh` resolve correctly, or temporarily adjust `cwd` in
> `integration-test.ts`.

---

## Cost

| Resource | Notes |
|----------|-------|
| Hetzner cpx21 (NC, ~25 min) | < €0.003 |
| Hetzner cpx11 (MCP, ~25 min) | < €0.002 |
| Anthropic API — Claude agent | ~$0.10–$0.50 per run (dominant cost) |
| Anthropic API — connector test | additional ~$0.05–$0.20 if `run_connector_test=true` |
