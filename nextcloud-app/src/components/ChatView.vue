<template>
	<div class="chat-view">
		<div ref="messagesContainer" class="chat-messages">
			<NcEmptyContent v-if="conversation.messages.length === 0"
				:name="t('aiquila', 'Start chatting')">
				<template #icon>
					<ChatIcon :size="48" />
				</template>
				{{ t('aiquila', 'Send a message to start the conversation') }}
			</NcEmptyContent>
			<MessageBubble v-for="msg in conversation.messages"
				:key="msg.id"
				:message="msg" />
			<div v-if="sending" class="chat-loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('aiquila', 'Thinking…') }}</span>
			</div>
		</div>
		<div v-if="conversationTokens.total > 0" class="token-summary">
			{{ t('aiquila', 'Total: {total} tokens ({input} in / {output} out)', conversationTokens) }}
		</div>
		<ChatInput :disabled="sending" @send="onSend" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChatIcon from 'vue-material-design-icons/Chat.vue'

import MessageBubble from './MessageBubble.vue'
import ChatInput from './ChatInput.vue'
import { sendMessage } from '../api.js'

export default {
	name: 'ChatView',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		ChatIcon,
		MessageBubble,
		ChatInput,
	},
	props: {
		conversation: {
			type: Object,
			required: true,
		},
	},
	emits: ['conversation-updated', 'message-sent'],
	data() {
		return {
			sending: false,
		}
	},
	watch: {
		'conversation.messages': {
			handler() {
				this.$nextTick(() => this.scrollToBottom())
			},
			deep: true,
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
	},
	methods: {
		t,
		scrollToBottom() {
			const container = this.$refs.messagesContainer
			if (container) {
				container.scrollTop = container.scrollHeight
			}
		},
		async onSend({ prompt, files }) {
			this.sending = true
			try {
				const filePaths = files.map(f => f.path)
				const { data } = await sendMessage(this.conversation.id, prompt, filePaths)
				this.$emit('message-sent', data)
				this.$emit('conversation-updated', data.conversation)
			} catch (err) {
				console.error('Failed to send message:', err)
			} finally {
				this.sending = false
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
</style>
