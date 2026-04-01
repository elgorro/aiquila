<template>
	<div style="display: contents">
	<NcAppNavigationNew :text="t('aiquila', 'New chat')"
		@click="$emit('new-chat')" />
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
		:name="conv.title || t('aiquila', 'Untitled')"
		:class="{ active: conv.id === activeConversationId }"
		:editable="true"
		:edit-label="t('aiquila', 'Rename')"
		:edit-placeholder="t('aiquila', 'Conversation name')"
		@update:name="newName => onSaveRename(conv.id, newName)"
		@click="$emit('select-conversation', conv.id)">
		<template #actions>
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
		async onSaveRename(id, newName) {
			const title = newName.trim()
			if (!title) return
			try {
				const { data } = await updateConversation(id, { title })
				this.$emit('conversation-renamed', data)
			} catch (err) {
				console.error('Rename failed:', err)
			}
		},
	},
}
</script>

<style scoped>
:deep(.app-navigation-entry.active) {
	background-color: var(--color-primary-element-light) !important;
}

.project-filter {
	padding: 4px 8px 8px;
}
</style>
