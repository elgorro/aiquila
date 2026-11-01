# AIquila Internal Tools

Tools for configuring and testing the AIquila Nextcloud app integration with Claude API.

## Overview

These tools provide instructions for running AIquila OCC (Nextcloud command-line) commands. Since MCP servers cannot directly execute commands on the Nextcloud server, these tools return the appropriate command syntax for manual execution.

## Prerequisites

- AIquila app installed in Nextcloud
- SSH access to Nextcloud server, OR
- Docker exec access (if using Docker installation)

## Available Tools

### aiquila_show_config

Show the current AIquila configuration including API key, model, tokens, and timeout settings.

**Parameters:**
- `targetProvider` (string, optional): show every setting of this provider by id instead — the ids `aiquila_configure`'s `providerSettings` accepts. Keys and other secrets are reported as `(configured)` or `(not set)`, never shown

**Returns:**
Instructions for running the show configuration command on your Nextcloud server.

**Example Usage:**
```
Ask Claude: "Show my AIquila configuration"
Ask Claude: "What are my current AIquila settings?"
Ask Claude: "Display AIquila API configuration"
```

**Command to Run (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --show
```

**Command to Run (SSH):**
```bash
php occ aiquila:configure --show
```

**Example Output:**
```
AIquila Configuration:
- API Key: sk-ant-***...*** (masked)
- Model: claude-sonnet-4-5-20250929
- Max Tokens: 4096
- Timeout: 60 seconds
```

---

### aiquila_configure

Configure AIquila settings including API key, Claude model, max tokens, API timeout, and the effort and thinking defaults for chat and background tasks.

**Parameters:**
- `apiKey` (string, optional): Anthropic API key (starts with `sk-ant-`)
- `model` (string, optional): Claude model identifier (e.g., `claude-sonnet-4-5-20250929`)
- `maxTokens` (number, optional): Maximum tokens for responses (1-100000)
- `timeout` (number, optional): API request timeout in seconds (10-1800)
- `effort` (string, optional): default effort — `low`, `medium`, `high`, `xhigh` or `max`; an empty string resets to the model default
- `thinking` (string, optional): default thinking — `auto` (follow the model), `on` or `off`
- `taskEffort` (string, optional): effort for Assistant tasks and coworkers; an empty string uses the default effort
- `taskThinking` (string, optional): thinking for Assistant tasks and coworkers — `auto`, `on`, `off`, or an empty string to use the default thinking
- `provider` (string, optional): make this provider the instance default for every user (`anthropic`, `mistral`, `hetzner`, `local`, …). Applied last, only after the provider's settings in the same call were accepted
- `targetProvider` (string, optional): the provider `providerKey`, `providerModel` and `providerSettings` apply to, **without** changing the instance default. Defaults to `provider`, else `anthropic`
- `providerKey` (string, optional): API key for the target provider
- `providerModel` (string, optional): model for the target provider
- `providerSettings` (object, optional): any other setting of the target provider, keyed by setting id or app config key (`{"local_base_url": "http://llama:8080", "timeout": "600"}`). Values are validated like the admin settings page; checkboxes take `on`/`off`, access lists take comma-separated ids. One bad value rejects the whole call and nothing is stored

**Returns:**
Instructions for running the configuration command with the specified parameters.

**Example Usage:**
```
Ask Claude: "Configure AIquila to use Sonnet 4.5"
Ask Claude: "Set my AIquila API key to sk-ant-..."
Ask Claude: "Update AIquila max tokens to 8192"
Ask Claude: "Set AIquila timeout to 120 seconds"
```

**Example with API Key:**
```json
{
  "apiKey": "sk-ant-api03-xxxxxxxxxxxxx"
}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --api-key "sk-ant-api03-xxxxxxxxxxxxx"
```

**Example with Model:**
```json
{
  "model": "claude-sonnet-4-5-20250929"
}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --model "claude-sonnet-4-5-20250929"
```

**Example with Multiple Parameters:**
```json
{
  "model": "claude-sonnet-4-5-20250929",
  "maxTokens": 8192,
  "timeout": 120
}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --model "claude-sonnet-4-5-20250929" --max-tokens 8192 --timeout 120
```

**Example: set up a second provider without switching anyone to it:**
```json
{
  "targetProvider": "local",
  "providerModel": "qwen3",
  "providerSettings": { "local_base_url": "http://llama:8080", "timeout": "900" }
}
```

**Command Generated:**
```bash
php occ aiquila:configure --target-provider local --provider-model qwen3 \
  --provider-setting local_base_url=http://llama:8080 --provider-setting timeout=900
```

---

### aiquila_test

Send a test prompt through the provider that would serve a user — their personal
choice, else the instance default, within the provider access lists — or through a
named provider.

**Parameters:**
- `prompt` (string, optional): Test prompt to send. Default: `"Hello, Claude!"`
- `provider` (string, optional): provider to test instead of the resolved one (e.g. `local`)
- `user` (string, optional): Nextcloud user ID to test as; their personal provider, key and model apply

**Returns:**
Instructions for running the test command.

**Example Usage:**
```
Ask Claude: "Test AIquila Claude integration"
Ask Claude: "Test AIquila with prompt 'What is Nextcloud?'"
Ask Claude: "Run AIquila test for user john"
```

**Simple Test:**
```json
{}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:test
```

**Test with Custom Prompt:**
```json
{
  "prompt": "Explain what Nextcloud is in one sentence"
}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:test --prompt "Explain what Nextcloud is in one sentence"
```

**Test for Specific User:**
```json
{
  "user": "john"
}
```

**Command Generated (Docker):**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:test --user john
```

**Example Output:**
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

The key is shown as its last four characters only, and not at all when it is
shorter than 16 characters.

---

## OCC Command Reference

### Configuration Commands

**Show current configuration:**
```bash
php occ aiquila:configure --show
```

**Set API key:**
```bash
php occ aiquila:configure --api-key "sk-ant-api03-xxxxxxxxxxxxx"
```

**Set model:**
```bash
php occ aiquila:configure --model "claude-sonnet-4-5-20250929"
```

**Set max tokens:**
```bash
php occ aiquila:configure --max-tokens 8192
```

**Set timeout:**
```bash
php occ aiquila:configure --timeout 120
```

**Set multiple options:**
```bash
php occ aiquila:configure \
  --api-key "sk-ant-api03-xxxxxxxxxxxxx" \
  --model "claude-sonnet-4-5-20250929" \
  --max-tokens 8192 \
  --timeout 120
```

**Configure a provider without making it the default:**
```bash
php occ aiquila:configure --target-provider local \
  --provider-key "<token>" --provider-model qwen3 \
  --provider-setting local_base_url=http://llama:8080 \
  --provider-setting local_auth_mode=bearer
```

**Make a provider the instance default:**
```bash
php occ aiquila:configure --provider local
```

`--provider` on its own also targets that provider, so
`--provider hetzner --provider-key <token>` configures and activates it in one
step; the default switches only after every value in the command was accepted.

**List a provider's settings by id:**
```bash
php occ aiquila:configure --show --target-provider local
```

`--provider-setting id=value` is repeatable and takes either the setting id from
that listing or its app config key (`timeout` or `local_timeout`). Values go
through the same validation as the admin settings page. Checkboxes take `on` /
`off`; access lists (`allowed_users`, `allowed_groups`, `blocked_users`,
`blocked_groups`) take comma-separated ids, and an empty value clears them.

### Testing Commands

**Basic test:**
```bash
php occ aiquila:test
```

**Test with custom prompt:**
```bash
php occ aiquila:test --prompt "Your test prompt here"
```

**Test for specific user:**
```bash
php occ aiquila:test --user username
```

**Test a specific provider:**
```bash
php occ aiquila:test --provider local
```

`--stream` is supported for `anthropic` only.

## Configuration Parameters

### API Key
- **Format**: Starts with `sk-ant-`
- **Obtaining**: Get from [Anthropic Console](https://console.anthropic.com/)
- **Security**: Never share or commit to version control
- **Validation**: Checked on first API request

### Model
Current Claude models:
- `claude-fable-5-1` - Most capable; thinking always on, xhigh effort
- `claude-fable-5` - Previous Fable; thinking always on, xhigh effort
- `claude-opus-5-5` - Newest Opus; thinking always on, xhigh effort
- `claude-opus-5` - Complex agentic coding and enterprise work; adaptive thinking (can be turned off up to effort high), xhigh effort
- `claude-sonnet-5` - Best speed/intelligence balance; adaptive thinking, medium effort (**default**)
- `claude-opus-4-8` - Previous-generation Opus; adaptive thinking, xhigh effort
- `claude-opus-4-7` - Adaptive thinking, xhigh effort
- `claude-opus-4-6` - Adaptive thinking, high effort
- `claude-sonnet-4-6` - Adaptive thinking, medium effort
- `claude-sonnet-4-5-20250929` - Fast, cost-effective
- `claude-haiku-4-5-20251001` - Fastest, most economical
- `claude-opus-4-5-20251101` - Older Opus generation

The Claude API rejects the `temperature` / `top_p` / `top_k` sampling parameters on every
model, so AIquila never sends them to Claude, on any provider. The OpenAI-compatible providers
(Mistral, DeepSeek, Local) still receive them where the backend accepts them.

How "thinking off" behaves per model is described under
[Thinking and effort](../../../installation/aiquila-setup.md#thinking-and-effort).

### Max Tokens
- **Range**: 1 - 100,000
- **Default**: 4096
- **Recommended**:
  - Short responses: 1024-2048
  - Normal responses: 4096
  - Long-form content: 8192+
- **Cost**: Higher tokens = higher API cost

### Timeout
- **Range**: 10 - 1800 seconds
- **Default**: 60 seconds
- **Recommended**:
  - Quick requests: 30-60s
  - Complex analysis: 120-180s
  - Long-running tasks: 300-600s

## Docker Installation

If using Docker, prefix all commands with:
```bash
docker exec -u www-data aiquila-nextcloud
```

**Full example:**
```bash
docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --show
```

### Finding Your Container Name

If your container isn't named `aiquila-nextcloud`:

```bash
# List all containers
docker ps

# Find Nextcloud container
docker ps | grep nextcloud

# Use the actual container name
docker exec -u www-data YOUR_CONTAINER_NAME php occ aiquila:configure --show
```

## SSH Installation

If accessing via SSH:

```bash
# SSH into server
ssh user@your-server.com

# Navigate to Nextcloud directory
cd /var/www/nextcloud

# Run OCC command
sudo -u www-data php occ aiquila:configure --show
```

## Troubleshooting

### Command not found
**Problem**: `aiquila:configure` or `aiquila:test` command not recognized

**Solution**:
- Verify AIquila app is installed: `php occ app:list | grep aiquila`
- Enable the app: `php occ app:enable aiquila`
- Check app version: `php occ app:info aiquila`

---

### Permission denied
**Problem**: Cannot execute OCC commands

**Solution**:
- Run as www-data user: `sudo -u www-data php occ ...`
- For Docker: Use `-u www-data` flag in `docker exec`
- Check file permissions on Nextcloud directory

---

### API key invalid
**Problem**: API key is rejected by Claude API

**Solution**:
- Verify API key format starts with `sk-ant-`
- Check key is active in [Anthropic Console](https://console.anthropic.com/)
- Ensure no extra spaces or quotes
- Try generating a new API key

---

### Timeout errors
**Problem**: Requests timing out

**Solution**:
- Increase timeout: `php occ aiquila:configure --timeout 120`
- Check network connectivity to Anthropic API
- Verify firewall allows HTTPS outbound
- Test with shorter prompt

## Security Best Practices

1. **API Key Storage**:
   - Keys are stored encrypted in Nextcloud database
   - Never log or display full API keys
   - Rotate keys periodically

2. **Access Control**:
   - Only admin users can configure AIquila
   - Use strong passwords for Nextcloud admin account
   - Enable two-factor authentication

3. **Network Security**:
   - All API calls use HTTPS
   - Validate SSL certificates
   - Use firewall to restrict outbound connections

## Integration with Other Tools

### Configuration Workflow
```
1. Use aiquila_show_config to check current settings
2. Use aiquila_configure to update settings
3. Use aiquila_test to verify changes
4. Use other MCP tools (files, tasks, etc.) with active configuration
```

### Testing Workflow
```
User: "Is AIquila configured correctly?"
1. Check configuration with aiquila_show_config
2. Run test with aiquila_test
3. Verify test succeeds
4. Confirm settings are optimal
```

## Future Development

These tools currently return command instructions. Future versions may include:
- Direct OCC command execution via Nextcloud API
- Real-time configuration updates
- Automated testing and health checks
- Configuration validation
- Multi-user settings management

## Development

To extend AIquila internal tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/aiquila.ts](../../../../mcp-server/src/tools/apps/aiquila.ts)
- AIquila app repository: [github.com/elgorro/aiquila](https://github.com/elgorro/aiquila)

## References

- [Anthropic Console](https://console.anthropic.com/)
- [Claude API Documentation](https://docs.anthropic.com/)
- [Nextcloud OCC Commands](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html)
- [AIquila Documentation](../../README.md)
