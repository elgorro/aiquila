document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aiquila-admin-form');
    const status = document.getElementById('aiquila-status');
    const testButton = document.getElementById('aiquila-test-config');
    const testResult = document.getElementById('aiquila-test-result');
    const testOutput = document.getElementById('aiquila-test-output');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const apiKey = document.getElementById('aiquila-api-key').value;
        const model = document.getElementById('aiquila-model').value;
        const maxTokens = document.getElementById('aiquila-max-tokens').value;
        const apiTimeout = document.getElementById('aiquila-timeout').value;

        status.textContent = 'Saving...';

        try {
            const response = await fetch(OC.generateUrl('/apps/aiquila/api/admin/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({
                    api_key: apiKey,
                    model: model,
                    max_tokens: maxTokens,
                    api_timeout: apiTimeout
                }),
            });

            if (response.ok) {
                status.textContent = 'Saved!';
                if (apiKey) {
                    document.getElementById('aiquila-api-key').value = '';
                    document.getElementById('aiquila-api-key').placeholder = 'API key configured';
                }
            } else {
                status.textContent = 'Error saving';
            }
        } catch (err) {
            status.textContent = 'Error: ' + err.message;
        }
    });

    testButton.addEventListener('click', async function() {
        const apiKey = document.getElementById('aiquila-api-key').value;
        const model = document.getElementById('aiquila-model').value;
        const maxTokens = document.getElementById('aiquila-max-tokens').value;
        const apiTimeout = document.getElementById('aiquila-timeout').value;

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
                body: JSON.stringify({
                    api_key: apiKey,
                    model: model,
                    max_tokens: maxTokens,
                    timeout: apiTimeout
                }),
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
});
