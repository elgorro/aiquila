/**
 * AIquila Main App Interface
 * Simple interface for interacting with Claude AI
 */

import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'

// ── Attached files state ──────────────────────────────────────────────
let attachedFiles = []

// ── Slash-command registry ────────────────────────────────────────────
const SLASH_COMMANDS = [
	{
		id: 'add-file',
		label: '/add-file',
		icon: '📎',
		description: 'Attach a file from Nextcloud',
		handler: handleAddFile,
		acceptsArgs: true,
	},
]

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

			<div style="position: relative;">
				<div id="slash-menu" class="slash-menu"></div>
				<div id="file-chips" class="file-chips"></div>
				<textarea id="prompt-input" placeholder="Ask Claude anything… Type / for commands" rows="3" style="width: 100%; padding: 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius); font-family: var(--font-face); resize: vertical; font-size: 14px;"></textarea>
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
	const slashMenu = document.getElementById('slash-menu')
	const fileChips = document.getElementById('file-chips')

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

	// ── Slash-menu logic ──────────────────────────────────────────────────
	let menuActiveIndex = -1

	function showSlashMenu(filter) {
		const matches = SLASH_COMMANDS.filter(cmd =>
			cmd.label.startsWith('/' + filter),
		)
		if (matches.length === 0) {
			hideSlashMenu()
			return
		}

		slashMenu.innerHTML = matches
			.map(
				(cmd, i) => `
			<div class="slash-menu-item${i === 0 ? ' active' : ''}" data-id="${cmd.id}">
				<span class="icon">${cmd.icon}</span>
				<span class="label">${cmd.label}</span>
				<span class="description">${cmd.description}</span>
			</div>
		`,
			)
			.join('')

		menuActiveIndex = 0
		slashMenu.classList.add('visible')

		slashMenu.querySelectorAll('.slash-menu-item').forEach((el) => {
			el.addEventListener('click', () => {
				selectSlashCommand(el.dataset.id)
			})
		})
	}

	function hideSlashMenu() {
		slashMenu.classList.remove('visible')
		slashMenu.innerHTML = ''
		menuActiveIndex = -1
	}

	function selectSlashCommand(id) {
		const cmd = SLASH_COMMANDS.find((c) => c.id === id)
		if (!cmd) return

		hideSlashMenu()

		// Check for inline args: /add-file:/path/to/file
		const text = input.value.trim()
		const colonIdx = text.indexOf(':')
		const args = colonIdx !== -1 ? text.substring(colonIdx + 1).trim() : ''

		input.value = ''
		cmd.handler(args, { input, fileChips })
	}

	input.addEventListener('input', () => {
		const text = input.value
		// Show menu when text starts with / and is a single token (no spaces)
		if (text.startsWith('/') && !text.includes(' ')) {
			// Don't show menu if it looks like a completed command with args (has colon)
			// but DO allow typing /add-file: to trigger on Enter
			const filter = text.substring(1).split(':')[0]
			showSlashMenu(filter)
		} else {
			hideSlashMenu()
		}
	})

	// ── Chat wiring ────────────────────────────────────────────────────────
	askBtn.addEventListener('click', () => sendMessage(input.value, historyDiv, input, askBtn, fileChips))
	clearBtn.addEventListener('click', () => {
		input.value = ''
		attachedFiles = []
		renderFileChips(fileChips)
		historyDiv.innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--color-text-lighter);"><p>Start a conversation with Claude...</p></div>'
	})

	input.addEventListener('keydown', (e) => {
		// Slash-menu keyboard navigation
		if (slashMenu.classList.contains('visible')) {
			const items = slashMenu.querySelectorAll('.slash-menu-item')
			if (e.key === 'ArrowDown') {
				e.preventDefault()
				menuActiveIndex = Math.min(menuActiveIndex + 1, items.length - 1)
				updateMenuActive(items)
				return
			}
			if (e.key === 'ArrowUp') {
				e.preventDefault()
				menuActiveIndex = Math.max(menuActiveIndex - 1, 0)
				updateMenuActive(items)
				return
			}
			if (e.key === 'Enter') {
				e.preventDefault()
				if (menuActiveIndex >= 0 && items[menuActiveIndex]) {
					selectSlashCommand(items[menuActiveIndex].dataset.id)
				}
				return
			}
			if (e.key === 'Escape') {
				e.preventDefault()
				hideSlashMenu()
				input.value = ''
				return
			}
		}

		// Handle /command:args when menu is NOT visible (e.g. typed full command)
		if (e.key === 'Enter' && !e.shiftKey) {
			const text = input.value.trim()
			if (text.startsWith('/')) {
				e.preventDefault()
				const cmdName = text.substring(1).split(':')[0].split(' ')[0]
				const cmd = SLASH_COMMANDS.find((c) => c.id === cmdName)
				if (cmd) {
					const colonIdx = text.indexOf(':')
					const args = colonIdx !== -1 ? text.substring(colonIdx + 1).trim() : ''
					input.value = ''
					hideSlashMenu()
					cmd.handler(args, { input, fileChips })
					return
				}
			}

			e.preventDefault()
			sendMessage(input.value, historyDiv, input, askBtn, fileChips)
		}
	})

	function updateMenuActive(items) {
		items.forEach((el, i) => {
			el.classList.toggle('active', i === menuActiveIndex)
		})
	}
}

