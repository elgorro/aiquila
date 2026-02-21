/**
 * AIquila Main App Interface
 * Simple interface for interacting with Claude AI
 */

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

function main() {
	// Get configuration from initial state
	const config = loadState('aiquila', 'config')

	// Get the main container
	const container = document.querySelector('#content')

	if (!config.has_api_key) {
		showNoApiKeyMessage(container)
		return
	}

	createChatInterface(container, config)
}

function showNoApiKeyMessage(container) {
	container.innerHTML = `
		<div style="display: flex; align-items: center; justify-content: center; height: 100%; padding: 40px;">
			<div style="text-align: center; max-width: 500px;">
				<h2>⚠️ No API Key Configured</h2>
				<p>Please configure your Anthropic API key in the admin settings to use AIquila.</p>
				<a href="${generateUrl('/settings/admin/aiquila')}" class="button primary" style="margin-top: 20px;">
					Go to Admin Settings
				</a>
			</div>
		</div>
	`
}

function createChatInterface(container, config) {
	container.innerHTML = `
		<div style="max-width: 1200px; margin: 0 auto; padding: 20px; height: 100%; display: flex; flex-direction: column;">
			<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
				<h2 style="margin: 0;">Ask Claude</h2>
				<button id="settings-toggle-btn" class="secondary" style="font-size: 14px;">⚙️ Settings</button>
			</div>

			<div id="settings-panel" style="display: none; border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 16px; margin-bottom: 20px; background: var(--color-background-dark);">
				<h3 style="margin: 0 0 12px 0; font-size: 15px;">Personal Settings</h3>

				<div style="margin-bottom: 12px;">
					<label for="settings-model" style="display: block; margin-bottom: 4px; font-size: 13px;">Model preference</label>
					<select id="settings-model" style="width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-main-background);">
						<option value="">(admin default)</option>
					</select>
				</div>

				<div style="margin-bottom: 12px;">
					<label for="settings-api-key" style="display: block; margin-bottom: 4px; font-size: 13px;">Personal API key <span style="opacity: 0.6;">(overrides admin key)</span></label>
					<div style="display: flex; gap: 8px;">
						<input type="password" id="settings-api-key" placeholder="sk-ant-… (leave blank to keep current)" style="flex: 1; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-main-background);">
						<button id="settings-clear-key-btn" class="secondary" style="white-space: nowrap;">Clear key</button>
					</div>
				</div>

				<div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px;">
					<button id="settings-cancel-btn" class="secondary">Cancel</button>
					<button id="settings-save-btn" class="primary">Save</button>
				</div>
				<p id="settings-status" style="margin: 8px 0 0 0; font-size: 13px; min-height: 18px;"></p>
			</div>

			<div id="chat-history" style="flex: 1; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 15px; background: var(--color-main-background); min-height: 400px; margin-bottom: 20px;">
				<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--color-text-lighter);">
					<p>Start a conversation with Claude...</p>
				</div>
			</div>

			<div>
				<textarea id="prompt-input" placeholder="Ask Claude anything..." rows="3" style="width: 100%; padding: 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius); font-family: var(--font-face); resize: vertical; font-size: 14px;"></textarea>
				<div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
					<button id="clear-btn" class="secondary">Clear</button>
					<button id="ask-btn" class="primary">Ask Claude</button>
				</div>
			</div>
		</div>
	`

	const historyDiv = document.getElementById('chat-history')
	const input = document.getElementById('prompt-input')
	const askBtn = document.getElementById('ask-btn')
	const clearBtn = document.getElementById('clear-btn')

	// ── Settings panel wiring ──────────────────────────────────────────────
	const settingsPanel = document.getElementById('settings-panel')
	const settingsToggleBtn = document.getElementById('settings-toggle-btn')
	const settingsSaveBtn = document.getElementById('settings-save-btn')
	const settingsCancelBtn = document.getElementById('settings-cancel-btn')
	const settingsClearKeyBtn = document.getElementById('settings-clear-key-btn')
	const settingsModelSelect = document.getElementById('settings-model')
	const settingsApiKeyInput = document.getElementById('settings-api-key')
	const settingsStatus = document.getElementById('settings-status')

	settingsToggleBtn.addEventListener('click', async () => {
		if (settingsPanel.style.display === 'none') {
			settingsPanel.style.display = 'block'
			settingsToggleBtn.textContent = '✕ Close Settings'
			settingsStatus.textContent = ''
			await loadSettingsPanel()
		} else {
			closeSettingsPanel()
		}
	})

	settingsCancelBtn.addEventListener('click', () => closeSettingsPanel())

	settingsClearKeyBtn.addEventListener('click', () => {
		settingsApiKeyInput.value = ''
		settingsApiKeyInput.dataset.clear = 'true'
		settingsApiKeyInput.placeholder = 'Key will be cleared on save'
	})

	settingsSaveBtn.addEventListener('click', async () => {
		await saveSettings()
	})

	function closeSettingsPanel() {
		settingsPanel.style.display = 'none'
		settingsToggleBtn.textContent = '⚙️ Settings'
		settingsApiKeyInput.dataset.clear = ''
	}

	async function loadSettingsPanel() {
		settingsStatus.textContent = 'Loading…'
		try {
			const url = generateUrl('/apps/aiquila/api/settings')
			const res = await axios.get(url)
			const data = res.data

			// Populate model select
			settingsModelSelect.innerHTML = '<option value="">(admin default)</option>'
			for (const model of (data.availableModels || [])) {
				const opt = document.createElement('option')
				opt.value = model
				opt.textContent = model
				if (model === data.userModel) opt.selected = true
				settingsModelSelect.appendChild(opt)
			}

			settingsApiKeyInput.placeholder = data.hasUserKey
				? 'Personal key configured — enter new key to replace'
				: 'sk-ant-… (leave blank to keep admin key)'
			settingsApiKeyInput.value = ''
			settingsApiKeyInput.dataset.clear = ''
			settingsStatus.textContent = ''
		} catch (err) {
			settingsStatus.textContent = 'Failed to load settings: ' + err.message
			settingsStatus.style.color = 'var(--color-error)'
		}
	}

	async function saveSettings() {
		settingsSaveBtn.disabled = true
		settingsStatus.textContent = 'Saving…'
		settingsStatus.style.color = ''

		const payload = {
			model: settingsModelSelect.value,
			api_key: settingsApiKeyInput.dataset.clear === 'true' ? '' : settingsApiKeyInput.value,
		}

		try {
			const url = generateUrl('/apps/aiquila/api/settings')
			await axios.post(url, payload)
			settingsStatus.textContent = 'Saved!'
			settingsStatus.style.color = 'var(--color-success)'
			settingsApiKeyInput.value = ''
			settingsApiKeyInput.dataset.clear = ''
		} catch (err) {
			settingsStatus.textContent = 'Error saving: ' + err.message
			settingsStatus.style.color = 'var(--color-error)'
		} finally {
			settingsSaveBtn.disabled = false
		}
	}

	// ── Chat wiring ────────────────────────────────────────────────────────
	askBtn.addEventListener('click', () => sendMessage(input.value, historyDiv, input, askBtn))
	clearBtn.addEventListener('click', () => {
		input.value = ''
		historyDiv.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--color-text-lighter);"><p>Start a conversation with Claude...</p></div>'
	})

	input.addEventListener('keydown', (e) => {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault()
			sendMessage(input.value, historyDiv, input, askBtn)
		}
	})
}

