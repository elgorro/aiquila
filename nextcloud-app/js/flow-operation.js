/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Settings editor for the "Process with AIquila" Flow operation.
 *
 * Plain JavaScript on purpose: Flow loads it as-is, and a custom element works
 * on every Nextcloud the app supports. The element receives the rule's stored
 * value as `model-value` and reports changes as an `update:model-value` event
 * whose detail is `[value]`, which is the contract of OCA.WorkflowEngine.
 * The value is JSON: {"action": "auto|summarize|describe|ask", "prompt": "…"}.
 */
(function() {
	const TAG = 'oca-aiquila-flow-operation'
	const t = (text) => (window.t ? window.t('aiquila', text) : text)

	function parse(value) {
		try {
			const data = JSON.parse(value || '{}')
			return { action: data.action || 'auto', prompt: data.prompt || '' }
		} catch (e) {
			return { action: 'auto', prompt: '' }
		}
	}

	class AIquilaFlowOperation extends HTMLElement {
		static get observedAttributes() {
			return ['model-value']
		}

		constructor() {
			super()
			this.state = parse('')
			this.select = document.createElement('select')
			this.select.setAttribute('aria-label', t('Action'))
			for (const [value, label] of [
				['auto', t('Summarize text and PDFs, describe images')],
				['summarize', t('Summarize')],
				['describe', t('Describe')],
				['ask', t('Ask a question')],
			]) {
				const option = document.createElement('option')
				option.value = value
				option.textContent = label
				this.select.appendChild(option)
			}
			this.prompt = document.createElement('textarea')
			this.prompt.placeholder = t('What should AIquila do with the file?')
			this.prompt.maxLength = 4000
			this.prompt.rows = 3
			this.prompt.style.width = '100%'
			this.select.style.width = '100%'
			this.select.addEventListener('change', () => this.emit())
			this.prompt.addEventListener('change', () => this.emit())
		}

		connectedCallback() {
			if (!this.select.isConnected) {
				this.append(this.select, this.prompt)
			}
			this.render()
		}

		attributeChangedCallback(name, oldValue, newValue) {
			this.modelValue = newValue
		}

		set modelValue(value) {
			this.state = parse(value)
			this.render()
		}

		get modelValue() {
			return JSON.stringify(this.state)
		}

		render() {
			this.select.value = this.state.action
			this.prompt.value = this.state.prompt
			this.prompt.hidden = this.state.action !== 'ask'
		}

		emit() {
			this.state = {
				action: this.select.value,
				prompt: this.select.value === 'ask' ? this.prompt.value.trim() : '',
			}
			this.render()
			this.dispatchEvent(new CustomEvent('update:model-value', { detail: [this.modelValue] }))
		}
	}

	if (!window.customElements.get(TAG)) {
		window.customElements.define(TAG, AIquilaFlowOperation)
	}

	window.OCA.WorkflowEngine.registerOperator({
		id: 'OCA\\AIquila\\Flow\\AIquilaOperation',
		color: '#0082c9',
		operation: '',
		element: TAG,
	})
})()
