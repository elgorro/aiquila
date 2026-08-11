<?php
/** @var array $_ */
script('aiquila', 'admin');
style('aiquila', 'admin');
?>

<div id="aiquila-admin" class="section">
    <h2>AIquila</h2>
    <p class="settings-hint">
        AIquila connects Nextcloud to large language models so your users can chat, summarise, translate and more directly inside Nextcloud. Pick a provider, paste its API key to get started; model and request tuning live in the cards further down this page.
    </p>

    <div class="section">
        <h3>AI provider &amp; API key</h3>
        <p class="settings-hint">
            Choose the default provider for this instance and paste its API key below. Anthropic keys come from
            <a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>;
            Mistral keys from
            <a href="https://console.mistral.ai/" target="_blank" rel="noopener noreferrer">console.mistral.ai</a>;
            DeepSeek keys from
            <a href="https://platform.deepseek.com/" target="_blank" rel="noopener noreferrer">platform.deepseek.com</a>.
            <strong>Local model</strong> needs no key — configure its endpoint in the card below instead.
            Keys are stored encrypted in Nextcloud's credential manager. Users may override both provider and key in personal settings. Use <em>Test Configuration</em> after saving to confirm the key reaches the provider.
        </p>

        <?php
            $providers = $_['providers'] ?? [];
            $activeProvider = $_['provider'] ?? 'anthropic';
            $hasKeyMap = [];
            foreach ($providers as $p) {
                $hasKeyMap[$p['id']] = (bool)$p['has_key'];
            }
        ?>

        <form id="aiquila-admin-form" data-haskey-map='<?php echo htmlspecialchars(json_encode($hasKeyMap), ENT_QUOTES); ?>'>
            <div class="form-group">
                <label for="aiquila-provider">Default provider</label>
                <select id="aiquila-provider" name="provider">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['id']); ?>" <?php echo $p['id'] === $activeProvider ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="aiquila-api-key">API Key for selected provider</label>
                <input type="password"
                       id="aiquila-api-key"
                       name="api_key"
                       placeholder="<?php echo ($hasKeyMap[$activeProvider] ?? false) ? 'API key configured' : 'Enter API key…'; ?>"
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

    <div id="aiquila-local-model" class="section">
        <h3>Local model endpoint</h3>
        <p class="settings-hint">
            Run inference on your own hardware instead of a hosted API — nothing leaves your infrastructure.
            <a href="https://ollama.com/" target="_blank" rel="noopener noreferrer">Ollama</a>,
            <a href="https://lmstudio.ai/" target="_blank" rel="noopener noreferrer">LM Studio</a> and
            llama.cpp's <code>llama-server</code> all speak the same OpenAI-compatible protocol, so one endpoint setting
            covers all three (as well as vLLM and LocalAI). Pick a preset below, adjust the host if the server runs
            elsewhere, then choose <strong>Local model</strong> as the default provider above.
        </p>

        <div class="form-group">
            <label for="aiquila-local-base-url">Endpoint base URL</label>
            <input type="text"
                   id="aiquila-local-base-url"
                   placeholder="http://localhost:11434"
                   style="min-width: 26rem;">
            <p class="hint">
                Presets:
                <button type="button" class="secondary aiquila-local-preset" data-url="http://localhost:11434">Ollama</button>
                <button type="button" class="secondary aiquila-local-preset" data-url="http://localhost:1234">LM Studio</button>
                <button type="button" class="secondary aiquila-local-preset" data-url="http://localhost:8080">llama.cpp</button>
                <br>
                The <code>/v1</code> suffix is added automatically. If Nextcloud runs in Docker, <code>localhost</code>
                is the container — use <code>http://host.docker.internal:11434</code> or the service name on the shared network instead.
            </p>
        </div>

        <div class="form-group">
            <label for="aiquila-local-api-key">Bearer token (optional)</label>
            <input type="password"
                   id="aiquila-local-api-key"
                   placeholder="Leave blank if the endpoint has no authentication"
                   style="min-width: 26rem;">
            <p class="hint">Ollama has no built-in authentication. LM Studio and <code>llama-server --api-key</code> accept a token; so does a reverse proxy in front of any of them. Stored encrypted in Nextcloud's credential manager.</p>
        </div>

        <div class="form-group">
            <label for="aiquila-local-model">Model</label>
            <input type="text" id="aiquila-local-model" placeholder="llama3.2" list="aiquila-local-model-list">
            <datalist id="aiquila-local-model-list"></datalist>
            <p class="hint">The model tag as the server reports it. Save the URL first — the list then autocompletes from the endpoint's own <code>/v1/models</code>.</p>
        </div>

        <div class="form-group">
            <label for="aiquila-local-max-tokens">Max response tokens</label>
            <input type="text" id="aiquila-local-max-tokens" placeholder="4096">
        </div>

        <div class="form-group">
            <label for="aiquila-local-timeout">Request timeout (seconds)</label>
            <input type="text" id="aiquila-local-timeout" placeholder="300">
            <p class="hint">Local inference on CPU is slow; the hosted-provider default of 30 seconds is usually far too low.</p>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="aiquila-local-vision">
                The loaded model accepts images
            </label>
            <p class="hint">Only enable for a multimodal model (llava, llama3.2-vision, qwen2-vl, …). Otherwise image requests are rejected up front instead of failing at the endpoint.</p>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="aiquila-local-allow-local-address">
                Allow requests to local and private addresses
            </label>
            <p class="hint">Required for <code>localhost</code>, Docker networks and LAN hosts — Nextcloud blocks these by default to prevent server-side request forgery. Turn it off if your endpoint has a public hostname.</p>
        </div>

        <button type="button" id="aiquila-local-save" class="primary">Save local model settings</button>
        <button type="button" id="aiquila-local-test" class="secondary">Test connection</button>
        <span id="aiquila-local-status"></span>
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

    <div id="aiquila-native-mcp" class="section">
        <h3>Native MCP connector (beta)</h3>
        <p class="settings-hint">
            When enabled, Claude calls each MCP server <strong>directly</strong> over HTTPS instead of routing tool calls through this Nextcloud instance. Anthropic's API takes the place of AIquila's PHP-side agentic loop and returns
            <code>mcp_tool_use</code> / <code>mcp_tool_result</code> blocks.
            Reads the SDK 0.20 beta header <code>mcp-client-2025-11-20</code>; only feasible if your MCP servers are publicly reachable over HTTPS from Anthropic's infrastructure. Not eligible for Zero Data Retention deployments. Read the
            <a href="https://github.com/elgorro/aiquila/tree/main/docs/dev/native-mcp-connector.md" target="_blank" rel="noopener noreferrer">native MCP connector notes</a>
            before flipping this on.
        </p>

        <div class="form-group">
            <label>
                <input type="checkbox" id="aiquila-native-mcp-enabled">
                Enable native MCP connector
            </label>
        </div>

        <div class="form-group">
            <label for="aiquila-native-mcp-extra-url">Extra MCP URL (optional)</label>
            <input type="text"
                   id="aiquila-native-mcp-extra-url"
                   placeholder="https://mcp.example.com/mcp"
                   style="min-width: 26rem;">
            <p class="hint">Forwarded in addition to the per-user MCP servers above. Use only if the URL is publicly reachable over HTTPS.</p>
        </div>
        <div class="form-group">
            <label for="aiquila-native-mcp-extra-token">Extra MCP bearer token (optional)</label>
            <input type="password"
                   id="aiquila-native-mcp-extra-token"
                   placeholder="Bearer token for the URL above"
                   style="min-width: 26rem;">
            <p class="hint">Leave blank to keep the existing token. Submit an empty save with this row cleared to remove it.</p>
        </div>

        <div class="form-group">
            <label for="aiquila-mistral-connector-ids">Mistral connector ID(s) (provider: Mistral)</label>
            <input type="text"
                   id="aiquila-mistral-connector-ids"
                   placeholder="connector-id-or-name, another-id"
                   style="min-width: 26rem;">
            <p class="hint">Used only when the active provider is <strong>Mistral</strong>. Register the AIquila MCP server as a connector in the
                <a href="https://console.mistral.ai/" target="_blank" rel="noopener noreferrer">Mistral console</a>
                (put its auth in the connector's headers), then paste the connector ID(s) here, comma- or space-separated. The native-MCP path then runs via Mistral's Conversations API using the admin Mistral API key.</p>
        </div>

        <button type="button" id="aiquila-native-mcp-save" class="primary">Save native MCP settings</button>
        <button type="button" id="aiquila-native-mcp-refresh" class="secondary">Refresh reachability</button>
        <span id="aiquila-native-mcp-status"></span>

        <div id="aiquila-native-mcp-servers" style="margin-top: 0.75rem;"></div>
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
