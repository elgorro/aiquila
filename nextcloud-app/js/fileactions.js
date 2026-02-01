/**
 * AIquila File Actions
 * Adds "Ask Claude" to file context menu
 */

(function() {
    console.log('[AIquila] Initializing file actions');

    // Wait for Files app to be ready
    if (!OCA.Files) {
        console.warn('[AIquila] OCA.Files not available yet, retrying...');
        setTimeout(arguments.callee, 100);
        return;
    }

    if (!OCA.Files.fileActions) {
        console.warn('[AIquila] fileActions not available yet, retrying...');
        setTimeout(arguments.callee, 100);
        return;
    }

    const fileActions = OCA.Files.fileActions;
    console.log('[AIquila] fileActions available, registering action');

    // File size limits
    const DEFAULT_MAX_SIZE = 5 * 1024 * 1024; // 5MB default
    const WARNING_SIZE = 5 * 1024 * 1024; // Warn at 5MB

    try {
        fileActions.registerAction({
            name: 'AiquilaAsk',
            displayName: t('aiquila', 'Ask Claude'),
            mime: 'all',  // Changed from 'text' to 'all' to show for all files
            permissions: OC.PERMISSION_READ,
            icon: OC.imagePath('core', 'actions/comment'),
            type: OCA.Files.FileActions.TYPE_DROPDOWN,  // Show in dropdown menu
            actionHandler: async function(fileName, context) {
                console.log('[AIquila] Action triggered for:', fileName);
            const filePath = context.dir + '/' + fileName;
            const fileSize = context.fileInfoModel.get('size');

            // Check file size and show warning if needed
            let warningHtml = '';
            if (fileSize > WARNING_SIZE) {
                const sizeMB = (fileSize / (1024 * 1024)).toFixed(2);
                warningHtml = `
                    <div style="background-color: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 4px;">
                        <strong>⚠️ Large File Warning</strong><br>
                        This file is ${sizeMB}MB. Processing files larger than 5MB may fail or take a long time.
                        The default maximum content size is 5MB. Consider selecting a smaller portion of the file or asking your administrator to increase the limit.
                    </div>
                `;
            }

            // Create modal dialog
            const dialog = document.createElement('div');
            dialog.innerHTML = `
                <div id="aiquila-dialog" style="padding: 20px;">
                    <h3>Ask Claude about "${fileName}"</h3>
                    ${warningHtml}
                    <textarea id="aiquila-prompt" placeholder="What would you like to know about this file?" style="width: 100%; height: 100px; margin: 10px 0;"></textarea>
                    <div style="margin-top: 10px;">
                        <button id="aiquila-summarize" class="primary">Summarize</button>
                        <button id="aiquila-ask-btn" class="primary">Ask</button>
                    </div>
                    <div id="aiquila-response" style="margin-top: 15px; white-space: pre-wrap;"></div>
                </div>
            `;

            OC.dialogs.message(dialog.innerHTML, t('aiquila', 'AIquila'), 'none', OC.dialogs.YES_NO_BUTTONS, function() {}, true);

            setTimeout(() => {
                const summarizeBtn = document.getElementById('aiquila-summarize');
                const askBtn = document.getElementById('aiquila-ask-btn');
                const responseDiv = document.getElementById('aiquila-response');
                const promptInput = document.getElementById('aiquila-prompt');

                async function callClaude(action, data) {
                    responseDiv.textContent = 'Loading...';
                    try {
                        const response = await fetch(OC.generateUrl(`/apps/aiquila/api/${action}`), {
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

    console.log('[AIquila] File action registered successfully');

    } catch (error) {
        console.error('[AIquila] Error registering file action:', error);
    }
})();
