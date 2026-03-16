<template>
	<NcContent app-name="aiquila">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew :text="t('aiquila', 'New chat')"
					@new="onNewChat" />
				<NcAppNavigationItem v-for="conv in conversations"
					:key="conv.id"
					:name="conv.title || t('aiquila', 'Untitled')"
					:class="{ active: conv.id === activeConversationId }"
					@click="onSelectConversation(conv.id)">
					<template #actions>
						<NcActionButton @click="onDeleteConversation(conv.id)">
							{{ t('aiquila', 'Delete') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<NcAppNavigationSettings :name="t('aiquila', 'Settings')">
					<NavigationSettings />
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<ChatView v-if="activeConversation"
				:conversation="activeConversation"
				@conversation-updated="onConversationUpdated"
				@message-sent="onMessageSent" />
			<NcEmptyContent v-else
				:name="t('aiquila', 'Ask Claude')">
				<template #icon>
					<ChatIcon :size="64" />
				</template>
				<template #action>
					<NcButton type="primary" @click="onNewChat">
						{{ t('aiquila', 'New chat') }}
					</NcButton>
				</template>
				{{ t('aiquila', 'Select a conversation or start a new chat') }}
			</NcEmptyContent>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChatIcon from 'vue-material-design-icons/Chat.vue'

import ChatView from './components/ChatView.vue'
import NavigationSettings from './components/NavigationSettings.vue'
import {
	createConversation,
	getConversation,
	deleteConversation,
} from './api.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationNew,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		NcActionButton,
		NcAppContent,
		NcEmptyContent,
		NcButton,
		ChatIcon,
		ChatView,
		NavigationSettings,
	},
	data() {
		return {
			conversations: loadState('aiquila', 'conversations', []),
			activeConversationId: null,
			activeConversation: null,
			loading: false,
		}
	},
	methods: {
		t,
		async onNewChat() {
			try {
				const { data } = await createConversation()
				this.conversations.unshift(data)
				await this.onSelectConversation(data.id)
			} catch (err) {
				console.error('Failed to create conversation:', err)
			}
		},
		async onSelectConversation(id) {
			if (this.activeConversationId === id) return
			this.activeConversationId = id
			this.loading = true
			try {
				const { data } = await getConversation(id)
				this.activeConversation = data
			} catch (err) {
				console.error('Failed to load conversation:', err)
				this.activeConversation = null
			} finally {
				this.loading = false
			}
		},
		async onDeleteConversation(id) {
			try {
				await deleteConversation(id)
				this.conversations = this.conversations.filter(c => c.id !== id)
				if (this.activeConversationId === id) {
					this.activeConversationId = null
					this.activeConversation = null
				}
			} catch (err) {
				console.error('Failed to delete conversation:', err)
			}
		},
		onConversationUpdated(updatedConv) {
			const idx = this.conversations.findIndex(c => c.id === updatedConv.id)
			if (idx !== -1) {
				this.conversations.splice(idx, 1, {
					...this.conversations[idx],
					...updatedConv,
				})
			}
		},
		onMessageSent({ userMessage, assistantMessage, conversation }) {
			if (this.activeConversation) {
				this.activeConversation.messages.push(userMessage)
				this.activeConversation.messages.push(assistantMessage)
			}
			this.onConversationUpdated(conversation)
		},
	},
}
</script>

<style scoped>
:deep(.app-navigation-entry.active) {
	background-color: var(--color-primary-element-light) !important;
}
</style>
