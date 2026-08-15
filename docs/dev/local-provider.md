# Local model provider (Ollama / LM Studio / llama.cpp)

AIquila can run inference on your own hardware instead of a hosted API. This is
the **`local`** provider — one implementation that covers Ollama, LM Studio,
llama.cpp's `llama-server`, and anything else speaking the same dialect (vLLM,
LocalAI, a reverse proxy in front of any of them).

## Why one provider and no SDK

All three popular local runtimes expose the *same* OpenAI-compatible surface:

- `POST {base}/v1/chat/completions` — including SSE streaming and
  `tools` / `tool_calls` function calling
- `GET {base}/v1/models`
- `POST {base}/v1/embeddings`

So they need no per-backend code, and no vendor SDK:

- Ollama ships `ollama` for npm and PyPI; LM Studio ships `lmstudio-js` and
  `lmstudio-python`. **Neither has a PHP client**, and AIquila's provider layer
  is PHP.
- What those SDKs add over the REST API is *model management* — load, unload,
  pull, list loaded models — not inference. AIquila doesn't manage the model
  lifecycle; the admin does that on the box running the backend.

`LocalProvider` therefore extends `AbstractOpenAiCompatibleProvider`
(`nextcloud-app/lib/Service/Provider/`), the same base class `DeepSeekProvider`
uses. It only supplies the base URL, the model, and a few capability flags.

## Backend reference

| | Default port | Auth | Notes |
|---|---|---|---|
| **Ollama** | 11434 | none built in | Binds `127.0.0.1` unless `OLLAMA_HOST=0.0.0.0`. Does **not** support `tool_choice`, `logprobs`, `n`, or `logit_bias`. Its native `/api/chat` adds `keep_alive` (how long to hold the model in VRAM) — not used here. |
| **LM Studio** | 1234 | Bearer token | "Serve on Local Network" in the Developer tab binds off-localhost. Also offers Anthropic-compatible endpoints and LM Link for remote use; AIquila uses the OpenAI ones. |
| **llama.cpp `llama-server`** | 8080 | `--api-key` | `--host 0.0.0.0` to bind externally. Single static binary; can route between models by the `model` request field. |

Because `tool_choice` support is uneven, `LocalProvider::sendsToolChoice()`
returns `false` — tools are sent, the field is not.

## Configuration

