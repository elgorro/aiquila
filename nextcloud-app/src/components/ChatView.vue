<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="chat-view">
		<div v-if="conversation.projectId && projectName" class="project-badge">
			<span class="badge-icon">📦</span>
			<span class="badge-label">{{ projectName }}</span>
			<button class="badge-remove" :title="t('aiquila', 'Detach project')" @click="detachProject">✕</button>
		</div>
		<div ref="messagesContainer" class="chat-messages">
			<div v-if="conversation.messages.length === 0" class="empty-state">
				<pre class="ascii-art">    ╭─────────╮
    │  ☁  ☁  │
    │ AIquila │
    │  {{ dotPattern }}  │
    ╰─────────╯</pre>
				<p class="empty-hint">{{ t('aiquila', 'Send a message to start the conversation') }}</p>
			</div>
			<MessageBubble v-for="msg in conversation.messages"
				:key="msg.id"
				:message="msg"
				:verbose="verbose"
				:model="conversation.model" />
			<MessageBubble v-if="draft.userMessage"
				:key="'draft-user'"
				:message="draft.userMessage"
				:verbose="verbose"
				:model="conversation.model" />
			<div v-if="draft.active" class="message-bubble message-assistant">
				<div class="message-header"><strong>{{ t('aiquila', 'Claude') }}</strong></div>
				<div v-if="draft.toolEvents.length > 0" class="draft-tool-events">
					<div v-for="(ev, i) in draft.toolEvents" :key="i" class="draft-tool-line">
						<span v-if="ev.kind === 'use'">↪ {{ t('aiquila', 'Using tool: {name}', { name: ev.name }) }}</span>
						<span v-else>← {{ t('aiquila', 'Tool result received') }}</span>
					</div>
				</div>
				<div v-if="draft.assistantText" class="message-content" style="white-space: pre-wrap;">{{ draft.assistantText }}</div>
				<div v-else class="chat-loading-inline">
					<NcLoadingIcon :size="20" />
					<span>{{ t('aiquila', 'Thinking…') }}</span>
				</div>
				<div v-if="draft.error" class="draft-error">{{ draft.error }}</div>
			</div>
			<div v-if="sending && !draft.active" class="chat-loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('aiquila', 'Thinking…') }}</span>
			</div>
		</div>
		<div v-if="verbose && conversationTokens.total > 0" class="token-summary">
			{{ t('aiquila', 'Total: {total} tokens ({input} in / {output} out)', conversationTokens) }}
		</div>
		<div v-if="showSearch" class="search-bar">
			<input v-model="searchQuery"
				:placeholder="t('aiquila', 'Search messages…')"
				class="search-input"
				@keydown.escape="showSearch = false" />
			<button class="search-close" @click="showSearch = false">✕</button>
		</div>
		<div v-if="showProjectPicker" class="project-picker">
			<NcSelect ref="projectSelect"
				:options="projectOptions"
				:placeholder="t('aiquila', 'Select a project…')"
				label="label"
				:reduce="o => o.value"
				:autoscroll="false"
				@update:model-value="attachProject($event)" />
			<button class="picker-close" @click="showProjectPicker = false">✕</button>
		</div>
		<ChatInput :disabled="sending"
			@send="onSend"
			@command="onCommand" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import MessageBubble from './MessageBubble.vue'
import ChatInput from './ChatInput.vue'
import { sendMessage, sendMessageStream, updateConversation } from '../api.js'

const DOT_PATTERNS = ['·····', '··●··', '·●●●·', '●●●●●', '·●●●·', '··●··']

