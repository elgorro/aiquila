<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="aiquila">
		<NcAppNavigation>
			<template #list>
				<router-view name="sidebar" v-slot="{ Component }">
					<component :is="Component"
						:conversations="conversations"
						:active-conversation-id="activeConversationId"
						:projects="projects"
						:active-project-filter="activeProjectFilter"
						:active-project-id="activeProjectIdForEditor"
						@new-chat="onNewChat"
						@select-conversation="onSelectConversation"
						@delete-conversation="confirmDeleteConversation"
						@duplicate-conversation="onDuplicateConversation"
						@conversation-renamed="onConversationUpdated"
						@update-project-filter="activeProjectFilter = $event"
						@new-project="onNewProject"
						@select-project="onSelectProject"
						@delete-project="confirmDeleteProject"
						@duplicate-project="onDuplicateProject"
						@project-renamed="onProjectRenamed" />
				</router-view>
			</template>
			<template #footer>
				<NcAppNavigationSettings :name="t('aiquila', 'Settings')">
					<NavigationSettings />
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div class="app-content-inner">
				<TabSelector />
				<div class="tab-content">
					<router-view v-slot="{ Component }">
						<component :is="Component"
							:conversation="activeConversation"
							:project="activeProject"
							:conversations="conversations"
							:projects="projects"
							@new-chat="onNewChat"
							@conversation-updated="onConversationUpdated"
							@message-sent="onMessageSent"
							@project-updated="onProjectUpdated"
							@create-project="onNewProject"
							@navigate-to-chat="onNavigateToChat"
							@new-project-chat="onNewProjectChat" />
					</router-view>
				</div>
			</div>
		</NcAppContent>

		<!-- Delete confirmation (shared for conversations and projects) -->
		<NcDialog v-if="deleteConfirm.id !== null"
			:name="deleteConfirm.type === 'project'
				? t('aiquila', 'Delete project')
				: t('aiquila', 'Delete conversation')"
			@closing="onCancelDelete">
			<p v-if="deleteConfirm.type === 'project'">
				{{ t('aiquila', 'Delete this project? Conversations using it will keep their messages but lose the project link.') }}
			</p>
			<p v-else>
				{{ t('aiquila', 'Delete this conversation and all its messages? This cannot be undone.') }}
			</p>
			<template #actions>
				<NcButton type="secondary" @click="onCancelDelete">
					{{ t('aiquila', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="onConfirmDelete">
					{{ t('aiquila', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

import TabSelector from './components/TabSelector.vue'

const NavigationSettings = defineAsyncComponent(() => import('./components/NavigationSettings.vue'))
import {
	createConversation,
	getConversation,
	updateConversation,
	deleteConversation,
	duplicateConversation,
	listProjects,
	getProject,
	createProject,
	deleteProject,
} from './api.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationSettings,
		NcAppContent,
		NcButton,
		NcDialog,
		TabSelector,
		NavigationSettings,
	},
	data() {
		return {
			conversations: loadState('aiquila', 'conversations', []),
			activeConversation: null,
			loading: false,
			projects: [],
			activeProjectFilter: null,
			activeProject: null,
			deleteConfirm: { id: null, type: null },
		}
	},
	computed: {
		activeConversationId() {
			const id = this.$route.params.conversationId
			return id ? Number(id) : null
		},
		activeProjectIdForEditor() {
			const id = this.$route.params.projectId
			return id ? Number(id) : null
		},
	},
	watch: {
		activeConversationId: {
			immediate: true,
			async handler(id) {
				if (!id) {
					this.activeConversation = null
					return
				}
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
		},
		activeProjectIdForEditor: {
			immediate: true,
			async handler(id) {
				if (!id) {
					this.activeProject = null
					return
				}
				try {
					const { data } = await getProject(id)
					this.activeProject = data
				} catch (err) {
					console.error('Failed to load project:', err)
					this.activeProject = null
				}
			},
		},
	},
	async mounted() {
		await this.loadProjects()
	},
	methods: {
		t,

		// ── Projects ──────────────────────────────────────────

		async loadProjects() {
			try {
				const { data } = await listProjects()
				this.projects = data
			} catch (err) {
				console.error('Failed to load projects:', err)
			}
		},
		onSelectProject(id) {
			if (this.activeProjectIdForEditor === id) return
			this.$router.push({ name: 'projects', params: { projectId: String(id) } })
		},
		async onNewProject() {
			try {
				const { data } = await createProject({
					title: t('aiquila', 'New project'),
					description: '',
					systemPrompt: '',
				})
				this.projects.unshift(data)
				this.$router.push({ name: 'projects', params: { projectId: String(data.id) } })
			} catch (err) {
				console.error('Failed to create project:', err)
			}
		},
		async onDuplicateProject(id) {
			const original = this.projects.find(p => p.id === id)
			if (!original) return
			try {
				const { data } = await createProject({
					title: original.title + ' (copy)',
					description: original.description || '',
					systemPrompt: original.systemPrompt || '',
				})
				this.projects.unshift(data)
				this.$router.push({ name: 'projects', params: { projectId: String(data.id) } })
			} catch (err) {
				console.error('Failed to duplicate project:', err)
			}
		},
		onProjectRenamed(updatedProject) {
			this.updateProjectInList(updatedProject)
		},
		onProjectUpdated(updatedProject) {
			this.updateProjectInList(updatedProject)
			if (this.activeProject && this.activeProject.id === updatedProject.id) {
				this.activeProject = { ...this.activeProject, ...updatedProject }
			}
		},
		updateProjectInList(project) {
			const idx = this.projects.findIndex(p => p.id === project.id)
			if (idx !== -1) {
				this.projects.splice(idx, 1, { ...this.projects[idx], ...project })
			}
		},
		confirmDeleteProject(id) {
			this.deleteConfirm = { id, type: 'project' }
		},
		async onDeleteProject(id) {
			try {
				await deleteProject(id)
				this.projects = this.projects.filter(p => p.id !== id)
				if (this.activeProjectIdForEditor === id) {
					this.$router.push({ name: 'projects' })
				}
			} catch (err) {
				console.error('Failed to delete project:', err)
			}
		},

		// ── Cross-tab navigation ──────────────────────────────

		onNavigateToChat(conversationId) {
			this.$router.push({ name: 'chat', params: { conversationId: String(conversationId) } })
		},
		async onNewProjectChat(projectId) {
			try {
				const { data: newConv } = await createConversation()
				const { data: updated } = await updateConversation(newConv.id, { projectId })
				const merged = { ...newConv, ...updated }
				this.conversations.unshift(merged)
				this.$router.push({ name: 'chat', params: { conversationId: String(merged.id) } })
			} catch (err) {
				console.error('Failed to create project chat:', err)
			}
		},

		// ── Conversations ─────────────────────────────────────

		async onNewChat() {
			try {
				const { data } = await createConversation()
				this.conversations.unshift(data)
				this.$router.push({ name: 'chat', params: { conversationId: String(data.id) } })
			} catch (err) {
				console.error('Failed to create conversation:', err)
			}
		},
		onSelectConversation(id) {
			if (this.activeConversationId === id) return
			this.$router.push({ name: 'chat', params: { conversationId: String(id) } })
		},
		confirmDeleteConversation(id) {
			this.deleteConfirm = { id, type: 'conversation' }
		},
		async onDeleteConversation(id) {
			try {
				await deleteConversation(id)
				this.conversations = this.conversations.filter(c => c.id !== id)
				if (this.activeConversationId === id) {
					this.$router.push({ name: 'chat' })
				}
			} catch (err) {
				console.error('Failed to delete conversation:', err)
			}
		},
		async onDuplicateConversation(id) {
			try {
				const { data } = await duplicateConversation(id)
				this.conversations.unshift(data)
				this.$router.push({ name: 'chat', params: { conversationId: String(data.id) } })
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

		// ── Delete confirmation ───────────────────────────────

		onCancelDelete() {
			this.deleteConfirm = { id: null, type: null }
		},
		onConfirmDelete() {
			const { id, type } = this.deleteConfirm
			if (!id) return
			this.deleteConfirm = { id: null, type: null }
			if (type === 'project') {
				this.onDeleteProject(id)
			} else {
				this.onDeleteConversation(id)
			}
		},
	},
}
</script>

<style scoped>
.app-content-inner {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.tab-content {
	flex: 1;
	min-height: 0;
	overflow: auto;
}
</style>
