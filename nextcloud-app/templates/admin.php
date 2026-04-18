<?php
/** @var array $_ */
script('aiquila', 'admin');
style('aiquila', 'admin');
?>

<div id="aiquila-admin" class="section">
    <h2>AIquila</h2>
    <p class="settings-hint">
        AIquila connects Nextcloud to Anthropic's Claude models so your users can chat, summarise, translate and more directly inside Nextcloud. You need an Anthropic API key to get started; model and request tuning live in the cards further down this page.
    </p>

    <div class="section">
        <h3>Claude API key</h3>
        <p class="settings-hint">
            Paste your Anthropic API key below. Get one from
            <a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>.
            The key is stored encrypted in Nextcloud's credential manager and used for every request unless a user provides their own in personal settings. Use <em>Test Configuration</em> after saving to confirm the key actually reaches Anthropic.
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

            <button type="submit" class="primary">Save</button>
            <button type="button" id="aiquila-test-config" class="secondary">Test Configuration</button>
            <span id="aiquila-status"></span>
        </form>

        <div id="aiquila-test-result" style="display: none;">
            <h4>Test Result</h4>
            <pre id="aiquila-test-output"></pre>
        </div>
    </div>

    <div id="aiquila-mcp-servers" class="section">
        <h3>MCP servers</h3>
        <p class="settings-hint">
            Model Context Protocol (MCP) servers give Claude tools — files, calendar, notes, and so on — that it can call during a conversation. Each server you add here becomes available to every user on this instance.
            New to MCP? Read
            <a href="https://modelcontextprotocol.io/docs/getting-started/intro" target="_blank" rel="noopener noreferrer">What's MCP?</a>
            or follow the
            <a href="https://github.com/elgorro/aiquila/tree/main/docs/installation/mcp-installation.md" target="_blank" rel="noopener noreferrer">free MCP installation guide</a>
            to run the bundled AIquila MCP server.
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

    <div class="aiquila-resources section">
        <h3>Resources</h3>
        <ul>
            <li><a href="https://github.com/elgorro/aiquila" target="_blank" rel="noopener noreferrer">📦 GitHub repository</a></li>
            <li><a href="https://github.com/elgorro/aiquila/tree/main/docs" target="_blank" rel="noopener noreferrer">📖 Documentation</a></li>
            <li><a href="https://github.com/elgorro/aiquila/issues" target="_blank" rel="noopener noreferrer">🐛 Report issues</a></li>
            <li><a href="https://github.com/elgorro/aiquila/discussions" target="_blank" rel="noopener noreferrer">💬 Discussions</a></li>
        </ul>
    </div>
</div>
