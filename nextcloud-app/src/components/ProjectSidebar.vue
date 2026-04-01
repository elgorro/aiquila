<template>
	<div style="display: contents">
	<NcAppNavigationNew :text="t('aiquila', 'New project')"
		@click="$emit('new-project')" />
	<NcAppNavigationItem v-for="project in projects"
		:key="project.id"
		:name="editingId === project.id ? '' : project.title"
		:class="{ active: project.id === activeProjectId }"
		:menu-open="menuOpenId === project.id"
		@update:menu-open="val => menuOpenId = val ? project.id : null"
		@click="onItemClick(project.id)">
		<template v-if="editingId === project.id" #name>
			<input ref="renameInput"
				v-model="editingTitle"
				class="rename-input"
				@keydown.enter="onSaveRename(project.id)"
				@keydown.escape="onCancelRename"
				@blur="onSaveRename(project.id)"
				@click.stop />
		</template>
		<template #actions>
			<NcActionButton @click.stop="onStartRename(project)">
				{{ t('aiquila', 'Rename') }}
			</NcActionButton>
			<NcActionButton @click.stop="$emit('duplicate-project', project.id)">
				{{ t('aiquila', 'Duplicate') }}
			</NcActionButton>
			<NcActionButton @click.stop="$emit('delete-project', project.id)">
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

import { updateProject } from '../api.js'

export default {
	name: 'ProjectSidebar',
	components: {
		NcAppNavigationNew,
		NcAppNavigationItem,
		NcActionButton,
	},
	props: {
		projects: {
			type: Array,
			default: () => [],
		},
		activeProjectId: {
			type: Number,
			default: null,
		},
	},
	emits: [
		'new-project',
		'select-project',
		'delete-project',
		'duplicate-project',
		'project-renamed',
	],
	data() {
		return {
			editingId: null,
			editingTitle: '',
			menuOpenId: null,
		}
	},
	methods: {
		t,
		onItemClick(id) {
			if (this.editingId) return
			this.$emit('select-project', id)
		},
		onStartRename(project) {
			this.menuOpenId = null
			this.editingId = project.id
			this.editingTitle = project.title || ''
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
				const { data } = await updateProject(id, { title })
				this.$emit('project-renamed', data)
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
</style>
