/**
 * NextClaude File Actions
 * Adds "Ask Claude" to file context menu
 */

(function() {
    if (!OCA.Files) return;

    const fileActions = OCA.Files.fileActions;
    if (!fileActions) return;

    // Supported file types for text analysis
    const supportedMimes = [
        'text/plain',
        'text/markdown',
        'text/html',
        'application/json',
        'application/xml',
        'text/csv',
    ];

    fileActions.registerAction({
        name: 'nextclaude-ask',
        displayName: t('nextclaude', 'Ask Claude'),
        mime: 'text',
        permissions: OC.PERMISSION_READ,
        iconClass: 'icon-comment',
        actionHandler: async function(fileName, context) {
            const fileId = context.fileInfoModel.get('id');
            const filePath = context.dir + '/' + fileName;

            // Create modal dialog
            const dialog = document.createElement('div');
            dialog.innerHTML = `
                <div id="nextclaude-dialog" style="padding: 20px;">
                    <h3>Ask Claude about "${fileName}"</h3>
                    <textarea id="nextclaude-prompt" placeholder="What would you like to know about this file?" style="width: 100%; height: 100px; margin: 10px 0;"></textarea>
                    <div style="margin-top: 10px;">
                        <button id="nextclaude-summarize" class="primary">Summarize</button>
                        <button id="nextclaude-ask-btn" class="primary">Ask</button>
                    </div>
                    <div id="nextclaude-response" style="margin-top: 15px; white-space: pre-wrap;"></div>
                </div>
            `;

            OC.dialogs.message(dialog.innerHTML, t('nextclaude', 'NextClaude'), 'none', OC.dialogs.YES_NO_BUTTONS, function() {}, true);

            setTimeout(() => {
                const summarizeBtn = document.getElementById('nextclaude-summarize');
                const askBtn = document.getElementById('nextclaude-ask-btn');
                const responseDiv = document.getElementById('nextclaude-response');
                const promptInput = document.getElementById('nextclaude-prompt');

                async function callClaude(action, data) {
                    responseDiv.textContent = 'Loading...';
                    try {
                        const response = await fetch(OC.generateUrl(`/apps/nextclaude/api/${action}`), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'requesttoken': OC.requestToken,
                            },
                            body: JSON.stringify(data),
                        });
                        const result = await response.json();
                        responseDiv.textContent = result.response || result.error || 'No response';
                    } catch (err) {
                        responseDiv.textContent = 'Error: ' + err.message;
                    }
                }

                // First fetch file content
                async function getFileContent() {
                    const response = await fetch(OC.generateUrl(`/remote.php/webdav${filePath}`), {
                        headers: { 'requesttoken': OC.requestToken }
                    });
                    return response.text();
                }

                if (summarizeBtn) {
                    summarizeBtn.addEventListener('click', async () => {
                        const content = await getFileContent();
                        callClaude('summarize', { content });
                    });
                }

                if (askBtn) {
                    askBtn.addEventListener('click', async () => {
                        const prompt = promptInput.value;
                        if (!prompt) return;
                        const content = await getFileContent();
                        callClaude('ask', { prompt, context: content });
                    });
                }
            }, 100);
        },
    });
})();
