# Mistral provider & multi-provider support

AIquila supports more than one LLM backend for its chat experience. Users (and
admins) can choose between **Claude (Anthropic)** and **Mistral**. This document
covers how the provider abstraction works (issue #138 / #139) and how to verify
that the AIquila MCP server still works when Mistral drives the tools (issue #136).

## Provider abstraction (Nextcloud app)

All chat paths go through `LLMProviderInterface`
(`nextcloud-app/lib/Service/Provider/LLMProviderInterface.php`), resolved at
runtime by `LLMProviderFactory`:

- **`ClaudeSDKService`** implements the interface (provider id `anthropic`),
  using the official Anthropic PHP SDK. Supports the native MCP connector.
- **`MistralProvider`** (provider id `mistral`) talks to
  `https://api.mistral.ai/v1` via Nextcloud's `IClientService`. It translates the
  app's canonical Anthropic-style message/tool blocks to Mistral's
  OpenAI-compatible wire format internally, so controllers and `McpClientService`
  stay provider-agnostic. By default it drives tools with the **local agentic
  loop** (`/v1/chat/completions` + function calling). It also supports a
  **native MCP connector** path (see below).

### Native MCP connector (Mistral Connectors + Conversations API)

When the native-MCP flag is on, `MistralProvider::chatWithNativeMcp()` hands the
conversation to Mistral's **Conversations API** (`POST /v1/conversations`) with
pre-registered MCP **connectors** attached as tools
(`{type:'connector', connector_id}`). Mistral calls each connector directly and
streams `tool.execution.*` / `message.output.delta` events back, which the
provider maps to AIquila's canonical event shape — the same shape the Anthropic
native path and the local loop emit.

Two differences from Anthropic's native MCP matter:

- **Connectors are pre-registered, not inline.** Unlike Anthropic (which accepts
  an inline `mcp_servers` URL + per-request `authorization_token`), Mistral
  connectors are persistent workspace objects. The **admin registers** the
  AIquila MCP server as a connector in the [Mistral console](https://console.mistral.ai/)
  (its auth goes in the connector's `headers`) and pastes the connector ID(s)
  into AIquila admin settings (`mistral_connector_ids`, surfaced by
  `NativeMcpService::buildMistralConnectorTools()`). AIquila never creates or
  mutates connectors.
- **Admin workspace key.** Connectors are scoped to the Mistral API key that
  registered them, so this path always authenticates with the **app-level (admin)
  Mistral key** (`getApiKey(null)`), regardless of any per-user key. The local
  loop still honours per-user keys.

If no connector ID is configured (or the admin key is missing), the native path
yields an error and `ChatController`/`ConversationController` transparently fall
back to the local agentic loop.

`LLMProviderFactory::getActiveProviderId()` precedence: per-user override
(`user_provider`) → admin default (`provider`) → `anthropic`.

### Configuration & credentials

- API keys are stored per provider in the credential manager. The Anthropic key
  keeps the legacy `aiquila/api_key` slot; others use `aiquila/api_key/<provider>`.
- Preferred model is stored per provider: `user_model` (anthropic) /
  `user_model_<provider>`; admin defaults `model` / `model_<provider>`.
- Settings UI: the personal panel (`NavigationSettings.vue`) and the admin form
  (`templates/admin.php` + `js/admin.js`) expose a provider dropdown; the model
  list and API-key field are scoped to the selected provider.

### Adding another provider

1. Implement `LLMProviderInterface` (mirror `MistralProvider`).
2. Add a model registry (mirror `MistralModels`).
3. Register it in `LLMProviderFactory`.
4. The settings UI picks it up automatically from `describeProviders()`.

## Verifying MCP works with Mistral (#136)

The AIquila MCP server is provider-agnostic — it just exposes Nextcloud tools
over the MCP protocol. To prove Mistral can drive those tools end-to-end, run the
verification script, which performs OAuth PKCE → MCP `tools/list` → Mistral
function-calling → MCP `tools/call` → final answer:

```bash
cd docker/standalone
# .env needs MCP_AUTH_ENABLED=true, MISTRAL_API_KEY, NEXTCLOUD_USER, NEXTCLOUD_PASSWORD
make up
make test-mistral
```

The script lives at `mcp-server/scripts/test-mistral-mcp.ts` and uses the
official `@mistralai/mistralai` TypeScript SDK (a dev-only dependency of the
scripts package; the production MCP server has no Mistral code). It is the Mistral
analogue of `make test-mcp-connector` (Anthropic).
