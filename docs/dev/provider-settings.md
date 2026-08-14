# Provider settings schema

Every LLM provider describes its own configuration. The admin and personal
settings pages render those descriptions generically, so adding a provider
requires no frontend work and no new endpoint.

Before 0.4.0 each provider meant hand-editing `templates/admin.php`,
`js/admin.js` and a Declarative Settings form whose model list was hardcoded to
Anthropic's — wrong for four of the five providers.

## The pieces

| Where | What it does |
|-------|--------------|
| `LLMProviderInterface::getSettingsSchema()` | The provider's fields, as descriptors |
| `LLMProviderInterface::getCapabilities()` | What it supports; drives capability chips and provider-aware validation |
| `ProviderSettingsSchema` | Field factories and the scope/type constants |
| `ProviderSettingsService` | Reads and writes fields from their descriptors; caches model lists |
| `ProviderSettingsController` | `GET|POST /api/providers[/{id}]` and the `/api/admin/…` twins |
| `src/components/settings/SchemaField.vue` | One renderer for every field type |
| `src/components/settings/ProviderCard.vue` | One card per provider, in either scope |

## A field descriptor

```php
ProviderSettingsSchema::model(
    'model_hetzner',        // app config key
    'user_model_hetzner',   // user config key
    HetznerModels::DEFAULT_MODEL,
    'Used for every request unless a conversation pins a different one.',
);
```

The descriptor carries its own config keys, so key naming stays with the
provider that owns it — `ProviderSettingsService` reads and writes purely from
the descriptor and never names a provider.

Types: `text`, `number`, `password`, `select`, `checkbox`, `url`, `multiselect`.
Groups: `basic` renders inline on the card, `advanced` behind a disclosure.
A select whose `options` is the sentinel `@models` is expanded to the provider's
live model list (cached, with the static registry as fallback); a `multiselect`
whose `options` is `@principals` is a user/group picker that queries
`/api/admin/principals` as the admin types.

## Fields no provider declares

The four access lists — allowed/blocked users and groups — are not part of any
provider's `getSettingsSchema()`. Access control is a property of the app, not of
a provider, so `ProviderSettingsService::schemaFor()` appends
`ProviderSettingsSchema::accessLists()` to every provider's schema. A new
provider gets them automatically and cannot forget them.

They carry `storage => 'access'` instead of a config key: `describe()` fills
their value from `ProviderAccessService::getLists()` and `writeAdmin()` routes
them to `setLists()` rather than `IConfig`. Being `SCOPE_ADMIN`, they are
filtered off the personal page by the ordinary scope rules.

## Scope is a security boundary

`scope` decides which endpoint may write a field:

- `SCOPE_ADMIN` — instance only (the default)
- `SCOPE_USER` — personal only
- `SCOPE_BOTH` — either; the user value overrides the instance value

`ProviderSettingsService::writeUser()` drops anything that is not
`SCOPE_USER`/`SCOPE_BOTH` and reports it back in `rejected`. This is what keeps
`local_base_url` and `hetzner_base_url` out of reach of the personal page: the
server makes outbound requests to whatever is stored there, so a user-settable
endpoint would be a server-side request forgery vector.

**Endpoint URLs must stay `SCOPE_ADMIN`.** The default scope is admin, so a
provider that forgets to declare one fails closed rather than open.

API keys are marked `sensitive`. They live in `CredentialService`, never in
`IConfig`, and their values are never returned to the client — the API reports
only whether a key exists in the requested scope, which is how the personal page
distinguishes your own key from an inherited instance one.

## Adding a provider

1. Implement `LLMProviderInterface`, or extend
   `AbstractOpenAiCompatibleProvider` for an OpenAI-compatible vendor — that
   base class supplies the usual key / model / max-tokens / timeout schema and
   derives capabilities from its own hooks, leaving you `defaultModel()` and
   `defaultMaxTokens()`. Merge in anything extra:

   ```php
   public function getSettingsSchema(): array {
       return array_merge(parent::getSettingsSchema(), [
           ProviderSettingsSchema::baseUrl('acme_base_url', 'API endpoint', '…'),
       ]);
   }
   ```

2. Add a model registry (mirror `MistralModels`) for the static fallback.
3. Register it in `LLMProviderFactory` and add a `staticModels()` arm in
   `ProviderSettingsService`.
4. Done — both settings pages render a card for it, with its fields, capability
   chips, live model list and a Test connection button.

## Model list caching

`listModels()` is a live HTTP call. Rendering a settings page used to make one
per registered provider on every load. `ProviderSettingsService::listModels()`
caches results for an hour in a distributed cache, and the pages pass
`?refresh=1` behind the card's **Refresh models** button.

The static fallback is deliberately *not* cached: it is static anyway, and
caching it would pin a transient outage for the full TTL. Saving a key or an
endpoint drops the provider's cached list, since either can change what it
serves.

## Where this is used

- **Admin page** (`src/views/AdminSettings.vue`) — tabs, with a card grid on
  Providers.
- **Personal page** (`src/views/PersonalSettings.vue`) — the same cards limited
  to user-writable fields.
- **Chat header** (`src/components/ConversationModelPicker.vue`) — reads
  `GET /api/providers` for the provider list and each provider's model options,
  and lists only providers the user actually has credentials for.

## See also

- [Local model provider](local-provider.md)
- [Hetzner Inference provider](hetzner-provider.md)
- [Mistral provider](mistral-provider.md)
