# Hetzner CI/CD Flow

## Overview

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `hetzner-integration-test.yml` | Manual dispatch | Full two-server E2E test |
| `hetzner-nc-nightly.yml` | _(planned)_ nightly cron | NC image build + smoke test |

---

## Pipeline: Integration Test

### Trigger

`workflow_dispatch` (manual) — the act of dispatching is itself the approval gate. Only team
members with write access to the repository can trigger the workflow.

**Future:** add a GitHub environment protection rule on a `hetzner-test` environment to require
a named reviewer before secrets are exposed.

---

### Step timeline (wall-clock estimates)

| Step | Duration | Notes |
|------|----------|-------|
| Checkout + Go/Node setup | ~1 min | GitHub-hosted runner |
| npm install (script deps) | ~30 s | |
| Build `aiquila-hetzner` binary | ~30 s | |
| Provision NC server (`nc-inttest-*`) | ~8 min | Server create + cloud-init + Docker install + image build + NC first boot |
| Provision MCP server (`mcp-inttest-*`) | ~5 min | Server create + cloud-init + Docker install + compose up |
| AIquila OCC install | ~1 min | tarball download + `occ app:enable` |
| Poll NC ready (`/status.php`) | 0–5 min | Usually done during MCP provision |
| Poll MCP ready (`/.well-known`) | 0–2 min | |
| OAuth test _(group: oauth)_ | ~1 min | Full PKCE flow |
| Tools test _(group: tools)_ | ~2 min | 8 tool operations |
| MCP protocol conformance _(group: mcp_protocol)_ | ~1 min | Raw JSON-RPC assertions on `initialize` + `tools/list` |
| NC app API check _(group: nc_app)_ | <1 min | REST endpoint smoke test |
| MCP-Connector test _(group: connector, off by default)_ | ~2 min | Anthropic API round-trip |
| TLS + CrowdSec _(group: infra)_ | ~1 min | |
| Destroy both servers | ~2 min | Always runs (cleanup step) |
| **Total** | **~27 min** | NC + MCP provision can be parallelised |

> **Parallelism opportunity:** NC and MCP servers can be provisioned concurrently since they are
> independent. The integration-test script currently provisions them sequentially; a future
> optimisation can cut ~5 min off total runtime.

---

### Secrets required

| Secret | Used for |
|--------|----------|
| `HCLOUD_TOKEN` | Server provisioning and destroy |
| `HETZNER_DNS_TOKEN` | DNS A/AAAA record creation |
| `ANTHROPIC_API_KEY` | Drives the Claude agent; also used by the connector test if enabled |

---

### Inputs

#### Infrastructure

| Input | Default | Description |
|-------|---------|-------------|
| `nc_domain` | _(required)_ | FQDN for the NC test server |
| `mcp_domain` | _(required)_ | FQDN for the MCP test server |
| `dns_zone` | _(required)_ | Hetzner DNS zone (e.g. `example.com`) |
| `nc_server_type` | `cpx21` | NC server — 4 vCPU, 8 GB RAM |
| `mcp_server_type` | `cpx11` | MCP server — 2 vCPU, 2 GB RAM |

#### Test group toggles (boolean)

| Input | Default | Description |
|-------|---------|-------------|
| `run_oauth_test` | `true` | OAuth PKCE flow (step 8) |
| `run_tools_test` | `true` | MCP tools functional test (step 9) |
| `run_mcp_protocol_test` | `true` | MCP JSON-RPC conformance assertions (step 10) |
| `run_nc_app_test` | `true` | NC AIquila app REST smoke test (step 11) |
| `run_connector_test` | `false` | MCP-Connector round-trip — uses Anthropic API (step 12) |
| `run_infra_test` | `true` | TLS certificate + CrowdSec checks (steps 13–14) |

---

### Cost per run

| Resource | Rate | Per run (~30 min) |
|----------|------|-------------------|
| Hetzner cpx21 (NC) | ~€0.004/h | < €0.003 |
| Hetzner cpx11 (MCP) | ~€0.002/h | < €0.002 |
| Anthropic API — Claude agent | ~$0.10–$0.50 | varies; dominates |
| Anthropic API — connector test | ~$0.05–$0.20 | only when `run_connector_test=true` |
| **Total** | | **< €0.01 infra; API costs dominate** |

Infrastructure costs are essentially negligible. The Anthropic API call that drives the Claude
agent is the dominant cost (~$0.10–$0.50 per run). Enabling the connector test adds a further
~$0.05–$0.20 for the Anthropic API round-trip.

---

### Safety net (orphan cleanup)

The workflow's final step runs unconditionally (`if: always()`) and greps for
`nc-inttest-|mcp-inttest-` server names, destroying any survivors if the agent step crashed
mid-run. This prevents runaway billing from forgotten servers.

---

## Roadmap: Nightly Nextcloud Builds

**Motivation:** NC releases patch versions frequently. A nightly build verifies:

- The PHP 8.4 Dockerfile upgrade still compiles cleanly with the latest NC33 patch image
- The AIquila app installs and enables without errors (`occ app:enable aiquila`)
- The NC33 `metrics` app enables and its `/metrics?token=` endpoint responds
- No regressions in the NC → MCP integration path

**Proposed workflow: `.github/workflows/hetzner-nc-nightly.yml`**

```yaml
on:
  schedule:
    - cron: '0 3 * * *'   # 3 AM UTC daily
```

Steps:
1. Provision NC server (`--stack nextcloud`, `cpx11` to minimise cost)
2. Verify: `/status.php` returns `{"installed":true}`, AIquila OCS API responds,
   `/metrics?token=<token>` endpoint responds with Prometheus output
3. Snapshot the server (`aiquila-hetzner snapshot --name nc-nightly-<date>`)
4. Destroy server
5. Prune snapshots older than 7 days

**Benefit:** Snapshots can serve as fast-start bases for integration tests, skipping the
~5-minute NC first-boot step and reducing total integration test time to ~15 min.

**Estimated cost:**
- Server: < €0.01/night for the cpx11 run (~20 min)
- Snapshot storage: ~€0.012/GB/month (a Nextcloud snapshot is typically 2–4 GB)

**Status: planned — not yet implemented.**
