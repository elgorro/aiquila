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
None

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

Configure AIquila settings including API key, Claude model, max tokens, and API timeout.

**Parameters:**
- `apiKey` (string, optional): Anthropic API key (starts with `sk-ant-`)
- `model` (string, optional): Claude model identifier (e.g., `claude-sonnet-4-5-20250929`)
- `maxTokens` (number, optional): Maximum tokens for responses (1-100000)
- `timeout` (number, optional): API request timeout in seconds (10-1800)

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

---

### aiquila_test

Test AIquila's Claude API integration with a test prompt.

**Parameters:**
- `prompt` (string, optional): Test prompt to send to Claude API. Default: `"Hello, Claude!"`
- `user` (string, optional): Nextcloud user ID to test with

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
Testing AIquila Claude Integration...

✓ Configuration loaded
✓ API key present
✓ Sending request to Claude API...

Response from Claude:
"Hello! I'm Claude, an AI assistant created by Anthropic. How can I help you today?"

✓ Test successful!
```

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

## Configuration Parameters

### API Key
- **Format**: Starts with `sk-ant-`
- **Obtaining**: Get from [Anthropic Console](https://console.anthropic.com/)
- **Security**: Never share or commit to version control
- **Validation**: Checked on first API request

### Model
Current Claude models (as of January 2025):
- `claude-sonnet-4-5-20250929` - Latest Sonnet (recommended)
- `claude-opus-4-5-20250929` - Most capable, slower
- `claude-3-5-sonnet-20241022` - Previous Sonnet version

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
