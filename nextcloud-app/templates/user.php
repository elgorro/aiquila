<?php
/** @var array $_ */
script('aiquila', 'user');
style('aiquila', 'user');
?>

<div id="aiquila-user" class="section">
    <h2>AIquila API Key</h2>

    <p class="settings-hint">
        Set a personal Claude API key to override the admin-configured key.
        Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
    </p>

    <form id="aiquila-user-form">
        <label for="aiquila-user-api-key">Personal Claude API Key</label>
        <input type="password"
               id="aiquila-user-api-key"
               name="api_key"
               placeholder="<?php echo $_['has_key'] ? 'Personal API key configured' : 'sk-ant-... (optional)'; ?>"
               value="">

        <button type="submit" class="primary">Save</button>
        <button type="button" id="aiquila-clear-key" class="secondary">Clear Key</button>
        <span id="aiquila-user-status"></span>
    </form>

    <p class="settings-note">
        Note: If no personal key is set, the admin-configured key will be used.
    </p>

    <h3>Native MCP connector</h3>
    <p class="settings-hint">
        When the admin has enabled the native MCP connector, Claude calls MCP servers directly over HTTPS instead of routing tool calls through this Nextcloud instance. You can override the admin default for your own conversations.
    </p>
    <form id="aiquila-native-mcp-user-form">
        <label><input type="radio" name="native_mcp_enabled" value=""> Inherit admin default</label><br>
        <label><input type="radio" name="native_mcp_enabled" value="1"> Always use native MCP connector (when reachable)</label><br>
        <label><input type="radio" name="native_mcp_enabled" value="0"> Always use the local agentic loop</label><br>
        <button type="submit" class="primary">Save</button>
        <span id="aiquila-native-mcp-user-status"></span>
    </form>
</div>
