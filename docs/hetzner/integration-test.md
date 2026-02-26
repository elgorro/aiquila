# Hetzner Integration Test

## Overview

The integration test is a Claude Agent SDK runner (`hetzner/scripts/integration-test.ts`)
that provisions a real Hetzner Cloud server, deploys AIquila on it, runs the full OAuth
and MCP tools test suite against the live HTTPS endpoint, verifies Traefik TLS and CrowdSec
health, then destroys the server. It uses the `@anthropic-ai/claude-code-sdk` `query()` API
so a Claude agent drives every step via bash commands, streaming progress to stderr for
human-readable CI logs.

---

## Test steps

1. Build the `aiquila-hetzner` binary from source.
2. Provision a test server named `aiquila-inttest-<timestamp>` using `aiquila-hetzner create`.
3. Poll `https://<domain>/.well-known/oauth-authorization-server` until it responds (up to 5 minutes).
4. Run the OAuth PKCE flow via `docker/standalone/scripts/test-oauth.sh https://<domain>`.
5. Run the MCP tools end-to-end suite via `docker/standalone/scripts/test-tools.sh https://<domain>`.
6. Verify Traefik TLS: `curl -sI https://<domain>/mcp` must return an HTTP 4xx with a valid certificate.
7. Verify CrowdSec: SSH into the server and confirm `docker ps | grep crowdsec` shows a running container.
8. Destroy the server and its DNS record (always runs, even when earlier steps fail).

---

## How it works

`integration-test.ts` passes a detailed prompt to the `query()` API with:

- `allowedTools: ["Bash"]` — the agent may only run shell commands.
- `permissionMode: "bypassPermissions"` — no interactive confirmations.
- `cwd: "/workspace"` — the GitHub Actions checkout root.

The runner processes the streamed message events:

- `assistant` messages — `text` blocks are written to stderr so CI logs are human-readable.
- `result` message — checked for success:
  - `is_error: true` → exit 1
  - result text containing `FAIL` (case-insensitive) → exit 1
  - otherwise → exit 0

A separate safety-net step in the workflow (`if: always()`) calls `aiquila-hetzner list`
and destroys any `aiquila-inttest-*` servers that the agent may have failed to clean up.

---

## Triggering via GitHub Actions

The workflow is triggered manually via `workflow_dispatch`:

```
Actions → Hetzner Integration Test → Run workflow
```

| Input | Required | Default | Example |
|-------|----------|---------|---------|
| `test_domain` | yes | — | `inttest.example.com` |
| `dns_zone` | yes | — | `example.com` |
| `server_type` | no | `cpx11` | `cpx21` |

---

## Required GitHub secrets

| Secret | Description |
|--------|-------------|
| `HCLOUD_TOKEN` | Hetzner Cloud API token |
| `HETZNER_DNS_TOKEN` | Hetzner DNS token (for A record creation/deletion) |
| `NEXTCLOUD_URL` | Existing Nextcloud instance URL |
| `NEXTCLOUD_USER` | Nextcloud username |
| `NEXTCLOUD_PASSWORD` | Nextcloud app password |
| `ANTHROPIC_API_KEY` | Anthropic API key (for the Claude agent) |

---

## Running locally

```bash
export HCLOUD_TOKEN=...
export HETZNER_DNS_TOKEN=...
export NEXTCLOUD_URL=...
export NEXTCLOUD_USER=...
export NEXTCLOUD_PASSWORD=...
export ANTHROPIC_API_KEY=...
export TEST_DOMAIN=inttest.example.com
export DNS_ZONE=example.com
export SERVER_TYPE=cpx11

cd hetzner/scripts
npm install
npx tsx integration-test.ts
```

> **Note:** The agent's `cwd` is set to `/workspace` (the GitHub Actions checkout path).
> When running locally, the agent has bash access and will adapt — run the command from the
> repository root so that relative paths like `docker/standalone/scripts/test-oauth.sh` resolve
> correctly, or set a `WORKSPACE` environment variable pointing to the repo root if you need
> to run from a different directory.

---

## Cost

- **Hetzner:** a `cpx11` server runs for roughly 10 minutes ≈ €0.001.
- **Anthropic API:** one agent run ≈ $0.10–$0.50 depending on the number of turns the agent takes.
