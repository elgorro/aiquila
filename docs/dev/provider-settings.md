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

Types: `text`, `number`, `password`, `textarea`, `select`, `checkbox`, `url`,
`multiselect`.
Groups: `basic` renders inline on the card, `advanced` behind a disclosure.
A select whose `options` is the sentinel `@models` is expanded to the provider's
live model list (cached per credential, with the static registry as fallback —
see [Model list caching](#model-list-caching)); a `multiselect`
whose `options` is `@principals` is a user/group picker that queries
`/api/admin/principals` as the admin types.

A `format` adds a validation rule on top of the type, applied by
`ProviderSettingsService::encode()` so the rule lives in one place rather than in
each provider: `header_name` (a single HTTP header name), `headers` (a
`Name: value` block, parsed by `HeaderSpec`), `file_path` (an absolute path that
must exist and be readable by the web-server user). A failure raises
`InvalidArgumentException`, which the controller turns into a 400 carrying the
message.

A `visible_if` — `['field' => 'local_auth_mode', 'in' => ['basic']]` — hides a
field until a sibling holds one of the listed values. Presentation only: scope is
what decides who may *write* a field, and a hidden field's stored value stays
put, so flipping between auth modes does not discard what was typed for the other
one.

## Secrets

`sensitive => true` keeps a value out of every response — `describe()` reports
only `hasValue`, and the client submits an empty string to mean "leave it alone".
Where it is stored depends on one more key:

- No `secret` key: the field is *the* provider API key, held in
  `CredentialService` under the provider id. Writable in user scope, which is how
  a personal key overrides the instance one.
- `secret => '<name>'`: a named instance-scope slot
  (`aiquila/secret/<name>`), for the second credential a provider needs — the
  local provider's extra request headers and client-key passphrase. Namespaced
  away from the API keys so a secret name cannot collide with a provider id, and
  `SCOPE_ADMIN` by construction. Submitting an empty value clears it.

Either way the value never reaches `IConfig`.

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
per registered provider on every load, which matters more than it sounds: Hetzner
allows only 10 requests per 60s per token, so an uncached settings page can spend
the whole budget on model lists and then get 429s in chat.
`ProviderSettingsService::listModels()` therefore caches, and both the personal
(`SettingsController`) and provider (`ProviderSettingsController`) endpoints go
through it. The pages pass `?refresh=1` behind the card's **Refresh models**
button to bypass the cache entirely.

| | TTL | Notes |
|---|---|---|
| Successful listing | 3600s | The line-up changes rarely |
| Failed listing (marker) | 60s | Serves the static fallback without a request |
| Status probe | 30s | The per-card status light |

**Keyed per credential, not per provider.** The cache key is the *credential
scope*: `user-<uid>` for a user with a personal key, `instance` for everyone
inheriting the instance key. A personal key talks to the provider as that user
and can see a different line-up — or a working endpoint where the instance key is
revoked — so serving their list to other users would both leak what they have
access to and show everyone else models they cannot reach.

**Failures are cached, the fallback is not.** The static registry is static
anyway, so there is nothing to cache; but re-asking an endpoint that just refused
us on every page load keeps a rate-limited key pinned at its ceiling and turns
one transient 429 into a permanent fallback. A short failure marker breaks that
loop while still recovering on its own within a minute.

**The status light is throttled, not cached.** `status()` stays live per call —
a stale green after a key was revoked would be worse than no light at all — but
each card costs its own `/models` probe on top of the model lists, so a result is
reused for 30s. Short enough that a revoked key goes red almost immediately, long
enough that a reload loop cannot exhaust the request budget on status lights.

Each provider owns its own cache namespace (`aiquila-models-<id>`), because
`ISimpleCache` cannot enumerate or glob keys and `forgetModels()` has to drop
*every* scope of a provider at once. Saving a key or an endpoint calls it, since
either can change what the provider serves.

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
