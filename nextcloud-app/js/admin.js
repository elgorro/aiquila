document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiquila-admin-form');
    const status = document.getElementById('aiquila-status');
    const testButton = document.getElementById('aiquila-test-config');
    const testResult = document.getElementById('aiquila-test-result');
    const testOutput = document.getElementById('aiquila-test-output');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('aiquila-api-key').value;

        if (!apiKey) {
            status.textContent = 'Enter an API key to save.';
            return;
        }

        status.textContent = 'Saving...';

        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({ api_key: apiKey }),
            });

            if (response.ok) {
                status.textContent = 'Saved!';
                document.getElementById('aiquila-api-key').value = '';
                document.getElementById('aiquila-api-key').placeholder = 'API key configured';
            } else {
                status.textContent = 'Error saving';
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
        }
    });

    // ── MCP Servers ────────────────────────────────────────────────────

    const mcpList = document.getElementById('mcp-server-list');
    const mcpForm = document.getElementById('mcp-server-form');
    const mcpAddBtn = document.getElementById('mcp-add-server');
    const mcpAuthType = document.getElementById('mcp-auth-type');
    const mcpTokenGroup = document.getElementById('mcp-token-group');
    const mcpFormStatus = document.getElementById('mcp-form-status');
    const mcpOauthGroup = document.getElementById('mcp-oauth-group');
    const mcpOauthStatus = document.getElementById('mcp-oauth-status');

    mcpAuthType.addEventListener('change', function() {
        mcpTokenGroup.style.display = this.value === 'bearer' ? '' : 'none';
        mcpOauthGroup.style.display = this.value === 'oauth2' ? '' : 'none';
    });

    mcpAddBtn.addEventListener('click', function() {
        document.getElementById('mcp-server-id').value = '';
        document.getElementById('mcp-display-name').value = '';
        document.getElementById('mcp-url').value = '';
        mcpAuthType.value = 'none';
        document.getElementById('mcp-auth-token').value = '';
        mcpTokenGroup.style.display = 'none';
        mcpOauthGroup.style.display = 'none';
        mcpOauthStatus.textContent = '';
        document.getElementById('mcp-form-title').textContent = 'Add MCP Server';
        mcpFormStatus.textContent = '';
        mcpForm.style.display = '';
    });

    document.getElementById('mcp-cancel-form').addEventListener('click', function() {
        mcpForm.style.display = 'none';
    });

    // OAuth authenticate button
    document.getElementById('mcp-oauth-authenticate').addEventListener('click', async function() {
        var serverId = document.getElementById('mcp-server-id').value;
        if (!serverId) {
            mcpOauthStatus.textContent = 'Save the server first, then authenticate.';
            return;
        }

        mcpOauthStatus.textContent = 'Starting OAuth flow...';

        try {
            var response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/mcp-servers/' + serverId + '/oauth/authorize'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            });
            var result = await response.json();
            if (result.error) {
                mcpOauthStatus.textContent = 'Error: ' + result.error;
                return;
            }
            // Open popup to MCP server's authorize page
            var popup = window.open(result.authorize_url, 'aiquila-oauth', 'width=600,height=700,popup=yes');
            if (!popup) {
                mcpOauthStatus.textContent = 'Popup blocked. Please allow popups for this site.';
                return;
            }
            mcpOauthStatus.textContent = 'Waiting for authentication...';
        } catch (err) {
            mcpOauthStatus.textContent = 'Error: ' + err.message;
        }
    });

    // Listen for OAuth completion from popup
    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) return;
        if (event.data && event.data.type === 'aiquila-oauth-complete') {
            mcpOauthStatus.textContent = 'Authenticated!';
            mcpOauthStatus.style.color = 'var(--color-success)';
            mcpForm.style.display = 'none';
            loadMcpServers();
        }
    });

    document.getElementById('mcp-save-server').addEventListener('click', async function() {
        const id = document.getElementById('mcp-server-id').value;
        const data = {
            displayName: document.getElementById('mcp-display-name').value,
            url: document.getElementById('mcp-url').value,
            authType: mcpAuthType.value,
            authToken: document.getElementById('mcp-auth-token').value,
        };

        mcpFormStatus.textContent = 'Saving...';

        try {
            const url = id
                ? OC.generateUrl('/apps/aiquila/api/admin/mcp-servers/' + id)
                : OC.generateUrl('/apps/aiquila/api/admin/mcp-servers');
            const response = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify(data),
            });
            const result = await response.json();
            if (result.error) {
                mcpFormStatus.textContent = 'Error: ' + result.error;
            } else {
                mcpForm.style.display = 'none';
                loadMcpServers();
            }
        } catch (err) {
            mcpFormStatus.textContent = 'Error: ' + err.message;
        }
    });

    async function loadMcpServers() {
        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/mcp-servers'), {
                headers: { 'requesttoken': OC.requestToken },
            });
            const servers = await response.json();
            renderMcpServers(servers);
        } catch (err) {
            mcpList.innerHTML = '<p class="mcp-error">Failed to load MCP servers.</p>';
        }
    }

    function renderMcpServers(servers) {
        if (!servers.length) {
            mcpList.innerHTML = '<p class="settings-hint">No MCP servers configured.</p>';
            return;
        }

        mcpList.innerHTML = servers.map(function(s) {
            const statusClass = s.last_status === 'ok' ? 'mcp-status-ok' : (s.last_status === 'error' ? 'mcp-status-error' : 'mcp-status-unknown');
            const statusText = s.last_status || 'unknown';
            const toolInfo = s.tool_count !== null ? s.tool_count + ' tools' : '';
            const enabledLabel = s.is_enabled ? 'Enabled' : 'Disabled';

            var oauthBadge = '';
            if (s.auth_type === 'oauth2' && s.oauth_status) {
                var oauthClass = 'mcp-oauth-' + s.oauth_status.replace('_', '-');
                var oauthLabel = s.oauth_status === 'authenticated' ? 'OAuth: OK' : (s.oauth_status === 'expired' ? 'OAuth: Expired' : 'OAuth: Not authenticated');
                oauthBadge = ' | <span class="' + oauthClass + '">' + oauthLabel + '</span>';
            }

            return '<div class="mcp-server-card" data-id="' + s.id + '">'
                + '<div class="mcp-server-header">'
                + '<strong>' + escapeHtml(s.display_name) + '</strong>'
                + '<span class="mcp-status ' + statusClass + '">' + statusText + '</span>'
                + '</div>'
                + '<div class="mcp-server-details">'
                + '<span class="mcp-url">' + escapeHtml(s.url) + '</span>'
                + '<span class="mcp-meta">' + s.auth_type + ' | ' + enabledLabel + (toolInfo ? ' | ' + toolInfo : '') + oauthBadge + '</span>'
                + (s.last_error ? '<span class="mcp-error">' + escapeHtml(s.last_error) + '</span>' : '')
                + '</div>'
                + '<div class="mcp-server-actions">'
                + '<button class="mcp-test-btn" data-id="' + s.id + '">Test Connection</button>'
                + '<button class="mcp-edit-btn" data-id="' + s.id + '">Edit</button>'
                + '<button class="mcp-toggle-btn" data-id="' + s.id + '" data-enabled="' + (s.is_enabled ? '1' : '0') + '">' + (s.is_enabled ? 'Disable' : 'Enable') + '</button>'
                + '<button class="mcp-delete-btn" data-id="' + s.id + '">Delete</button>'
                + '<span class="mcp-test-result" data-id="' + s.id + '"></span>'
                + '</div>'
                + '</div>';
        }).join('');

        // Bind action buttons
        mcpList.querySelectorAll('.mcp-test-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { testMcpServer(this.dataset.id); });
        });
        mcpList.querySelectorAll('.mcp-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { editMcpServer(this.dataset.id, servers); });
        });
        mcpList.querySelectorAll('.mcp-toggle-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { toggleMcpServer(this.dataset.id, this.dataset.enabled === '1'); });
        });
        mcpList.querySelectorAll('.mcp-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { deleteMcpServer(this.dataset.id); });
        });
    }

    async function testMcpServer(id) {
        const resultSpan = mcpList.querySelector('.mcp-test-result[data-id="' + id + '"]');
        resultSpan.textContent = 'Testing...';
        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/mcp-servers/' + id + '/test'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
            });
            const result = await response.json();
            resultSpan.textContent = result.success ? result.message : 'Error: ' + result.message;
            resultSpan.style.color = result.success ? 'var(--color-success)' : 'var(--color-error)';
            loadMcpServers();
        } catch (err) {
            resultSpan.textContent = 'Error: ' + err.message;
            resultSpan.style.color = 'var(--color-error)';
        }
    }

    function editMcpServer(id, servers) {
        const server = servers.find(function(s) { return s.id == id; });
        if (!server) return;
        document.getElementById('mcp-server-id').value = server.id;
        document.getElementById('mcp-display-name').value = server.display_name;
        document.getElementById('mcp-url').value = server.url;
        mcpAuthType.value = server.auth_type;
        mcpTokenGroup.style.display = server.auth_type === 'bearer' ? '' : 'none';
        mcpOauthGroup.style.display = server.auth_type === 'oauth2' ? '' : 'none';
        if (server.auth_type === 'oauth2' && server.oauth_status) {
            var label = server.oauth_status === 'authenticated' ? 'Authenticated' : (server.oauth_status === 'expired' ? 'Token expired — re-authenticate' : 'Not authenticated');
            mcpOauthStatus.textContent = label;
            mcpOauthStatus.style.color = server.oauth_status === 'authenticated' ? 'var(--color-success)' : 'var(--color-warning)';
        } else {
            mcpOauthStatus.textContent = '';
        }
        document.getElementById('mcp-auth-token').value = '';
        document.getElementById('mcp-form-title').textContent = 'Edit MCP Server';
        mcpFormStatus.textContent = '';
        mcpForm.style.display = '';
    }

    async function toggleMcpServer(id, currentlyEnabled) {
        try {
            await fetch(OC.generateUrl('/apps/aiquila/api/admin/mcp-servers/' + id), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ isEnabled: !currentlyEnabled }),
            });
            loadMcpServers();
        } catch (err) {
            // ignore
        }
    }

    async function deleteMcpServer(id) {
        if (!confirm('Delete this MCP server?')) return;
        try {
            await fetch(OC.generateUrl('/apps/aiquila/api/admin/mcp-servers/' + id), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken },
            });
            loadMcpServers();
        } catch (err) {
            // ignore
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Load MCP servers on page load
    loadMcpServers();

    // ── Test Configuration ──────────────────────────────────────────────

    testButton.addEventListener('click', async function() {
        const apiKey = document.getElementById('aiquila-api-key').value;

        testButton.disabled = true;
        testButton.textContent = 'Testing...';
        testResult.style.display = 'none';

        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/test'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({ api_key: apiKey }),
            });

            const result = await response.json();

            testResult.style.display = 'block';
            if (result.success) {
                testOutput.textContent = '✓ Success: ' + result.message;
                testOutput.style.color = 'green';
            } else {
                testOutput.textContent = '✗ Error: ' + result.message;
                testOutput.style.color = 'red';
            }
        } catch (err) {
            testResult.style.display = 'block';
            testOutput.textContent = '✗ Exception: ' + err.message;
            testOutput.style.color = 'red';
        } finally {
            testButton.disabled = false;
            testButton.textContent = 'Test Configuration';
        }
    });

    // ── Native MCP connector ───────────────────────────────────────────
    const nativeEnabled = document.getElementById('aiquila-native-mcp-enabled');
    const nativeExtraUrl = document.getElementById('aiquila-native-mcp-extra-url');
    const nativeExtraToken = document.getElementById('aiquila-native-mcp-extra-token');
    const nativeSaveBtn = document.getElementById('aiquila-native-mcp-save');
    const nativeRefreshBtn = document.getElementById('aiquila-native-mcp-refresh');
    const nativeStatus = document.getElementById('aiquila-native-mcp-status');
    const nativeServersBox = document.getElementById('aiquila-native-mcp-servers');

    async function loadNativeMcpStatus() {
        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/native-mcp/status'), {
                headers: { 'requesttoken': OC.requestToken },
            });
            if (!response.ok) {
                nativeStatus.textContent = 'Failed to load native MCP status';
                return;
            }
            const data = await response.json();
            nativeEnabled.checked = !!data.enabled;
            nativeExtraUrl.value = data.extraUrl || '';
            nativeExtraToken.placeholder = data.hasExtraToken ? 'Token configured (leave blank to keep)' : 'Bearer token for the URL above';
            renderServers(data.servers || []);
        } catch (err) {
            nativeStatus.textContent = 'Error: ' + err.message;
        }
    }

    function renderServers(servers) {
        if (!nativeServersBox) return;
        if (servers.length === 0) {
            nativeServersBox.innerHTML = '<p class="hint">No MCP servers configured. Add one above to forward it via the native connector.</p>';
            return;
        }
        const rows = servers.map(s => {
            const dot = s.reachable ? '🟢' : (s.scheme_ok ? '🟡' : '🔴');
            const detail = s.reachable
                ? 'reachable (HTTP ' + (s.http_status ?? '?') + ')'
                : (s.scheme_ok ? 'HTTPS but not reachable: ' + (s.message || '?') : 'not HTTPS — Anthropic cannot reach this URL');
            return '<li>' + dot + ' <code>' + escapeHtml(s.url) + '</code> — ' + escapeHtml(s.name) + ' — ' + escapeHtml(detail) + '</li>';
        }).join('');
        nativeServersBox.innerHTML = '<ul>' + rows + '</ul>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    if (nativeSaveBtn) {
        nativeSaveBtn.addEventListener('click', async function() {
            nativeStatus.textContent = 'Saving...';
            const body = {
                native_mcp_enabled: nativeEnabled.checked ? '1' : '0',
                native_mcp_extra_url: nativeExtraUrl.value,
            };
            if (nativeExtraToken.value !== '') {
                body.native_mcp_extra_token = nativeExtraToken.value;
            }
            try {
                const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/settings'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify(body),
                });
                if (response.ok) {
                    nativeStatus.textContent = 'Saved!';
                    nativeExtraToken.value = '';
                    loadNativeMcpStatus();
                } else {
                    nativeStatus.textContent = 'Error saving';
                }
            } catch (err) {
                nativeStatus.textContent = 'Error: ' + err.message;
            }
        });
    }
    if (nativeRefreshBtn) {
        nativeRefreshBtn.addEventListener('click', loadNativeMcpStatus);
    }
    if (nativeEnabled) {
        loadNativeMcpStatus();
    }
});
