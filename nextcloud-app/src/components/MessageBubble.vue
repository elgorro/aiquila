<template>
	<div :class="['message-bubble', `message-${message.role}`]">
		<div class="message-header">
			<strong>{{ roleLabel }}</strong>
		</div>
		<NcNoteCard v-if="isError" type="error">
			{{ messageContent }}
		</NcNoteCard>
		<div v-else class="message-content">
			{{ messageContent }}
		</div>
		<div v-if="message.files && message.files.length > 0" class="message-files">
			<span v-for="file in message.files"
				:key="file.id"
				class="file-chip">
				{{ file.fileName }}
			</span>
		</div>
		<div v-if="hasTokens" class="message-tokens">
			<NcProgressBar :value="tokenPercent" size="small" />
			<span class="token-label">{{ totalTokens }} tokens ({{ message.inputTokens }} in / {{ message.outputTokens }} out)</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

export default {
	name: 'MessageBubble',
	components: {
		NcNoteCard,
		NcProgressBar,
	},
	props: {
		message: {
			type: Object,
			required: true,
		},
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
	},
	methods: {
		t,
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

.message-files {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 8px;
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
</style>
