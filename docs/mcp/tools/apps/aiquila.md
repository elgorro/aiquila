# AIquila Tools

Tools for reading and changing the AIquila app's provider settings and for sending a test prompt
through a provider. They are registered only when the AIquila app is installed and enabled.

## How they run

Each tool runs an `occ` command of the AIquila app on the Nextcloud server and returns its
output. The MCP server sends the command to the app's `/api/occ` endpoint, as described in
[How OCC-backed tools run](../system-tools.md#how-occ-backed-tools-run):

| Tool | Runs |
|---|---|
| `aiquila_show_config` | `occ aiquila:configure --show [--target-provider <id>]` |
| `aiquila_configure` | `occ aiquila:configure <options>` |
| `aiquila_test` | `occ aiquila:test --prompt <prompt> [--provider <id>] [--user <uid>]` |

So the tools need:

- an **admin** account: under OAuth, the signed-in user; for stdio, `NEXTCLOUD_USER`
- `aiquila:configure *` and `aiquila:test *` in the app's `occ_allowlist`. Both are in its
  default; if you set the value yourself, keep them (see [The
  allowlist](../system-tools.md#the-allowlist))

`MCP_OCC_ALLOWLIST` does not apply; it limits `run_occ` only.

A command that exits non-zero, such as one rejecting a value, comes back as a tool error
`OCC command failed (exit <n>): <message>`, and nothing from that call is stored.

## aiquila_show_config

Show the instance configuration, or every setting of one provider.

**Parameters:**
- `targetProvider` (string, optional): list this provider's settings by id instead, the ids
  `aiquila_configure`'s `providerSettings` takes

**Returns:** the command's output. Without `targetProvider`: the instance default provider, its
model and max tokens, whether a key is configured, effort and thinking (for chat and for tasks),
thinking and task budgets, service tier, fast mode, auto cache, Files API, server fallback and
timeout. With it: each setting of that provider with its id and current value.

Keys and other secrets are shown only as `(configured)` or `(not set)`.

```
AIquila Configuration:

  Provider:   anthropic
  API Key:    (configured)
  Model:      claude-sonnet-5
  Max Tokens: 16384
  Effort:     (model default)
  Thinking:   auto (model default)
  …
  Timeout:    30 seconds
```

---

## aiquila_configure

Change instance-wide settings, and set up any provider.

**Parameters** (all optional; only the ones given are changed):

Anthropic and the defaults every provider uses:
- `apiKey` (string): Anthropic API key
- `model` (string): Claude model ID, e.g. `claude-sonnet-5`
- `maxTokens` (number): maximum response tokens, 1–128000
- `timeout` (number): request timeout in seconds, 10–1800 (default 30)
- `effort` (string): default effort, `low`, `medium`, `high`, `xhigh` or `max`; an empty string
  resets to the model default
- `thinking` (string): default thinking, `auto` (follow the model), `on` or `off`
- `taskEffort` (string): effort for Assistant tasks and coworkers; an empty string uses `effort`
- `taskThinking` (string): thinking for Assistant tasks and coworkers, `auto`, `on`, `off`, or an
  empty string to use `thinking`

Any provider:
- `provider` (string): make this provider the instance default for every user (`anthropic`,
  `mistral`, `hetzner`, `local`, …). Applied last, only after every other value in the call was
  accepted
- `targetProvider` (string): the provider `providerKey`, `providerModel` and `providerSettings`
  apply to, **without** changing the instance default. Defaults to `provider`, else `anthropic`
- `providerKey` (string): API key for the target provider
- `providerModel` (string): model for the target provider
- `providerSettings` (object): any other setting of the target provider, keyed by setting id or
  app config key (`timeout` or `local_timeout`). Values are validated like the admin settings
  page: checkboxes take `on` / `off`, access lists (`allowed_users`, `allowed_groups`,
  `blocked_users`, `blocked_groups`) take comma-separated ids, and an empty value clears them.
  One bad value rejects the whole call and nothing is stored

**Returns:** the command's output, one `✓ … updated` line per change.

Set up a second provider without moving anyone to it:

```json
{
  "targetProvider": "local",
  "providerModel": "qwen3",
  "providerSettings": { "local_base_url": "http://llama:8080", "timeout": "900" }
}
```

Configure Hetzner and make it the default in one call:

```json
{ "provider": "hetzner", "providerKey": "<token>" }
```

The Claude models AIquila knows are listed under
[Claude models](../../../installation/aiquila-setup.md#claude-models), and how effort and thinking
behave per model under [Thinking and effort](../../../installation/aiquila-setup.md#thinking-and-effort).

---

## aiquila_test

Send a prompt through the provider that would serve a user (their personal choice, else the
instance default, within the provider access lists), or through a named provider.

**Parameters:**
- `prompt` (string, optional): default `Hello, Claude!`
- `provider` (string, optional): provider to test instead of the resolved one, e.g. `local`
- `user` (string, optional): Nextcloud user ID to test as; their personal provider, key and model
  apply

**Returns:** the command's output: the provider, the key (last four characters only, and not at
all when it is shorter than 16 characters), the model and max tokens, the response and how long
it took.

```
Testing AIquila provider: Local model (local)
════════════════════════════════════

1. Checking configuration...
✓ API Key: …4567
✓ Model: qwen3
✓ Max Tokens: 4096

2. Sending the test prompt...
✓ Response received in 1.2s
...
✓ Provider local answered
```

The tool sends a real request to the provider, which the provider may bill.

## OCC command reference

The same commands can be run on the server directly, which needs no MCP server and no admin
account in Nextcloud. Prefix them with `sudo -u www-data php` on a host install, or
`docker compose exec -u www-data <service> php` in Docker.

```bash
occ aiquila:configure --show
occ aiquila:configure --show --target-provider local   # setting ids of one provider

occ aiquila:configure --api-key "sk-ant-..." --model claude-sonnet-5 --max-tokens 8192 --timeout 120

# Configure a provider without making it the default
occ aiquila:configure --target-provider local \
  --provider-key "<token>" --provider-model qwen3 \
  --provider-setting local_base_url=http://llama:8080 \
  --provider-setting local_auth_mode=bearer

# Make a provider the default
occ aiquila:configure --provider local

occ aiquila:test
occ aiquila:test --prompt "Your test prompt" --user alice
occ aiquila:test --provider local
```

`--provider` on its own also targets that provider, so `--provider hetzner --provider-key
<token>` configures and activates it in one step; the default switches only after every value
in the command was accepted. `--provider-setting id=value` is repeatable.

Two options of `aiquila:test` have no tool parameter: `--stream` and `--cache-check` (send the
chat system prompt twice and report whether the second request read it from the prompt cache).
Both work for `anthropic` only. `aiquila:configure` has further options the tool does not
expose, such as `--task-budget`, `--service-tier`, `--fast` and `--files-api`; see
[AIquila setup](../../../installation/aiquila-setup.md) and `occ aiquila:configure --help`.

## Troubleshooting

**`Permission denied. OCC commands require admin rights…`**: the account the MCP server acts as
is not a Nextcloud admin. Sign in as an admin, or run the [commands](#occ-command-reference) on
the server.

**`Refused by Nextcloud: Command and arguments are not in the OCC allowlist…`**: the app's
`occ_allowlist` was set without `aiquila:configure *` or `aiquila:test *`. Add them back.

**`OCC endpoint not found…`**: the AIquila app is not installed or not enabled.

**`OCC command failed (exit 1): …`**: the command rejected a value (an unknown provider or
setting id, a number out of range, an invalid model). The message says which; nothing was stored.

**`aiquila_test` times out**: raise the provider's timeout (`timeout` for Anthropic,
`providerSettings: { "timeout": "…" }` for others) and check that the Nextcloud server can reach
the provider.

## Development

- Source: [`mcp-server/src/tools/apps/aiquila.ts`](../../../../mcp-server/src/tools/apps/aiquila.ts)
- Commands: `nextcloud-app/lib/Command/ConfigureCommand.php`, `nextcloud-app/lib/Command/TestSDKCommand.php`
- [Adding tools](../../development/adding-tools.md)