// ── /add-file handler ─────────────────────────────────────────────────
async function handleAddFile(args, { input, fileChips }) {
	if (args) {
		// Direct path mode: /add-file:/path/to/file
		try {
			const url = generateUrl('/apps/aiquila/api/files/info')
			const res = await axios.get(url, { params: { path: args } })
			const info = res.data
			addFile({
				path: args,
				name: info.name || args.split('/').pop(),
				size: info.size || 0,
				mime: info.mimeType || 'application/octet-stream',
			}, fileChips)
		} catch {
			addMessageToHistory(
				document.getElementById('chat-history'),
				'error',
				'File not found: ' + args,
			)
		}
	} else {
		// File picker mode
		try {
			const picker = getFilePickerBuilder('Select files')
				.setMultiSelect(true)
				.setType(1) // Choose files
				.allowDirectories(false)
				.build()

			const paths = await picker.pick()

			// pick() returns a string (single) or array (multi)
			const pathList = Array.isArray(paths) ? paths : [paths]
			for (const p of pathList) {
				try {
					const url = generateUrl('/apps/aiquila/api/files/info')
					const res = await axios.get(url, { params: { path: p } })
					const info = res.data
					addFile({
						path: p,
						name: info.name || p.split('/').pop(),
						size: info.size || 0,
						mime: info.mimeType || 'application/octet-stream',
					}, fileChips)
				} catch {
					// If info call fails, still add with basic info
					addFile({
						path: p,
						name: p.split('/').pop(),
						size: 0,
						mime: 'application/octet-stream',
					}, fileChips)
				}
			}
		} catch (err) {
			if (!(err instanceof FilePickerClosed)) {
				console.error('File picker error:', err)
			}
		}
	}

	input.focus()
}

function addFile(file, fileChips) {
	// Avoid duplicates
	if (attachedFiles.some((f) => f.path === file.path)) return
	attachedFiles.push(file)
	renderFileChips(fileChips)
}

function removeFile(path, fileChips) {
	attachedFiles = attachedFiles.filter((f) => f.path !== path)
	renderFileChips(fileChips)
}

function renderFileChips(container) {
	container.innerHTML = attachedFiles
		.map(
			(f) => `
		<div class="file-chip" data-path="${f.path}">
			<span class="name" title="${f.path}">${f.name}</span>
			<span class="size">${humanSize(f.size)}</span>
			<button class="remove" title="Remove">✕</button>
		</div>
	`,
		)
		.join('')

	container.querySelectorAll('.file-chip .remove').forEach((btn) => {
		btn.addEventListener('click', () => {
			const path = btn.closest('.file-chip').dataset.path
			removeFile(path, container)
		})
	})
}

function humanSize(bytes) {
	if (!bytes) return ''
	const units = ['B', 'KB', 'MB', 'GB']
	let i = 0
	let size = bytes
	while (size >= 1024 && i < units.length - 1) {
		size /= 1024
		i++
	}
	return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
}

// ── Send message ──────────────────────────────────────────────────────
async function sendMessage(prompt, historyDiv, input, button, fileChips) {
	if (!prompt.trim() && attachedFiles.length === 0) return

	// Clear empty state if present
	if (historyDiv.querySelector('p')) {
		historyDiv.innerHTML = ''
	}

	input.disabled = true
	button.disabled = true

	// Build user message display with attached file names
	let userDisplay = prompt
	if (attachedFiles.length > 0) {
		const fileNames = attachedFiles.map((f) => f.name).join(', ')
		userDisplay += (prompt.trim() ? '\n' : '') + '📎 ' + fileNames
	}

	// Add user message
	addMessageToHistory(historyDiv, 'user', userDisplay)

	// Show loading
	const loadingDiv = addMessageToHistory(historyDiv, 'assistant', '⏳ Thinking...')

	try {
		const url = generateUrl('/apps/aiquila/api/ask')
		const body = {
			prompt: prompt,
			context: '',
		}
		if (attachedFiles.length > 0) {
			body.files = attachedFiles.map((f) => f.path)
		}
		const response = await axios.post(url, body)

		loadingDiv.remove()

		if (response.data.error) {
			addMessageToHistory(historyDiv, 'error', response.data.error)
			console.error('Claude error:', response.data.error)
		} else {
			addMessageToHistory(historyDiv, 'assistant', response.data.response)
			console.log('Response received from Claude')
		}

		input.value = ''
		attachedFiles = []
		renderFileChips(fileChips)
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