export default {
	name: 'ChatView',
	components: {
		NcLoadingIcon,
		NcSelect,
		MessageBubble,
		ChatInput,
	},
	props: {
		conversation: {
			type: Object,
			required: true,
		},
		projects: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['conversation-updated', 'message-sent'],
	data() {
		return {
			sending: false,
			verbose: false,
			dotPattern: DOT_PATTERNS[0],
			dotIndex: 0,
			dotTimer: null,
			showSearch: false,
			searchQuery: '',
			showProjectPicker: false,
			draft: { active: false, userMessage: null, assistantText: '', toolEvents: [], error: null },
		}
	},
	watch: {
		'conversation.messages': {
			handler() {
				this.$nextTick(() => this.scrollToBottom())
			},
			deep: true,
		},
		'conversation.id'() {
			// Reset verbose on conversation switch (keep user preference via settings default)
			this.showSearch = false
			this.searchQuery = ''
			this.showProjectPicker = false
		},
		showProjectPicker(val) {
			if (val) {
				this.$nextTick(() => {
					const input = this.$refs.projectSelect?.$el?.querySelector('input')
					if (input) {
						input.focus()
						input.click()
					}
				})
			}
		},
	},
	computed: {
		conversationTokens() {
			let input = 0
			let output = 0
			for (const msg of this.conversation.messages) {
				if (msg.role === 'assistant') {
					input += msg.inputTokens || 0
					output += msg.outputTokens || 0
				}
			}
			return { total: input + output, input, output }
		},
		projectName() {
			if (!this.conversation.projectId) return null
			const project = this.projects.find(p => p.id === this.conversation.projectId)
			return project?.title || null
		},
		projectOptions() {
			return this.projects.map(p => ({
				value: p.id,
				label: p.title,
			}))
		},
	},
	mounted() {
		this.dotTimer = setInterval(() => {
			this.dotIndex = (this.dotIndex + 1) % DOT_PATTERNS.length
			this.dotPattern = DOT_PATTERNS[this.dotIndex]
		}, 400)
	},
	beforeUnmount() {
		if (this.dotTimer) {
			clearInterval(this.dotTimer)
			this.dotTimer = null
		}
	},
	methods: {
		t,
		scrollToBottom() {
			const container = this.$refs.messagesContainer
			if (container) {
				container.scrollTop = container.scrollHeight
			}
		},
		resetDraft() {
			this.draft = { active: false, userMessage: null, assistantText: '', toolEvents: [], error: null }
		},
		async onSend({ prompt, files }) {
			this.sending = true
			const filePaths = files.map(f => f.path)
			let userMessage = null
			let assistantMessage = null
			let conversationUpdate = null

			this.draft = { active: true, userMessage: null, assistantText: '', toolEvents: [], error: null }

			try {
				await sendMessageStream(this.conversation.id, prompt, filePaths, (event) => {
					switch (event.type) {
					case 'user_message':
						this.draft.userMessage = event.userMessage
						userMessage = event.userMessage
						break
					case 'text_delta':
						this.draft.assistantText += event.text || ''
						break
					case 'tool_use':
						this.draft.toolEvents.push({ kind: 'use', name: event.name })
						break
					case 'tool_result':
						this.draft.toolEvents.push({ kind: 'result', toolUseId: event.tool_use_id })
						break
					case 'error':
						this.draft.error = event.error || 'Stream error'
						break
					case 'persisted':
						assistantMessage = event.assistantMessage
						conversationUpdate = event.conversation
						break
					}
				})

				if (userMessage && assistantMessage) {
					this.$emit('message-sent', { userMessage, assistantMessage, conversation: conversationUpdate })
					if (conversationUpdate) {
						this.$emit('conversation-updated', conversationUpdate)
					}
				} else {
					// Stream returned without a persisted assistant message — fall back.
					console.warn('aiquila: stream completed without persisted message; falling back')
					await this.sendNonStreaming(prompt, filePaths)
				}
			} catch (err) {
				// Initial fetch / parse failure → fall back to non-streaming so the
				// user still gets a reply rather than a silent dead end.
				console.error('aiquila: streaming failed, falling back to non-streaming', err)
				try {
					await this.sendNonStreaming(prompt, filePaths)
				} catch (err2) {
					console.error('aiquila: non-streaming fallback also failed', err2)
				}
			} finally {
				this.resetDraft()
				this.sending = false
			}
		},
		async sendNonStreaming(prompt, filePaths) {
			const { data } = await sendMessage(this.conversation.id, prompt, filePaths)
			this.$emit('message-sent', data)
			this.$emit('conversation-updated', data.conversation)
		},
		onCommand({ type, args, path }) {
			switch (type) {
			case 'toggle-verbose':
				this.verbose = !this.verbose
				break
			case 'search':
				this.showSearch = true
				this.searchQuery = args || ''
				break
			case 'remove-project':
				this.detachProject()
				break
			case 'add-project':
				if (args) {
					const id = parseInt(args, 10)
					if (!isNaN(id)) {
						this.attachProject(id)
						return
					}
				}
				if (this.projects.length === 0) {
					// No projects exist — nothing to pick
					return
				}
				this.showProjectPicker = true
				break
			}
		},
		async attachProject(projectId) {
			try {
				const { data } = await updateConversation(this.conversation.id, { projectId })
				this.$emit('conversation-updated', data)
			} catch (err) {
				console.error('Failed to attach project:', err)
			} finally {
				this.showProjectPicker = false
			}
		},
		async detachProject() {
			try {
				const { data } = await updateConversation(this.conversation.id, { projectId: null })
				this.$emit('conversation-updated', data)
			} catch (err) {
				console.error('Failed to detach project:', err)
			}
		},
	},
}
</script>

<style scoped>
.chat-view {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.project-badge {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 16px;
	background: var(--color-primary-element-light);
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.badge-icon {
	font-size: 14px;
}

.badge-label {
	font-weight: 600;
}

.badge-remove {
	margin-left: auto;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	font-size: 14px;
	padding: 0 4px;
	border-radius: 50%;
}

.badge-remove:hover {
	color: var(--color-error);
}

.chat-messages {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.token-summary {
	padding: 4px 16px;
	font-size: 12px;
	color: var(--color-text-lighter);
	text-align: right;
}

.chat-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px;
	color: var(--color-text-lighter);
}

.chat-loading-inline {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 0;
	color: var(--color-text-lighter);
	font-size: 12px;
}

.draft-tool-events {
	margin: 4px 0 6px 0;
	font-size: 12px;
	color: var(--color-text-lighter);
	font-style: italic;
}

.draft-tool-line {
	padding: 1px 0;
}

.draft-error {
	margin-top: 6px;
	padding: 6px 8px;
	background: var(--color-warning-text, rgba(255, 0, 0, 0.05));
	border-left: 3px solid var(--color-error);
	font-size: 12px;
	color: var(--color-error);
}

/* Empty state with ASCII art */
.empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	opacity: 0.7;
}

.ascii-art {
	font-family: 'Menlo', 'Consolas', 'Liberation Mono', monospace;
	font-size: 18px;
	text-align: center;
	color: var(--color-text-lighter);
	line-height: 1.4;
	user-select: none;
	margin: 0;
}

.empty-hint {
	margin-top: 16px;
	color: var(--color-text-lighter);
	font-size: 15px;
}

/* Search bar */
.search-bar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.search-input {
	flex: 1;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.search-close {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	font-size: 16px;
	padding: 4px;
}

.search-close:hover {
	color: var(--color-error);
}

.project-picker {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.project-picker :deep(.v-select) {
	flex: 1;
}

.picker-close {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	font-size: 16px;
	padding: 4px;
}

.picker-close:hover {
	color: var(--color-error);
}
</style>
