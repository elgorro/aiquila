<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal size="large"
		:name="t('aiquila', 'Ask Claude about {filename}', { filename: file.basename })"
		@close="onClose">
		<div class="aiquila-modal-content">
			<NcNoteCard v-if="isLargeFile" type="warning">
				{{ t('aiquila', 'This file is {size} MB. Processing large files may take longer or fail.', { size: fileSizeMB }) }}
			</NcNoteCard>

			<div v-if="isImage && messages.length === 0" class="aiquila-image-preview">
				<img v-if="previewUrl"
					:src="previewUrl"
					:alt="file.basename"
					class="preview-image" />
				<NcLoadingIcon v-else :size="32" />
			</div>

			<!-- The conversation so far. The file rides on the first message and
				stays in context for every follow-up. -->
			<div v-if="messages.length > 0 || draft !== null" ref="thread" class="aiquila-thread">
				<MessageBubble v-for="msg in messages" :key="msg.id" :message="msg" />
				<MessageBubble v-if="draft !== null"
					key="draft"
					:message="{ id: 'draft', role: 'assistant', content: draft }" />
				<div v-if="loading && !draft" class="aiquila-loading">
					<NcLoadingIcon :size="20" />
					<span>{{ t('aiquila', 'Working…') }}</span>
				</div>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField v-model="prompt"
				:label="messages.length === 0 ? t('aiquila', 'What would you like to know?') : t('aiquila', 'Ask a follow-up')"
				:placeholder="isImage ? t('aiquila', 'Ask a question about this image…') : t('aiquila', 'Ask a question about this file…')"
				:disabled="loading"
				class="aiquila-prompt-input"
				@keydown.enter.exact.prevent="ask(prompt)" />

			<div class="aiquila-actions">
				<NcButton v-if="messages.length === 0"
					:disabled="loading"
					@click="ask(isImage ? t('aiquila', 'Describe this image in detail.') : t('aiquila', 'Summarize this document.'))">
					<template #icon>
						<ImageIcon v-if="isImage" :size="20" />
						<FileDocumentIcon v-else :size="20" />
					</template>
					{{ isImage ? t('aiquila', 'Describe') : t('aiquila', 'Summarize') }}
				</NcButton>
				<NcButton v-if="conversationId !== null"
					:href="conversationUrl"
					target="_blank">
					<template #icon>
						<OpenInNewIcon :size="20" />
					</template>
					{{ t('aiquila', 'Open in AIquila') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="!prompt.trim() || loading"
					@click="ask(prompt)">
					<template #icon>
						<SendIcon :size="20" />
					</template>
					{{ t('aiquila', 'Ask') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'

import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

import MessageBubble from './MessageBubble.vue'
import {
	createConversation,
	getFilePreview,
	isImageMime,
	sendMessage,
	sendMessageStream,
} from '../api.js'

export default {
	name: 'AskClaudeModal',

	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		FileDocumentIcon,
		ImageIcon,
		OpenInNewIcon,
		SendIcon,
		MessageBubble,
	},

	props: {
		/** A @nextcloud/files Node. */
		file: {
			type: Object,
			required: true,
		},
		onClose: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			conversationId: null,
			messages: [],
			/** Assistant text streamed so far, or null when no turn is in flight. */
			draft: null,
			prompt: '',
			error: '',
			loading: false,
			previewUrl: null,
		}
	},

	computed: {
		fileSizeMB() {
			return ((this.file.size || 0) / (1024 * 1024)).toFixed(1)
		},
		isLargeFile() {
			return (this.file.size || 0) > 5 * 1024 * 1024
		},
		isImage() {
			return isImageMime(this.file.mime)
		},
		conversationUrl() {
			return generateUrl('/apps/aiquila/') + '#/chat/' + this.conversationId
		},
	},

	mounted() {
		if (this.isImage) {
			this.loadPreview()
		}
	},

	methods: {
		t,

		async loadPreview() {
			try {
				const { data } = await getFilePreview(this.file.path, 400, 400)
				this.previewUrl = 'data:' + data.mimeType + ';base64,' + data.content
			} catch {
				// Preview not available
			}
		},

		async ask(text) {
			const prompt = text.trim()
			if (!prompt || this.loading) return

			this.loading = true
			this.error = ''
			this.prompt = ''
			try {
				if (this.conversationId === null) {
					const { data } = await createConversation()
					this.conversationId = data.id
				}
				// Only the first turn carries the file; the server re-attaches it
				// to that message on every later turn.
				const files = this.messages.length === 0 ? [this.file.path] : []
				await this.send(prompt, files)
			} catch (err) {
				this.prompt = prompt
				this.error = err.response?.data?.error
					|| err.message
					|| t('aiquila', 'Failed to communicate with Claude')
			} finally {
				this.draft = null
				this.loading = false
			}
		},

		/**
		 * Stream the turn; fall back to the single-response endpoint when the
		 * stream cannot be opened (e.g. a proxy buffering text/event-stream).
		 */
		async send(prompt, files) {
			let userMessage = null
			let assistantMessage = null
			try {
				this.draft = ''
				await sendMessageStream(this.conversationId, prompt, files, (event) => {
					switch (event.type) {
					case 'user_message':
						userMessage = event.userMessage
						this.messages.push(userMessage)
						this.scrollToEnd()
						break
					case 'text_delta':
						this.draft += event.text || ''
						this.scrollToEnd()
						break
					case 'persisted':
						assistantMessage = event.assistantMessage
						break
					}
				})
			} catch (err) {
				if (userMessage) throw err
				console.warn('[AIquila] stream unavailable, falling back', err)
			}

			if (!userMessage) {
				this.draft = null
				const { data } = await sendMessage(this.conversationId, prompt, files)
				this.messages.push(data.userMessage)
				assistantMessage = data.assistantMessage
			}
			if (assistantMessage) {
				this.messages.push(assistantMessage)
				this.scrollToEnd()
			}
		},

		scrollToEnd() {
			this.$nextTick(() => {
				const el = this.$refs.thread
				if (el) el.scrollTop = el.scrollHeight
			})
		},
	},
}
</script>

<style scoped>
.aiquila-modal-content {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.aiquila-image-preview {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 100px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.preview-image {
	max-width: 100%;
	max-height: 300px;
	border-radius: var(--border-radius);
	object-fit: contain;
}

.aiquila-thread {
	max-height: 55vh;
	overflow-y: auto;
}

.aiquila-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	opacity: 0.8;
}

.aiquila-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