Admin settings → AIquila → **Local model endpoint**. All of it is admin scope;
none of it is user-settable (see [Security](#security)).

| Field | App config key | Default | Notes |
|---|---|---|---|
| Endpoint base URL | `local_base_url` | *(empty)* | `/v1` appended automatically; must be `http(s)`. Empty means the provider is unconfigured. |
| API key | credential manager, provider `local` | *(empty)* | Optional, and used by whichever auth mode is selected — the bearer token, the Basic *password*, or the custom header's value. Empty ⇒ **no** credential header at all. |
| Authentication | `local_auth_mode` | `bearer` | `none` \| `bearer` \| `basic` \| `header` — see [Authentication](#authentication). |
| Basic auth username | `local_auth_user` | *(empty)* | Only used in `basic` mode. |
| Header name | `local_auth_header` | *(empty)* | Only used in `header` mode, e.g. `X-API-Key`. |
| Additional request headers | credential manager, secret `local_extra_headers` | *(empty)* | `Name: value` per line — see [Additional headers](#additional-headers). |
| Verify TLS certificate | `local_tls_verify` | `yes` | Unchecking accepts any certificate for this endpoint. |
| CA bundle path | `local_ca_bundle` | *(empty)* | Absolute path to a PEM bundle for a private CA. |
| Client certificate path | `local_client_cert` | *(empty)* | mTLS; absolute path. |
| Client key path | `local_client_key` | *(empty)* | mTLS; absolute path. |
| Client key passphrase | credential manager, secret `local_client_key_password` | *(empty)* | Only for an encrypted private key. |
| Model | `model_local` | `llama3.2` | Free text; autocompletes from the endpoint's `/v1/models`. Users may override with `user_model_local`. |
| Max response tokens | `max_tokens_local` | `4096` | No per-model ceiling table — local model ids are arbitrary tags. |
| Request timeout | `local_timeout` | `300` | Applies to streaming and non-streaming. The shared `api_timeout` (30 s) is far too low for CPU inference. |
| Accepts images | `local_vision` | `no` | Only enable for a multimodal model (llava, llama3.2-vision, qwen2-vl, …). Images are sent as `image_url` data URIs. |
| Allow local addresses | `local_allow_local_address` | `yes` | See below. |

Setting **Local model** as the default provider (or a user picking it in personal
settings) routes chat, tools and streaming through it. The TaskProcessing
providers and the MCP server's `assistant` tool follow automatically — they call
Nextcloud's TaskProcessing, which resolves the active provider.

Configuration goes through the schema-driven endpoints
`GET /api/admin/providers` and `POST /api/admin/providers/local`, which read and
write whatever `LocalProvider::getSettingsSchema()` declares; the token is stored
encrypted in Nextcloud's credential manager like every other provider key.

Every field above is declared `SCOPE_ADMIN`, so
`ProviderSettingsService::writeUser()` refuses it even if the personal page were
to submit it — see the Security section below. The only user-scope setting this
provider has is `user_model_local`.

## Authentication

A bare Ollama or LM Studio takes a bearer token or nothing at all, but a
self-hosted endpoint published beyond localhost is usually behind a reverse
proxy that wants something else. `local_auth_mode` picks the scheme; the stored
API key is the secret in every case, so switching modes does not mean
re-entering it.

| Mode | Sent | For |
|---|---|---|
| `none` | nothing | A backend with no auth, where a stored key should stay unused |
| `bearer` | `Authorization: Bearer <key>` | LM Studio, `llama-server --api-key`. The default, and what every install had before this existed |
| `basic` | `Authorization: Basic base64(<local_auth_user>:<key>)` | nginx `auth_basic`, Caddy `basic_auth` |
| `header` | `<local_auth_header>: <key>` | LiteLLM, vLLM behind a gateway, anything wanting `X-API-Key` |

An empty key means no credential header at all, whatever the mode — that is the
keyless-Ollama case. In `header` mode with no header name configured, nothing is
sent either: an obvious 401 beats the key landing in a header nobody named.

The **Test connection** button distinguishes the failures rather than reporting a
flat "unreachable": a rejected credential names the configured scheme, a TLS
failure points at the CA settings, an unresolvable host points at
`host.docker.internal`, and a refused connection points at the port.

## Additional headers

`local_extra_headers` holds static headers for setups that need more than a
credential — Cloudflare Access sends a `CF-Access-Client-Id` /
`CF-Access-Client-Secret` pair, gateways want a tenant or routing header. One
`Name: value` per line; blank lines and `#` comments are ignored.

```
# Cloudflare Access
CF-Access-Client-Id: 1234….access
CF-Access-Client-Secret: abcd…
X-Tenant: research
```

It goes through the credential manager rather than `appconfig`, because those
values are secrets. `HeaderSpec` validates the block both when it is saved (so a
mistake is a readable error, not a cURL failure hours later) and when it is read
back, and refuses:

- CR/LF anywhere — header injection, against an endpoint whose SSRF guard is off.
- Hop-by-hop headers and `Content-Length` — they describe the connection, not the
  message.
- `Content-Type` and `Authorization` — owned by the provider; letting an extra
  header silently beat the configured auth scheme would make the mode a lie.

`Host` **is** allowed: the URL already decides which address is contacted, so
overriding it only selects a vhost there.

## TLS and mTLS

An internal endpoint is often published with a certificate no public bundle
trusts, or behind a proxy demanding a client certificate. These map straight onto
the HTTP client's options, since Nextcloud's `Client` merges caller options over
its own defaults (`array_merge($defaults, $options)`) — so `verify` set here
really does replace the instance CA bundle, and leaving it unset keeps that
bundle applying.

- `local_ca_bundle` → `verify`. The instance-wide alternative is
  `occ security:certificates:import`, which affects every outbound request rather
  than just this provider's.
- `local_client_cert` / `local_client_key` → `cert` / `ssl_key`, paired with
  `local_client_key_password` when the key is encrypted.
- `local_tls_verify` off → `verify: false`. Accepts any certificate for this
  endpoint, including one presented by an attacker on the path. It exists because
  an admin who knows their own network may knowingly want it; prefer the CA
  bundle.

Certificate material is given as **paths on the server**, not uploaded: private
keys stay out of app storage. The path must be absolute and readable by the
web-server user — in Docker it has to be mounted into the container. It is
checked when saved and again on every request, so a rotated-away mount reports
the file and the setting instead of an opaque cURL error.

## Security

Two things deserve care here.

**Nextcloud blocks local addresses by default.** `IClientService` refuses
loopback and private-range targets to prevent server-side request forgery, which
would block every realistic local-model deployment. `LocalProvider` sets
`['nextcloud' => ['allow_local_address' => true]]` on its own requests when
`local_allow_local_address` is `yes` (the default). The relaxation is scoped to
this provider's requests only — nothing else in the app gains it. Turn it off if
your endpoint has a public hostname.

**Every setting here is admin-only, deliberately.** Nextcloud makes outbound
server-side requests to whatever is stored in the base URL, so a user-settable
URL combined with the allowance above would be a straightforward SSRF primitive
— and user-settable headers on top of it would be a way to post an instance
credential wherever the user likes. There is a `user_model_local` override for
the *model*, and nothing else.

**Secrets never touch `appconfig`.** The API key, the extra headers and the
client-key passphrase all live in the credential manager, encrypted at rest, and
the settings API returns only whether each one is set.

**Don't expose the backend itself.** The recommended topology is to keep the
runtime bound to `127.0.0.1` and reach it over the Docker network, a reverse
proxy, or a VPN:

- Prefer the Docker network or `host.docker.internal` over
  `OLLAMA_HOST=0.0.0.0`.
- If it must cross machines, put an authenticating HTTPS reverse proxy in front
  (Caddy/nginx basic auth, or the backend's own token) and firewall the raw port.
- Across sites, use a mesh VPN (Tailscale/WireGuard) rather than a public port.
  Ollama in particular has no authentication of its own — an exposed 11434 is an
  open inference endpoint and a model-listing/pull surface.

## Deployment notes

**Dev stack (`docker/installation/`).** Nextcloud runs in a container, so
`localhost` is the *container*, not your machine. Use
`http://host.docker.internal:11434` (the compose file maps the host gateway), or
attach the backend to the shared network and use its service name.

**Hetzner (`hetzner/docker/{nextcloud,full}/`).** The provisioned cpx instances
have no GPU and are not realistic inference hosts. The intended topology is
Nextcloud on Hetzner pointing at a VPN-reachable machine you control. No compose
changes are needed — only the base URL.

**MCP server.** Nothing to configure. `mcp-server/src/tools/apps/assistant.ts`
proxies to Nextcloud's TaskProcessing / text2image OCS endpoints, so it uses
whatever provider Nextcloud has active.

## Known limitations

- **PDFs are rejected.** Local runtimes don't accept `document` input; extract
  the text first or switch providers.
- **No native MCP connector.** `supportsNativeMcp()` is `false` — there is no
  server-side connector to hand the conversation to. Tools run through AIquila's
  PHP-side agentic loop, which works fine but needs a model that reliably emits
  `tool_calls`; small models often do not.
- **Token usage is whatever the backend reports.** Most report
  `prompt_tokens`/`completion_tokens`; cache counters are always `null`.

## Verifying

```bash
# Backend
ollama serve && ollama pull llama3.2

# Rebuild the dev stack from the working tree
cd docker/installation
make build-tarball && make up
docker compose exec -T nextcloud bash /docker-entrypoint-hooks.d/post-installation/aiquila-install.sh

# Admin settings → Local model endpoint → Ollama preset → Save → Test connection
# The Model field should then autocomplete from /v1/models.

make nc-log
docker compose exec -T nextcloud sh -c "grep '\"level\":[34]' /var/www/html/data/nextcloud.log | tail"
```

Unit coverage lives in `nextcloud-app/tests/Unit/LocalProviderTest.php` (base-URL
normalization, optional auth, the local-address allowance, `tool_choice`
omission, vision gating, streaming, tool round-trip).
