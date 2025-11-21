document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('nextclaude-admin-form');
    const status = document.getElementById('nextclaude-status');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('nextclaude-api-key').value;

        if (!apiKey) {
            status.textContent = 'Please enter an API key';
            return;
        }

        status.textContent = 'Saving...';

        try {
            const response = await fetch(OC.generateUrl('/apps/nextclaude/api/admin/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({ api_key: apiKey }),
            });

            if (response.ok) {
                status.textContent = 'Saved!';
                document.getElementById('nextclaude-api-key').value = '';
                document.getElementById('nextclaude-api-key').placeholder = 'API key configured';
            } else {
                status.textContent = 'Error saving';
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
        }
    });
});
