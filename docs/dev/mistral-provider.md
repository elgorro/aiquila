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
- **`DeepSeekProvider`** (`deepseek`) and **`LocalProvider`** (`local`) extend
  `AbstractOpenAiCompatibleProvider`, which carries the shared OpenAI wire-format
  handling. `LocalProvider` points at a self-hosted Ollama / LM Studio /
  llama.cpp endpoint — see [Local model provider](local-provider.md).
  `MistralProvider` deliberately does *not* extend that base class: its native
  MCP path runs against the Conversations API, which diverges too far to share.

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
- Settings UI: both pages (`src/views/AdminSettings.vue`, `src/views/PersonalSettings.vue`)
  render one `ProviderCard` per provider from the schema the provider itself
  declares via `getSettingsSchema()` — there is no per-provider frontend code.

### Adding another provider

1. Implement `LLMProviderInterface` (mirror `MistralProvider`), including
   `getSettingsSchema()` and `getCapabilities()`. Extending
   `AbstractOpenAiCompatibleProvider` gives you the usual key/model/tokens/timeout
   schema for free; merge in anything extra. Endpoint URLs must stay
   `SCOPE_ADMIN` — see the note in `ProviderSettingsSchema`.
2. Add a model registry (mirror `MistralModels`).
3. Register it in `LLMProviderFactory` (and add a `staticModels()` arm in
   `ProviderSettingsService`, the fallback used when the live model listing is
   unavailable).
4. Both settings pages pick it up automatically — a card is rendered from the
   schema, with capability chips from `getCapabilities()`, a live model list and
   a Test connection button. No frontend change is needed.

For an OpenAI-compatible vendor, extend `AbstractOpenAiCompatibleProvider`
instead of implementing the interface from scratch — see `DeepSeekProvider` and
[`HetznerProvider`](hetzner-provider.md), which are ~60 lines each.

## Models & reasoning

`nextcloud-app/lib/Service/MistralModels.php` is the static registry. It is only
a **fallback**: `MistralProvider::listModels()` prefers the live `/v1/models`
listing and the settings UI renders that when it is reachable.

### Dated IDs, not `-latest` aliases

The registry pins dated model IDs (`mistral-small-2603`, `mistral-large-2512`, …)
on purpose. It used to pin `-latest` aliases on the assumption that they stay
current by themselves, which does not survive a family being retired:
`pixtral-large-2411` was retired on 2026-05-31 and `ministral-8b-2410` on
2025-12-31, and the app went on pointing its **vision fallback** at Pixtral long
after the API stopped serving it (#487). Dated IDs can be checked against
Mistral's published deprecation table; aliases cannot.

Current entries: Mistral Large 3, Medium 3.5, Small 4 and Ministral 3
(14B/8B/3B). All are natively multimodal, so `supportsVision()` is a lookup over
the registry plus a family-prefix check — not the old `str_contains(…, 'pixtral')`
heuristic. `MistralModels::VISION_MODEL` is the model
`MistralProvider::visionOptions()` swaps in when the configured model cannot take
image input; since the default (Small 4) is multimodal, it rarely fires.

**When Mistral ships a new generation**, add the dated IDs to the constants,
`MAX_TOKENS_CEILING`, `VISION_MODELS` and `getAllModels()`, and check the
deprecation table for anything the app still names.

### Reasoning (`reasoning_effort`)

Mistral Small 4 and Medium 3.5 are hybrid reasoning models. They take a
`reasoning_effort` request parameter — `high` (think before answering) or `none`
— which the app exposes through its existing **`effort`** capability, not
`thinking`/`thinkingBudget`: there is no token budget to set. Resolution order in
`MistralProvider::resolveEffort()` is conversation override (`/effort`) → user
setting (`user_effort_mistral`) → admin setting (`effort_mistral`); a value the
model does not accept falls through rather than 400ing the API, and when nothing
resolves the parameter is omitted entirely.

Effort vocabularies differ per provider (Anthropic's `low…max` vs Mistral's
`none`/`high`), so `LLMProviderInterface::getAllowedEfforts()` lets each provider
own its own table. `ConversationController::update()` validates through it;
providers without an effort knob return `[]`.

With `reasoning_effort: high` the response shape changes: `message.content`
becomes a list of chunks (`{type: thinking, thinking: [TextChunk…]}` then
`{type: text}`) instead of a plain string, and the streaming `delta.content` is a
chunk list while the model thinks and a string afterwards.
`extractMessageText()` / `extractThinkingText()` split the two. The reasoning
trace is **replayed** on assistant turns inside a tool loop —
`assistantMessageFromToolCalls()` keeps it as a `thinking` block and
`toMistralMessages()` sends it back — because Mistral's docs are explicit that
stripping it costs coherence across turns.

> **Known limitation.** Persisted conversation history stores assistant messages
> as plain text, so a reasoning trace does not survive a page reload; it is only
> replayed within a single request's agentic loop. The native-MCP Conversations
> path drops it too (`toConversationInputs()` keeps text/image/document blocks
> only).

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
