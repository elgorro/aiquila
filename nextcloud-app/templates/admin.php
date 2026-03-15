<?php
/** @var array $_ */
script('aiquila', 'admin');
style('aiquila', 'admin');
?>

<div id="aiquila-admin" class="section">
    <h2>AIquila Settings</h2>

    <p class="settings-hint">
        Configure Claude AI integration. Get your API key from
        <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
    </p>

    <form id="aiquila-admin-form">
        <div class="form-group">
            <label for="aiquila-api-key">Claude API Key</label>
            <input type="password"
                   id="aiquila-api-key"
                   name="api_key"
                   placeholder="<?php echo $_['has_key'] ? 'API key configured' : 'sk-ant-...'; ?>"
                   value="">
        </div>

        <h3>Advanced Settings</h3>

        <div class="form-group">
            <label for="aiquila-model">Claude Model</label>
            <input type="text"
                   id="aiquila-model"
                   name="model"
                   value="<?php echo htmlspecialchars($_['model']); ?>"
                   placeholder="<?php echo htmlspecialchars($_['default_model']); ?>"
                   list="model-suggestions">
            <datalist id="model-suggestions">
                <?php foreach ($_['available_models'] as $m): ?>
                    <option value="<?php echo htmlspecialchars($m); ?>">
                <?php endforeach; ?>
            </datalist>
            <p class="hint">Default: <?php echo htmlspecialchars($_['default_model']); ?> (Claude Sonnet 4.5, recommended). claude-opus-4-6 supports adaptive thinking and 128K output tokens.</p>
        </div>

        <div class="form-group">
            <label for="aiquila-max-tokens">Max Tokens</label>
            <input type="number"
                   id="aiquila-max-tokens"
                   name="max_tokens"
                   value="<?php echo $_['max_tokens']; ?>"
                   min="1"
                   max="100000"
                   step="1">
            <p class="hint">Default: 4096 (range: 1-100,000)</p>
        </div>

        <div class="form-group">
            <label for="aiquila-timeout">API Timeout (seconds)</label>
            <input type="number"
                   id="aiquila-timeout"
                   name="api_timeout"
                   value="<?php echo $_['api_timeout']; ?>"
                   min="10"
                   max="1800"
                   step="5">
            <p class="hint">Default: 30 seconds (range: 10-1800 = up to 30 minutes)</p>
        </div>

        <button type="submit" class="primary">Save</button>
        <button type="button" id="aiquila-test-config" class="secondary">Test Configuration</button>
        <span id="aiquila-status"></span>
    </form>

    <div id="aiquila-test-result" style="display: none;">
        <h3>Test Result</h3>
        <pre id="aiquila-test-output"></pre>
    </div>

    <div id="aiquila-mcp-servers" class="section">
        <h3>MCP Servers</h3>
        <p class="settings-hint">
            Connect MCP servers to give Claude access to tools (files, calendar, notes, etc.)
        </p>

        <div id="mcp-server-list"></div>

        <button type="button" id="mcp-add-server" class="primary">Add MCP Server</button>

        <div id="mcp-server-form" style="display: none;">
            <h4 id="mcp-form-title">Add MCP Server</h4>
            <input type="hidden" id="mcp-server-id" value="">
            <div class="form-group">
                <label for="mcp-display-name">Name</label>
                <input type="text" id="mcp-display-name" placeholder="My MCP Server">
            </div>
            <div class="form-group">
                <label for="mcp-url">URL</label>
                <input type="text" id="mcp-url" placeholder="http://localhost:3339/mcp">
            </div>
            <div class="form-group">
                <label for="mcp-auth-type">Authentication</label>
                <select id="mcp-auth-type">
                    <option value="none">None</option>
                    <option value="bearer">Bearer Token</option>
                    <option value="oauth2">OAuth 2.1 (PKCE)</option>
                </select>
            </div>
            <div class="form-group" id="mcp-token-group" style="display: none;">
                <label for="mcp-auth-token">Bearer Token</label>
                <input type="password" id="mcp-auth-token" placeholder="Token">
            </div>
            <div class="form-group" id="mcp-oauth-group" style="display: none;">
                <button type="button" id="mcp-oauth-authenticate" class="secondary">Authenticate</button>
                <span id="mcp-oauth-status"></span>
                <p class="hint">Click to open the MCP server login page and authorize access.</p>
            </div>
            <button type="button" id="mcp-save-server" class="primary">Save</button>
            <button type="button" id="mcp-cancel-form">Cancel</button>
            <span id="mcp-form-status"></span>
        </div>
    </div>

    <div class="aiquila-resources">
        <h3>MCP</h3>
        <ul>
            <li><a href="https://github.com/elgorro/aiquila/tree/main/docs/installation/mcp-installation.md" target="_blank" rel="noopener noreferrer">🧭 Free MCP Installation</a></li>
            <li><a href="https://modelcontextprotocol.io/docs/getting-started/intro" target="_blank" rel="noopener noreferrer">📖 What's MCP?</a></li>
        </ul>
    </div>

    <div class="aiquila-resources">
        <h3>Resources</h3>
        <ul>
            <li><a href="https://github.com/elgorro/aiquila" target="_blank" rel="noopener noreferrer">📦 GitHub Repository</a></li>
            <li><a href="https://github.com/elgorro/aiquila/tree/main/docs" target="_blank" rel="noopener noreferrer">📖 Documentation</a></li>
            <li><a href="https://github.com/elgorro/aiquila/issues" target="_blank" rel="noopener noreferrer">🐛 Report Issues</a></li>
            <li><a href="https://github.com/elgorro/aiquila/discussions" target="_blank" rel="noopener noreferrer">💬 Discussions</a></li>
        </ul>
    </div>
</div>
