document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiquila-user-form');
    const status = document.getElementById('aiquila-user-status');
    const clearBtn = document.getElementById('aiquila-clear-key');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('aiquila-user-api-key').value;

        if (!apiKey) {
            status.textContent = 'Enter an API key to save.';
            return;
        }

        status.textContent = 'Saving...';
        status.className = '';

        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({ api_key: apiKey }),
            });

            if (response.ok) {
                status.textContent = 'Saved!';
                status.className = 'success';
                document.getElementById('aiquila-user-api-key').value = '';
                document.getElementById('aiquila-user-api-key').placeholder = 'Personal API key configured';
            } else {
                status.textContent = 'Error saving';
                status.className = 'error';
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
            status.className = 'error';
        }
    });

    // ── Native MCP override ────────────────────────────────────────────
    const nativeForm = document.getElementById('aiquila-native-mcp-user-form');
    const nativeStatus = document.getElementById('aiquila-native-mcp-user-status');
    if (nativeForm) {
        // Load current setting and select the matching radio.
        fetch(OC.generateUrl('/apps/aiquila/api/settings'), {
            headers: { 'requesttoken': OC.requestToken },
        }).then(r => r.json()).then(d => {
            const v = d && typeof d.nativeMcpUserOverride === 'string' ? d.nativeMcpUserOverride : '';
            const radio = nativeForm.querySelector('input[name="native_mcp_enabled"][value="' + v + '"]');
            if (radio) radio.checked = true;
        }).catch(() => {});

        nativeForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const selected = nativeForm.querySelector('input[name="native_mcp_enabled"]:checked');
            const value = selected ? selected.value : '';
            nativeStatus.textContent = 'Saving...';
            try {
                const response = await fetch(OC.generateUrl('/apps/aiquila/api/settings'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ native_mcp_enabled: value }),
                });
                nativeStatus.textContent = response.ok ? 'Saved!' : 'Error saving';
            } catch (err) {
                nativeStatus.textContent = 'Error: ' + err.message;
            }
        });
    }

    clearBtn.addEventListener('click', async function() {
        if (!confirm('Are you sure you want to clear your personal API key?')) {
            return;
        }

        status.textContent = 'Clearing...';
        status.className = '';

        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({ api_key: '' }),
            });

            if (response.ok) {
                status.textContent = 'Personal key cleared!';
                status.className = 'success';
                document.getElementById('aiquila-user-api-key').value = '';
                document.getElementById('aiquila-user-api-key').placeholder = 'sk-ant-... (optional)';
            } else {
                status.textContent = 'Error clearing';
                status.className = 'error';
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
            status.className = 'error';
        }
    });
});
