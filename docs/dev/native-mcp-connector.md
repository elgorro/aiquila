# Native MCP connector

AIquila's default tool-execution path runs an agentic loop in PHP:
`ChatController` / `ConversationController` collect tool definitions from
`McpClientService::getAllTools()`, hand them to
`ClaudeSDKService::chatWithTools()` / `chatWithToolsStream()`, and dispatch
each `tool_use` block back to MCP via `McpClientService::executeTool()`
until the model emits `end_turn`.

The **native MCP connector** short-circuits that loop: the provider's cloud
connects to the MCP servers itself. AIquila makes one API call and persists
the result. `chatWithNativeMcp()` unpacks the response into the same
generator event shape (`text_delta` / `tool_use` / `tool_result` / `done` /
`error`) as the local-loop streaming path.

Providers report support through `supportsNativeMcp()`:

| Provider | Mechanism |
|----------|-----------|
| Claude (Anthropic API), Claude Platform on AWS, Claude on Google Cloud (Anthropic managed) | Beta header `mcp-client-2025-11-20` and the `mcp_servers` parameter on `$client->beta->messages->createStream()`, built by `NativeMcpService::buildServerDefinitions()`. The response carries `mcp_tool_use` and `mcp_tool_result` blocks inline. |
| Mistral | Pre-registered connectors on the Conversations API, built by `NativeMcpService::buildMistralConnectorTools()` from `mistral_connector_ids`. See [Mistral provider](mistral-provider.md#native-mcp-connector-mistral-connectors--conversations-api). |
| Bedrock, Vertex AI, Microsoft Foundry, OpenAI-compatible providers | Not supported. The local loop always runs. |

The rest of this page describes the Anthropic path.

## When this works

- Each MCP URL **must be reachable from Anthropic over HTTPS**. LAN-only
  installs, VPN-only installs, and self-signed-cert installs cannot use
  this path — Anthropic will fail to connect and the request errors out.
  AIquila's reachability probe (admin settings → "Refresh reachability")
  HEADs the URL from the Nextcloud server, which proves the URL resolves
  but does **not** prove Anthropic can reach it.
- The feature is **beta** and **not Zero Data Retention eligible**.
  Strict-data-retention deployments must leave the flag off.
- Plaintext `http://` URLs are filtered out of the descriptor list — both
  because Anthropic requires HTTPS and because forwarding a bearer token
  over plaintext leaks credentials.

## Configuration

MCP servers (`aiquila_mcp_servers`) are configured by the admin and apply to the
whole instance: the table has no user column, and every user's request
forwards the same enabled servers. The only per-user setting is whether the
native path is used at all (below).

### Admin defaults (Settings → Administration → AIquila)

| Field | Storage | Notes |
|-------|---------|-------|
| Enable native MCP connector | `appconfig.aiquila.native_mcp_enabled` (`'1'` / `'0'`, default `'0'`) | Global default for users who have not set an override. |
| Extra MCP URL | `appconfig.aiquila.native_mcp_extra_url` | Optional. Forwarded in addition to the enabled `aiquila_mcp_servers` rows. Use only if HTTPS-public-reachable. |
| Extra MCP bearer token | Nextcloud credentials manager (`aiquila/native_mcp_extra_token`) | Token used as `authorization_token` on the extra URL above. Encrypted at rest by Nextcloud's credential manager. |

### Per-user override (Personal → AIquila)

`userconfig.<uid>.aiquila.native_mcp_enabled`:

| Value | Effect |
|-------|--------|
| `''` (default) | Inherit admin default. |
| `'1'` | Use native connector when at least one HTTPS server is configured. |
| `'0'` | Force the local agentic loop, regardless of admin default. |

The effective value is resolved by `NativeMcpService::isEnabledForUser()`.

## Token strategy

We do not mint new short-lived bearer tokens for each request. Instead we
forward the existing per-server credentials already stored against each
`McpServer` row:

- `auth_type = 'oauth2'` → decrypts and forwards `oauth_access_token`. An
  expired token is refreshed first through `McpClientService::refreshOAuthToken()`,
  under the same per-server lock as the direct path, because the provider calls
  the server on its own and cannot refresh it. A server whose refresh fails, or
  that has no access token, is left out of `mcp_servers` and a warning is logged.
- `auth_type = 'bearer'` → decrypts and forwards `auth_token`.
- `auth_type = 'none'` → no `authorization_token` field.

This reuses the same scope the MCP server already grants AIquila during a
direct PHP-driven tool call; no new audience expansion is performed. Because
the credentials belong to the server row, not to a user, every user's
request reaches the MCP server under the identity that signed in or whose
token was entered. Admins
who want a narrower scope should configure a dedicated OAuth client on the
MCP server with reduced scopes and use a separate `McpServer` entity for it.

## Dispatch logic

Both controllers prefer the native path when it is feasible:

```
supportsNativeMcp() && isEnabledForUser($userId) && <descriptors> !== []
    → chatWithNativeMcp[Collect]()
otherwise
    → chatWithTools[Stream]()
```

`<descriptors>` is `buildMistralConnectorTools()` on Mistral and
`buildServerDefinitions()` everywhere else. `buildServerDefinitions()` filters out non-HTTPS URLs and OAuth servers
without a usable token, so the dispatch is self-protecting: an HTTP-only MCP
environment will silently fall back to the local loop even with the flag on.

## Tradeoffs

- **Visibility**: per-tool PHP-side rate limiting and structured per-tool
  logging are lost. Anthropic logs the tool calls server-side instead.
- **Error surface**: a flaky MCP server now surfaces as an opaque API-side
  error rather than a controllable PHP exception.
- **Auth scope**: forwarding the existing access token is simpler but
  broader than minting per-request scoped tokens. See "Token strategy"
  above for how to narrow it.

## Out of scope

The PHP agentic loop stays. It remains the fallback whenever the flag is
off, no MCP server is HTTPS-reachable, or the deployment opts out of the
beta. We do **not** plan to remove `chatWithTools()` /
`chatWithToolsStream()` while the connector is beta.

Moving the loop itself to Anthropic — Managed Agents sessions rather than
the `mcp_servers` parameter — is a separate question, and a settled one:
see [Managed Agents](managed-agents.md).

## Smoke testing

`docker/standalone/scripts/test-native-mcp.sh` parallels `test-tools.sh`:
it enables the flag via `OCC config:app:set`, sends a chat request that
should trigger an MCP tool call, and asserts the response contains an
`mcp_tool_use` block. Skips with a warning if the configured MCP URL is
not HTTPS-reachable from outside Nextcloud.
