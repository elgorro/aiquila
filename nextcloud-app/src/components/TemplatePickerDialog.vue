<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcDialog :name="t('aiquila', 'New project from template')"
		size="normal"
		@closing="$emit('close')">
		<p class="hint">
			{{ t('aiquila', 'The new project is a copy: later changes to the template do not affect it.') }}
		</p>
		<p v-if="loading">
			{{ t('aiquila', 'Loading…') }}
		</p>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcEmptyContent v-else-if="templates.length === 0"
			:name="t('aiquila', 'No templates yet')"
			:description="t('aiquila', 'Save a project as a template from its menu, or ask your administrator to publish some.')" />
		<ul v-else class="template-list">
			<li v-for="template in templates" :key="template.id">
				<button class="template" :disabled="busy" @click="$emit('pick', template)">
					<span class="title">
						{{ template.title }}
						<span v-if="template.scope === 'instance'" class="badge">{{ t('aiquila', 'Organization') }}</span>
					</span>
					<span v-if="template.description" class="description">{{ template.description }}</span>
				</button>
				<NcButton v-if="template.scope !== 'instance'"
					type="tertiary"
					:aria-label="t('aiquila', 'Delete template')"
					:title="t('aiquila', 'Delete template')"
					:disabled="busy"
					@click="onDelete(template)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import { listProjectTemplates, deleteProjectTemplate } from '../api.js'

export default {
	name: 'TemplatePickerDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcEmptyContent,
		DeleteIcon,
	},
	props: {
		/** True while the parent is creating the project. */
		busy: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['pick', 'close'],
	data() {
		return {
			templates: [],
			loading: true,
			error: '',
		}
	},
	async mounted() {
		try {
			const { data } = await listProjectTemplates()
			this.templates = data
		} catch (err) {
			console.error('Failed to load project templates:', err)
			this.error = t('aiquila', 'Could not load templates')
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		async onDelete(template) {
			try {
				await deleteProjectTemplate(template.id)
				this.templates = this.templates.filter(x => x.id !== template.id)
			} catch (err) {
				console.error('Failed to delete template:', err)
				this.error = t('aiquila', 'Could not delete the template')
			}
		},
	},
}
</script>

<style scoped>
.hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.template-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.template-list li {
	display: flex;
	align-items: center;
	gap: 4px;
}

.template {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	text-align: start;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
}

.template:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.title {
	font-weight: 600;
}

.badge {
	margin-inline-start: 6px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	font-size: 12px;
	font-weight: normal;
}

.description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
