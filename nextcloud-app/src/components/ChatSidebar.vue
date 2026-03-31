<template>
	<div style="display: contents">
	<NcAppNavigationNew :text="t('aiquila', 'New chat')"
		@new="$emit('new-chat')" />
	<div v-if="projects.length > 0" class="project-filter">
		<NcSelect :model-value="activeProjectFilter"
			:options="projectFilterOptions"
			:placeholder="t('aiquila', 'All conversations')"
			:clearable="true"
			label="label"
			:reduce="o => o.value"
			@update:model-value="$emit('update-project-filter', $event)" />
	</div>
	<NcAppNavigationItem v-for="conv in filteredConversations"
		:key="conv.id"
		:name="editingId === conv.id ? '' : (conv.title || t('aiquila', 'Untitled'))"
		:class="{ active: conv.id === activeConversationId }"
		@click="onItemClick(conv.id)">
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
			<NcActionButton @click.stop="onStartRename(conv)">
				{{ t('aiquila', 'Rename') }}
			</NcActionButton>
			<NcActionButton @click.stop="$emit('duplicate-conversation', conv.id)">
				{{ t('aiquila', 'Duplicate') }}
			</NcActionButton>
			<NcActionButton @click.stop="$emit('delete-conversation', conv.id)">
				{{ t('aiquila', 'Delete') }}
			</NcActionButton>
		</template>
	</NcAppNavigationItem>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import { updateConversation } from '../api.js'

export default {
	name: 'ChatSidebar',
	components: {
		NcAppNavigationNew,
		NcAppNavigationItem,
		NcActionButton,
		NcSelect,
	},
	props: {
		conversations: {
			type: Array,
			default: () => [],
		},
		activeConversationId: {
			type: Number,
			default: null,
		},
		projects: {
			type: Array,
			default: () => [],
		},
		activeProjectFilter: {
			type: Number,
			default: null,
		},
	},
	emits: [
		'new-chat',
		'select-conversation',
		'delete-conversation',
		'duplicate-conversation',
		'conversation-renamed',
		'update-project-filter',
	],
	data() {
		return {
			editingId: null,
			editingTitle: '',
		}
	},
	computed: {
		filteredConversations() {
			if (!this.activeProjectFilter) return this.conversations
			return this.conversations.filter(c => c.projectId === this.activeProjectFilter)
		},
		projectFilterOptions() {
			return this.projects.map(p => ({
				value: p.id,
				label: p.title,
			}))
		},
	},
	methods: {
		t,
		onItemClick(id) {
			if (this.editingId) return
			this.$emit('select-conversation', id)
		},
		onStartRename(conv) {
			this.editingId = conv.id
			this.editingTitle = conv.title || ''
			setTimeout(() => {
				const input = this.$refs.renameInput
				const el = Array.isArray(input) ? input[0] : input
				el?.focus()
				el?.select()
			}, 50)
		},
		async onSaveRename(id) {
			if (this.editingId !== id) return
			const title = this.editingTitle.trim()
			this.editingId = null
			if (!title) return
			try {
				const { data } = await updateConversation(id, { title })
				this.$emit('conversation-renamed', data)
			} catch (err) {
				console.error('Rename failed:', err)
			}
		},
		onCancelRename() {
			this.editingId = null
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
