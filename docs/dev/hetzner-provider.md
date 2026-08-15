# Hetzner Inference provider

AIquila can send chat, vision and tool-calling traffic to
[Hetzner's Inference API](https://docs.hetzner.com/general/company-and-policy/experiments/inference/),
an OpenAI-compatible service on the Hetzner Experiments platform that serves
open-weight models from datacenters in Germany and Finland. For an instance that
already runs on Hetzner Cloud it is the shortest path to an EU-hosted LLM with no
GPU of your own.

> **Experimental.** Hetzner offers the service "as is", with no availability or
> performance guarantee and no backups. Do not build a production dependency on it.

## Endpoint

| | |
|---|---|
| Base URL | `https://inference.hetzner.com/api/v1` |
| Auth | `Authorization: Bearer <token>` — create tokens at [experiments.hetzner.com/inference](https://experiments.hetzner.com/inference) |
| Endpoints used | `POST /chat/completions` (incl. SSE streaming and `tools` / `tool_calls`), `GET /models` |
| Token limits (per token) | 4M input / 100k output tokens per 60s; 500M input / 5M output per 24h |
| Request limit (per token) | **10 requests per 60s** |

Exceeding either limit returns HTTP 429. The request limit is the tight one for
AIquila: every settings page render costs a `GET /models` per provider, so the
model list and the provider status light are both cached — see
[Provider settings](provider-settings.md).

## Models

| Model | Type | Context | Modalities |
|---|---|---|---|
| `Qwen/Qwen3.6-35B-A3B-FP8` | MoE, 35B total / 3B active | 262k | Text, Image |
| `Kimi-K2.7-Code` | MoE, 1T total / 32B active | 262k | Text, Image |
| `DeepSeek-V4-Flash-0731` | MoE, 304B total / 13B active | 512k | Text |
| `GLM-5.2-NVFP4` | MoE, 744B total / 40B active | 512k | Text |

The line-up changes as the experiment evolves, so the settings UI lists whatever
`GET /models` currently returns and falls back to `HetznerModels` only when that
call fails. Model ids can also be typed freely. Keep
`nextcloud-app/lib/Service/HetznerModels.php` in step with the table above: it
carries the fallback list, the per-model output-token ceilings and the
text-only set.

## Implementation

`HetznerProvider` (`nextcloud-app/lib/Service/Provider/HetznerProvider.php`)
extends `AbstractOpenAiCompatibleProvider`, the same base class `DeepSeekProvider`
and `LocalProvider` use, so all wire-format handling is shared. It only supplies:

- identity (`hetzner`, "Hetzner Inference (EU)") and the base URL,
- `supportsVisionInput()` — decided per model via `HetznerModels::supportsVision()`:
  true for Qwen3.6 and Kimi-K2.7-Code, false for DeepSeek-V4-Flash and GLM-5.2.
  An id the registry does not know is assumed vision-capable, so a model added to
  the service after this release is not crippled by a stale list — the API
  rejects an unsupported modality on its own,
- error mapping for 401 (bad token), 429 (quota) and 502/503 (experiment down),
- model / max-token resolution from the config keys below.

Native MCP is not supported (no connector API); tools are executed client-side by
the shared base class, exactly as for DeepSeek.

## Configuration keys

App config (`oc_appconfig`, app `aiquila`):

| Key | Default | Meaning |
|---|---|---|
| `model_hetzner` | `Qwen/Qwen3.6-35B-A3B-FP8` | Admin default model |
| `max_tokens_hetzner` | `8192` | Output token limit, clamped to the per-model ceiling below |
| `hetzner_base_url` | *(unset → default endpoint)* | Endpoint override; admin-scope only (SSRF), validated by `HetznerProvider::normalizeBaseUrl()` |

Per-model output ceilings applied by `HetznerModels::getMaxTokenCeiling()`
(an unknown id falls back to the 8192 default):

| Model | Ceiling |
|---|---|
| `Qwen/Qwen3.6-35B-A3B-FP8` | 32768 |
| `Kimi-K2.7-Code` | 32768 |
| `DeepSeek-V4-Flash-0731` | 65536 |
| `GLM-5.2-NVFP4` | 65536 |

User config: `user_model_hetzner` (per-user model override), `user_provider`
(per-user provider override).

The token is stored encrypted in Nextcloud's credential manager under
`aiquila/api_key/hetzner`, per user or app-wide — never in `oc_appconfig`.

## Configuring it

**Web UI** — Admin settings → AIquila → **Providers** → open the
"Hetzner Inference (EU)" card, paste the token, **Save**, then
**Test connection**. Select its radio to make it the instance default. Users can
override the provider, the model and their own token in personal settings; the
endpoint override stays admin-only.

**OCC** — useful for headless installs:

```bash
php occ aiquila:configure --provider hetzner --provider-key <token>
php occ aiquila:configure --provider hetzner --provider-model Qwen/Qwen3.6-35B-A3B-FP8
php occ aiquila:configure --show
```

`--provider-key` / `--provider-model` apply to the provider named by
`--provider` (anthropic when omitted).

**Provisioning** — `aiquila-hetzner create` can configure it while installing the
app, for `--stack nextcloud` and `--stack full`:

```bash
aiquila-hetzner create --stack nextcloud --nc-domain cloud.example.com \
  --nc-admin-password '…' \
  --hetzner-inference-token "$HETZNER_INFERENCE_TOKEN" \
  --hetzner-inference-model Qwen/Qwen3.6-35B-A3B-FP8
```

The token may also come from `$HETZNER_INFERENCE_TOKEN` or from the deployment
config file (`hetzner_inference_token` / `hetzner_inference_model`). It is passed
to `occ aiquila:configure` over SSH and redacted from all printed output. On
`--stack mcp` the flag is ignored with a warning — there is no local Nextcloud to
configure.

## Troubleshooting

**Only one model in the dropdown.** The live listing failed and the static
registry rendered instead. `listModels()` swallows the failure to keep its
contract, but it always logs the reason first:

```bash
grep 'Could not list models' data/nextcloud.log   # the provider's own error
grep 'no live model list'    data/nextcloud.log   # the fallback that followed
```

The `error` field distinguishes the causes:

| Error | Cause |
|---|---|
| `401 Unauthorized` | No token configured, or a revoked/mistyped one |
| `429 Too Many Requests` | The 10 requests / 60s budget is exhausted |
| `cURL error 28` / timeout | The experiment is unreachable |

Check the token against the API directly:

```bash
curl -si https://inference.hetzner.com/api/v1/models \
  -H "Authorization: Bearer $HETZNER_INFERENCE_TOKEN" | head -30
```

If `curl` lists the models but the UI does not, the cached failure marker is
still live — click **Refresh models** on the provider card (it sends
`?refresh=1`, which bypasses both the marker and the cached list), or wait out
the 60s TTL.

## Tests

`nextcloud-app/tests/Unit/HetznerProviderTest.php` covers endpoint targeting, the
bearer header, base-URL override and its validation, model/max-token resolution,
per-model vision support and the static registry, image input, the tool
round-trip, streaming, `listModels()`, and the 401/429 error mapping.
