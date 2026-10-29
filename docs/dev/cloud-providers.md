# Claude on cloud platforms

Besides the Claude API itself, AIquila can reach Claude through the cloud
platforms that serve it, so an organisation can use its existing AWS, Google
Cloud or Azure account, billing and IAM:

| Provider id | Card | Platform | Endpoint |
|---|---|---|---|
| `claude_aws` | Claude Platform on AWS | [Claude Platform on AWS](https://platform.claude.com/docs/en/build-with-claude/claude-platform-on-aws) (Anthropic-operated) | `aws-external-anthropic.{region}.api.aws` |
| `claude_gcp` | Claude on Google Cloud (Anthropic managed) | [Claude Models on Google Cloud, Anthropic managed](https://platform.claude.com/docs/en/build-with-claude/claude-models-on-google-cloud/anthropic-managed) | `claude.googleapis.com` |
| `bedrock` | Claude in Amazon Bedrock | [Claude in Amazon Bedrock](https://platform.claude.com/docs/en/build-with-claude/claude-in-amazon-bedrock) — Opus 4.7 and later | `bedrock-mantle.{region}.api.aws/anthropic` |
| `bedrock_invoke` | Claude on Amazon Bedrock (InvokeModel) | [Bedrock InvokeModel](https://platform.claude.com/docs/en/build-with-claude/claude-on-amazon-bedrock-legacy) — Opus 4.6 and earlier | `bedrock-runtime.{region}.amazonaws.com` |
| `vertex` | Claude on Vertex AI (Google managed) | [Claude Models on Google Cloud, Google managed](https://platform.claude.com/docs/en/build-with-claude/claude-models-on-google-cloud/google-managed) | `aiplatform.googleapis.com` and regional hosts |
| `foundry` | Claude in Microsoft Foundry | [Claude in Microsoft Foundry](https://platform.claude.com/docs/en/build-with-claude/claude-in-microsoft-foundry) | `{resource}.services.ai.azure.com/anthropic` |

Each appears as its own card under **Settings → Administration → AIquila →
Providers**, can be the instance default or a user's choice, and is subject to
the same per-provider access lists as every other provider. Chat, streaming, the
tool loop, vision, PDFs, citations, thinking, effort and prompt caching behave as
on the Claude API card.

## What each platform serves

The platforms do not all serve the whole Claude API. AIquila switches off what a
platform lacks, so a request never fails on a missing endpoint:

| | Claude API | `claude_aws` | `claude_gcp` | `bedrock` | `bedrock_invoke` | `vertex` | `foundry` |
|---|---|---|---|---|---|---|---|
| Batch summaries and coworkers | ✓ | ✓ | ✓ | — | — | — | — |
| Live model list | ✓ | ✓ | ✓ | — | — | — | — |
| Native MCP connector | ✓ | ✓ | ✓ | — | — | — | — |
| Fast mode | ✓ | — | ✓ | — | — | — | — |
| Files API uploads | ✓ | — | — | — | — | — | — |
| Service tier, extended batch output | ✓ | — | — | — | — | — | — |
| Automatic prompt caching | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |

Where a feature is off:

- **Batches** — summaries and coworker runs use ordinary requests instead, at the
  normal price.
- **Model list** — the card takes a free-text model ID, and per-model limits come
  from the app's static registry (see [Model IDs](#model-ids)).
- **MCP connector** — conversations fall back to the app's own MCP client, as with
  the other non-Claude providers.
- **Files API** — attachments are sent inline with each request. The Files API is
  only used through the Claude API card's key, which no cloud workspace can read,
  so even the Anthropic-operated platforms inline.
- **InvokeModel** — explicit cache breakpoints still go out; only the top-level
  automatic one is left off, because that route rejects it.

## Setting one up

Every field on these cards is instance-scope. A cloud account is an
organisation's billing and IAM boundary, so there are no personal cloud
credentials, and a user's personal Anthropic key is never sent to a cloud
endpoint. Users can still choose the provider and set their own effort and
thinking defaults.

1. Fill in the endpoint fields:
   - **AWS** — the region, e.g. `us-east-1`. On `claude_aws` also the
     `wrkspc_…` workspace ID, whose region must match.
     The hosts take the region's partition suffix: `amazonaws.com` and
     `api.aws` commercially and in GovCloud, `amazonaws.com.cn` and
     `api.amazonwebservices.com.cn` in China, `amazonaws.eu` and
     `api.amazonwebservices.eu` in the European Sovereign Cloud
     (`eusc-de-east-1`). `claude_aws` is offered in the commercial regions only.
   - **Google Cloud** — the project ID and location. Use `global` unless you
     need a multi-region (`us`, `eu`) or regional (`us-east5`) Vertex endpoint.
     On `claude_gcp` also the workspace ID; leave the location at `global`.
   - **Foundry** — the resource name, i.e. the `{resource}` part of the endpoint
     URL, not the URL itself.
2. Pick an **Authentication** mode and fill in its fields (see below).
3. Set the **Model** to an ID as the platform spells it.
4. **Save**, then check the status light or **Test connection**.

### Authentication

| Mode | Platforms | What it uses | Extra package |
|---|---|---|---|
| `api_key` | AWS platforms, Foundry | A key issued by the platform: a Bedrock API key, a Claude Platform on AWS key, or the Foundry deployment key | none |
| `access_keys` | AWS platforms | IAM access key ID and secret, plus a session token for temporary credentials; requests are SigV4-signed | `aws/aws-sdk-php` |
| `default_chain` | AWS platforms | The AWS default credential chain: environment, shared profile, ECS task role or EC2 instance role. Nothing is stored in Nextcloud | `aws/aws-sdk-php` |
| `service_account` | Google platforms | A service account key file (JSON) | `google/auth` |
| `adc` | Google platforms | Application Default Credentials: the attached service account on GCE, GKE or Cloud Run, or `GOOGLE_APPLICATION_CREDENTIALS` | `google/auth` |
| `access_token` | Google platforms, Foundry (Entra ID) | A bearer token pasted into the card | `google/auth` on `vertex` only |

Prefer a mode that does not store a long-lived secret: `default_chain` with an
instance or task role on AWS, `adc` with an attached service account on Google
Cloud. Pasted access tokens typically expire after an hour, so treat
`access_token` as a way to test a setup, not to run it. On AWS, scope whatever
identity you use to the inference actions on the model or inference-profile ARNs
you need: `bedrock:InvokeModel` and `bedrock:InvokeModelWithResponseStream`, plus
`bedrock:CallWithBearerToken` for API keys.

Secrets — API keys, secret access keys, session tokens, service account keys and
pasted tokens — are stored encrypted in Nextcloud's credential manager and never
returned to the browser.

### The optional packages

The Anthropic SDK the app bundles only *suggests* `aws/aws-sdk-php` and
`google/auth`, and the app does not ship them. Key and token auth work without
either.

- **`aws/aws-sdk-php`** is part of Nextcloud server itself (`3rdparty/`, used for
  S3 primary storage), so SigV4 and the credential chain normally work out of
  the box.
- **`google/auth`** has to be installed. Put it in a directory of its own, outside
  the app directory (an app update replaces that), and point Nextcloud at its
  autoloader:

  ```bash
  mkdir -p /opt/aiquila-cloud-sdk && cd /opt/aiquila-cloud-sdk
  composer require google/auth
  occ config:system:set aiquila.cloud_sdk_autoload --value=/opt/aiquila-cloud-sdk/vendor/autoload.php
  ```

  The path has to be readable by the web server and, in Docker, mounted into the
  container. It is read from `config.php`, not app config, because loading it
  runs code: no web endpoint can set it, including the provisioning API that can
  write app config. The external autoloader is consulted after Nextcloud's and
  the app's own, so it cannot replace a library Nextcloud already ships.

`occ aiquila:doctor` reports which of the two packages are available. The
**Authentication** field on each card says the same, and the status light names
the missing package when the selected mode needs it.

## Model IDs

The model field takes whatever the platform expects:

| Platform | Example |
|---|---|
| `claude_aws`, `claude_gcp` | `claude-sonnet-5` — the Claude API IDs |
| `bedrock` | `anthropic.claude-opus-5-5`, or an inference profile such as `global.anthropic.claude-sonnet-5` or `eu.anthropic.claude-sonnet-5` |
| `bedrock_invoke` | `global.anthropic.claude-opus-4-6-v1`, `us.anthropic.claude-haiku-4-5-20251001-v1:0`, or an inference profile ARN |
| `vertex` | `claude-opus-5-5`; snapshots before Claude 4.6 use `@`, e.g. `claude-haiku-4-5@20251001` |
| `foundry` | the deployment name |

The app maps each of these back to the Claude API ID before looking up the
model's output ceiling, context window and thinking and effort behaviour
(`ClaudeModels::canonicalId()`). A Foundry deployment name defaults to the model
ID. If you chose another name, set **Base model** on the Foundry card so the
right limits apply. An ID the registry does not know gets conservative defaults,
and the platform rejects anything the model cannot do.

## Endpoint validation

Region, location, project, resource and workspace end up in the hostname or path
of every request, together with the platform credentials. A crafted value such as
`us-east-1.example.org/` would send those credentials somewhere else, the same
server-side request forgery risk as a user-settable base URL. So:

- the fields are admin-only;
- `Cloud\CloudEndpoints` checks each one against the shape the platform issues,
  both when the settings page stores it and again whenever a client is built,
  since `occ config:app:set` bypasses the settings API;
- the app builds every base URL itself and hands it to the SDK, so the SDK's own
  `ANTHROPIC_*_BASE_URL` environment fallbacks never apply.

Model IDs are restricted to the characters the platforms use. Where the SDK puts
the model into a URL path (Vertex, InvokeModel), it URL-encodes it as well.

## Implementation

The providers live in `nextcloud-app/lib/Service/Provider/Cloud/`. Each extends
`ClaudeSDKService` through `AbstractCloudClaudeProvider` (with
`AbstractAwsClaudeProvider` and `AbstractGoogleClaudeProvider` for the shared
region, project and credential fields), so request building, streaming, the tool
loop and thinking are inherited unchanged. A platform supplies:

- its SDK client, built in `buildClient()` from the `Anthropic\Bedrock`,
  `Anthropic\Vertex`, `Anthropic\Foundry`, `Anthropic\Aws` or
  `Anthropic\GoogleCloud` clients of `anthropic-ai/sdk`. The client is built once
  per provider instance and caches its own tokens and signatures;
- its endpoint fields and auth modes;
- `platformFeatures()`, which switches off what the platform does not serve. See
  [Platform features](provider-settings.md#platform-features).

Settings are namespaced with the provider id (`model_bedrock`, `effort_vertex`,
`region_claude_aws`) through `configKey()`, so the cards are independent of one
another and of the Claude API card. API keys use the per-provider credential
slot; the other secrets are named `<provider>_<name>`.

## See also

- [Provider settings schema](provider-settings.md)
- [Features overview](https://platform.claude.com/docs/en/build-with-claude/overview), Anthropic's per-platform feature availability
