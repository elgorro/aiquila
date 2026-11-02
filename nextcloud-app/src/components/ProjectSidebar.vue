<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div style="display: contents">
	<NcAppNavigationNew :text="t('aiquila', 'New project')"
		@click="$emit('new-project')" />
	<NcAppNavigationItem :name="t('aiquila', 'New from template…')"
		@click="$emit('new-project-from-template')">
		<template #icon>
			<ShapeOutlineIcon :size="20" />
		</template>
	</NcAppNavigationItem>
	<NcAppNavigationItem v-for="project in projects"
		:key="project.id"
		:name="project.title"
		:class="{ active: project.id === activeProjectId }"
		:editable="true"
		:edit-label="t('aiquila', 'Rename')"
		:edit-placeholder="t('aiquila', 'Project name')"
		@update:name="newName => onSaveRename(project.id, newName)"
		@click="$emit('select-project', project.id)">
		<template #actions>
			<NcActionButton @click.stop="$emit('duplicate-project', project.id)">
				{{ t('aiquila', 'Duplicate') }}
			</NcActionButton>
			<NcActionButton @click.stop="onSaveAsTemplate(project.id)">
				{{ t('aiquila', 'Save as template') }}
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
import ShapeOutlineIcon from 'vue-material-design-icons/ShapeOutline.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import { updateProject, createProjectTemplate } from '../api.js'

export default {
	name: 'ProjectSidebar',
	components: {
		NcAppNavigationNew,
		NcAppNavigationItem,
		NcActionButton,
		ShapeOutlineIcon,
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
		'new-project-from-template',
		'select-project',
		'delete-project',
		'duplicate-project',
		'project-renamed',
	],
	methods: {
		t,
		async onSaveAsTemplate(id) {
			try {
				await createProjectTemplate({ projectId: id })
				showSuccess(t('aiquila', 'Saved as a personal template'))
			} catch (err) {
				console.error('Save as template failed:', err)
				showError(t('aiquila', 'Could not save the template'))
			}
		},
		async onSaveRename(id, newName) {
			const title = newName.trim()
			if (!title) return
			try {
				const { data } = await updateProject(id, { title })
				this.$emit('project-renamed', data)
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

</style>
