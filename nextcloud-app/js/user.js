document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiquila-user-form');
    const status = document.getElementById('aiquila-user-status');
    const clearBtn = document.getElementById('aiquila-clear-key');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('aiquila-user-api-key').value;

        if (!apiKey) {
            status.textContent = 'Please enter an API key';
            status.className = 'error';
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
