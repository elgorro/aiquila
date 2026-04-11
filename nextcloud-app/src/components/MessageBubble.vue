<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div :class="['message-bubble', `message-${message.role}`]">
		<div class="message-header">
			<strong>{{ roleLabel }}</strong>
		</div>
		<NcNoteCard v-if="isError" type="error">
			{{ messageContent }}
		</NcNoteCard>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-else-if="renderedContent" class="message-content markdown-body" v-html="renderedContent" />
		<div v-else class="message-content">
			{{ messageContent }}
		</div>
		<div v-if="message.files && message.files.length > 0" class="message-files">
			<!-- Image previews -->
			<div v-if="imageFiles.length > 0" class="message-images">
				<div v-for="file in imageFiles"
					:key="file.id"
					class="image-preview">
					<img v-if="previews[file.filePath]"
						:src="previews[file.filePath]"
						:alt="file.fileName" />
					<NcLoadingIcon v-else :size="20" />
					<span class="image-name" :title="file.filePath">{{ file.fileName }}</span>
				</div>
			</div>
			<!-- Non-image file chips -->
			<span v-for="file in nonImageFiles"
				:key="file.id"
				class="file-chip">
				{{ file.fileName }}
			</span>
		</div>
		<div v-if="hasTokens && !verbose" class="message-tokens">
			<NcProgressBar :value="tokenPercent" size="small" />
			<span class="token-label">{{ totalTokens }} tokens ({{ message.inputTokens }} in / {{ message.outputTokens }} out)</span>
		</div>
		<div v-if="verbose && message.role === 'assistant'" class="verbose-info">
			<div class="verbose-row">
				<span class="verbose-label">Model:</span>
				<span class="verbose-value">{{ model || '—' }}</span>
			</div>
			<div class="verbose-row">
				<span class="verbose-label">Input:</span>
				<span class="verbose-value">{{ formatNum(message.inputTokens) }} tokens</span>
			</div>
			<div class="verbose-row">
				<span class="verbose-label">Output:</span>
				<span class="verbose-value">{{ formatNum(message.outputTokens) }} tokens</span>
			</div>
			<div v-if="message.cacheCreationTokens" class="verbose-row">
				<span class="verbose-label">Cache create:</span>
				<span class="verbose-value">{{ formatNum(message.cacheCreationTokens) }}</span>
			</div>
			<div v-if="message.cacheReadTokens" class="verbose-row">
				<span class="verbose-label">Cache read:</span>
				<span class="verbose-value">{{ formatNum(message.cacheReadTokens) }}</span>
			</div>
			<div v-if="message.latencyMs" class="verbose-row">
				<span class="verbose-label">Latency:</span>
				<span class="verbose-value">{{ (message.latencyMs / 1000).toFixed(1) }}s</span>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { isImageMime, getFilePreview } from '../api.js'
import { renderMarkdown, attachCopyHandlers } from '../utils/markdown.js'
import '../styles/markdown.css'

export default {
	name: 'MessageBubble',
	components: {
		NcNoteCard,
		NcProgressBar,
		NcLoadingIcon,
	},
	props: {
		message: {
			type: Object,
			required: true,
		},
		verbose: {
			type: Boolean,
			default: false,
		},
		model: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			previews: {},
		}
	},
	computed: {
		roleLabel() {
			if (this.message.role === 'user') return t('aiquila', 'You')
			if (this.message.role === 'assistant') return t('aiquila', 'Claude')
			return t('aiquila', 'Error')
		},
		isError() {
			return this.message.role === 'assistant'
				&& typeof this.message.content === 'string'
				&& this.message.content.startsWith('Error: ')
		},
		messageContent() {
			return this.message.content || ''
		},
		renderedContent() {
			if (this.message.role === 'assistant' && !this.isError && this.messageContent) {
				return renderMarkdown(this.messageContent)
			}
			return null
		},
		hasTokens() {
			return this.message.role === 'assistant'
				&& (this.message.inputTokens != null || this.message.outputTokens != null)
		},
		totalTokens() {
			return (this.message.inputTokens || 0) + (this.message.outputTokens || 0)
		},
		tokenPercent() {
			if (this.totalTokens === 0) return 0
			return Math.round(((this.message.outputTokens || 0) / this.totalTokens) * 100)
		},
		imageFiles() {
			if (!this.message.files) return []
			return this.message.files.filter(f => isImageMime(f.mimeType))
		},
		nonImageFiles() {
			if (!this.message.files) return []
			return this.message.files.filter(f => !isImageMime(f.mimeType))
		},
	},
	mounted() {
		this.loadPreviews()
		this.$nextTick(() => attachCopyHandlers(this.$el))
	},
	updated() {
		this.$nextTick(() => attachCopyHandlers(this.$el))
	},
	methods: {
		t,
		async loadPreviews() {
			for (const file of this.imageFiles) {
				try {
					const { data } = await getFilePreview(file.filePath, 200, 200)
					this.previews[file.filePath] = 'data:' + data.mimeType + ';base64,' + data.content
				} catch {
					// Preview not available
				}
			}
		},
		formatNum(n) {
			if (n == null) return '—'
			return n.toLocaleString()
		},
	},
}
</script>

<style scoped>
.message-bubble {
	margin-bottom: 12px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	max-width: 85%;
}

.message-user {
	background: var(--color-primary-element-light);
	margin-left: auto;
}

.message-assistant {
	background: var(--color-background-dark);
	margin-right: auto;
}

.message-header {
	margin-bottom: 4px;
	font-size: 13px;
	opacity: 0.8;
}

.message-content {
	white-space: pre-wrap;
	word-wrap: break-word;
}

.message-content.markdown-body {
	white-space: normal;
}

.message-files {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;
}

.message-images {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	width: 100%;
}

.image-preview {
	width: 120px;
	border-radius: var(--border-radius);
	overflow: hidden;
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.image-preview img {
	width: 100%;
	height: 80px;
	object-fit: cover;
	display: block;
}

.image-name {
	display: block;
	padding: 4px;
	font-size: 11px;
	text-overflow: ellipsis;
	overflow: hidden;
	white-space: nowrap;
}

.file-chip {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	font-size: 12px;
}

.message-tokens {
	margin-top: 8px;
}

.token-label {
	display: block;
	margin-top: 2px;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.verbose-info {
	margin-top: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	font-size: 12px;
}

.verbose-row {
	display: flex;
	gap: 8px;
	padding: 1px 0;
}

.verbose-label {
	color: var(--color-text-lighter);
	min-width: 90px;
}

.verbose-value {
	font-family: 'Menlo', 'Consolas', monospace;
}
</style>