async function sendMessage(prompt, historyDiv, input, button) {
	if (!prompt.trim()) return

	// Clear empty state if present
	if (historyDiv.querySelector('p')) {
		historyDiv.innerHTML = ''
	}

	input.disabled = true
	button.disabled = true

	// Add user message
	addMessageToHistory(historyDiv, 'user', prompt)

	// Show loading
	const loadingDiv = addMessageToHistory(historyDiv, 'assistant', '⏳ Thinking...')

	try {
		const url = generateUrl('/apps/aiquila/api/ask')
		const response = await axios.post(url, {
			prompt: prompt,
			context: ''
		})

		loadingDiv.remove()

		if (response.data.error) {
			addMessageToHistory(historyDiv, 'error', response.data.error)
			console.error('Claude error:', response.data.error)
		} else {
			addMessageToHistory(historyDiv, 'assistant', response.data.response)
			console.log('Response received from Claude')
		}

		input.value = ''
	} catch (error) {
		loadingDiv.remove()
		addMessageToHistory(historyDiv, 'error', 'Failed to communicate with Claude: ' + error.message)
		console.error('Request failed:', error)
	} finally {
		input.disabled = false
		button.disabled = false
		input.focus()
	}
}

function addMessageToHistory(historyDiv, role, content) {
	const messageDiv = document.createElement('div')
	messageDiv.style.cssText = `
		margin-bottom: 15px;
		padding: 12px;
		border-radius: var(--border-radius);
		${role === 'user' ? 'background: var(--color-primary-element-light); margin-left: 20%;' : ''}
		${role === 'assistant' ? 'background: var(--color-background-dark); margin-right: 20%;' : ''}
		${role === 'error' ? 'background: var(--color-error); color: white;' : ''}
	`

	const roleLabel = role === 'user' ? 'You' : role === 'assistant' ? 'Claude' : 'Error'
	messageDiv.innerHTML = `
		<div style="margin-bottom: 5px; font-size: 13px; opacity: 0.8;">
			<strong>${roleLabel}</strong>
		</div>
		<div style="white-space: pre-wrap; word-wrap: break-word;">
			${content}
		</div>
	`

	historyDiv.appendChild(messageDiv)
	historyDiv.scrollTop = historyDiv.scrollHeight

	return messageDiv
}

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', () => {
	main()
})
