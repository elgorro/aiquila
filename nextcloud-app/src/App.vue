<template>
	<NcContent app-name="aiquila">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew :text="t('aiquila', 'New chat')"
					@new="onNewChat" />
				<div v-if="projects.length > 0" class="project-filter">
					<NcSelect v-model="activeProjectId"
						:options="projectFilterOptions"
						:placeholder="t('aiquila', 'All conversations')"
						:clearable="true"
						label="label"
						:reduce="o => o.value"
						@input="activeProjectId = $event" />
				</div>
				<NcAppNavigationItem v-for="conv in filteredConversations"
					:key="conv.id"
					:name="editingId === conv.id ? '' : (conv.title || t('aiquila', 'Untitled'))"
					:class="{ active: conv.id === activeConversationId }"
					@click="onSelectConversation(conv.id)">
					<template v-if="editingId === conv.id" #name>
						<input ref="renameInput"
							v-model="editingTitle"
							class="rename-input"
							@keydown.enter="onSaveRename(conv.id)"
							@keydown.escape="onCancelRename"
							@blur="onSaveRename(conv.id)"
							@click.stop />
					</template>
					<template #actions>
						<NcActionButton @click="onStartRename(conv)">
							{{ t('aiquila', 'Rename') }}
						</NcActionButton>
						<NcActionButton @click="onDuplicateConversation(conv.id)">
							{{ t('aiquila', 'Duplicate') }}
						</NcActionButton>
						<NcActionButton @click="confirmDelete(conv.id)">
							{{ t('aiquila', 'Delete') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<NcAppNavigationSettings :name="t('aiquila', 'Settings')">
					<NavigationSettings @open-projects="showProjectManager = true" />
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<ChatView v-if="activeConversation"
				:conversation="activeConversation"
				:projects="projects"
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
		<ProjectManager v-if="showProjectManager"
			:projects="projects"
			@close="showProjectManager = false"
			@projects-changed="loadProjects" />
		<NcDialog v-if="deleteConfirmId !== null"
			:name="t('aiquila', 'Delete conversation')"
			@closing="deleteConfirmId = null">
			<p>{{ t('aiquila', 'Delete this conversation and all its messages? This cannot be undone.') }}</p>
			<template #actions>
				<NcButton type="secondary" @click="deleteConfirmId = null">
					{{ t('aiquila', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="onDeleteConversation(deleteConfirmId)">
					{{ t('aiquila', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ChatIcon from 'vue-material-design-icons/Chat.vue'

import ChatView from './components/ChatView.vue'
import NavigationSettings from './components/NavigationSettings.vue'
import ProjectManager from './components/ProjectManager.vue'
import {
	createConversation,
	getConversation,
	updateConversation,
	deleteConversation,
	duplicateConversation,
	listProjects,
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
		NcDialog,
		NcSelect,
		ChatIcon,
		ChatView,
		NavigationSettings,
		ProjectManager,
	},
	data() {
		return {
			conversations: loadState('aiquila', 'conversations', []),
			activeConversationId: null,
			activeConversation: null,
			loading: false,
			editingId: null,
			editingTitle: '',
			deleteConfirmId: null,
			projects: [],
			activeProjectId: null,
			showProjectManager: false,
		}
	},
	computed: {
		filteredConversations() {
			if (!this.activeProjectId) return this.conversations
			return this.conversations.filter(c => c.projectId === this.activeProjectId)
		},
		projectFilterOptions() {
			return this.projects.map(p => ({
				value: p.id,
				label: p.title,
			}))
		},
	},
	async mounted() {
		await this.loadProjects()
	},
	methods: {
		t,
		async loadProjects() {
			try {
				const { data } = await listProjects()
				this.projects = data
			} catch (err) {
				console.error('Failed to load projects:', err)
			}
		},
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
		confirmDelete(id) {
			this.deleteConfirmId = id
		},
		async onDeleteConversation(id) {
			this.deleteConfirmId = null
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
		onStartRename(conv) {
			this.editingId = conv.id
			this.editingTitle = conv.title || ''
			this.$nextTick(() => {
				const input = this.$refs.renameInput
				if (Array.isArray(input)) {
					input[0]?.focus()
				} else {
					input?.focus()
				}
			})
		},
		async onSaveRename(id) {
			if (this.editingId !== id) return
			const title = this.editingTitle.trim()
			this.editingId = null
			if (!title) return
			try {
				const { data } = await updateConversation(id, { title })
				this.onConversationUpdated(data)
			} catch (err) {
				console.error('Rename failed:', err)
			}
		},
		onCancelRename() {
			this.editingId = null
		},
		async onDuplicateConversation(id) {
			try {
				const { data } = await duplicateConversation(id)
				this.conversations.unshift(data)
				await this.onSelectConversation(data.id)
			} catch (err) {
				console.error('Duplicate failed:', err)
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
			if (this.activeConversation && this.activeConversation.id === updatedConv.id) {
				Object.assign(this.activeConversation, updatedConv)
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

.rename-input {
	width: 100%;
	padding: 4px 8px;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius);
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	outline: none;
}

.project-filter {
	padding: 4px 8px 8px;
}
</style>
