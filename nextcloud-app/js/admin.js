document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiquila-admin-form');
    const status = document.getElementById('aiquila-status');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('aiquila-api-key').value;

        if (!apiKey) {
            status.textContent = 'Please enter an API key';
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
});
