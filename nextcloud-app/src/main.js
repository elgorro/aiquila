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
				<a href="${generateUrl('/settings/admin/aiquila')}" style="font-size: 14px; color: var(--color-primary-element);">
					⚙️ Settings
				</a>
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
