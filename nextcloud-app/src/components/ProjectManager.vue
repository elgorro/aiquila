<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcDialog :name="t('aiquila', 'Manage Projects')"
		size="normal"
		@closing="$emit('close')">
		<div class="project-manager">
			<!-- Project list -->
			<div v-if="!editing" class="project-list">
				<div v-for="project in projects"
					:key="project.id"
					class="project-item">
					<div class="project-info">
						<strong>{{ project.title }}</strong>
						<span v-if="project.description" class="project-desc">{{ project.description }}</span>
						<span class="project-meta">
							{{ project.paths?.length || 0 }} {{ t('aiquila', 'paths') }}
						</span>
					</div>
					<div class="project-actions">
						<NcButton type="tertiary" @click="onEdit(project)">
							{{ t('aiquila', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary" @click="onDelete(project.id)">
							{{ t('aiquila', 'Delete') }}
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-if="projects.length === 0"
					:name="t('aiquila', 'No projects yet')">
					{{ t('aiquila', 'Create a project to bundle files and prompts for your conversations.') }}
				</NcEmptyContent>

				<div class="list-actions">
					<NcButton type="primary" @click="onNew">
						{{ t('aiquila', 'New project') }}
					</NcButton>
				</div>
			</div>

			<!-- Edit/Create form -->
			<div v-else class="project-form">
				<div class="form-group">
					<label>{{ t('aiquila', 'Title') }}</label>
					<NcTextField v-model="form.title"
						:placeholder="t('aiquila', 'Project title')" />
				</div>

				<div class="form-group">
					<label>{{ t('aiquila', 'Description') }}</label>
					<NcTextField v-model="form.description"
						:placeholder="t('aiquila', 'Optional description')" />
				</div>

				<div class="form-group">
					<label>{{ t('aiquila', 'System prompt') }}</label>
					<textarea v-model="form.systemPrompt"
						class="prompt-input"
						rows="4"
						:placeholder="t('aiquila', 'Custom instructions for this project…')" />
				</div>

				<div v-if="form.id" class="form-group">
					<label>{{ t('aiquila', 'Paths') }}</label>
					<div v-for="path in form.paths"
						:key="path.id"
						class="path-item">
						<span class="path-type">{{ path.pathType === 'directory' ? '📁' : '📄' }}</span>
						<span class="path-value">{{ path.path }}</span>
						<button class="path-remove" @click="onRemovePath(path.id)">✕</button>
					</div>
					<div class="path-actions">
						<NcButton type="secondary" @click="onAddFile">
							{{ t('aiquila', 'Add file') }}
						</NcButton>
						<NcButton type="secondary" @click="onAddDirectory">
							{{ t('aiquila', 'Add directory') }}
						</NcButton>
					</div>
				</div>

				<div class="form-actions">
					<NcButton type="secondary" @click="editing = false">
						{{ t('aiquila', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:disabled="!form.title.trim() || saving"
						@click="onSave">
						{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'

import {
	createProject,
	updateProject,
	deleteProject,
	addProjectPath,
	removeProjectPath,
} from '../api.js'

export default {
	name: 'ProjectManager',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcEmptyContent,
	},
	props: {
		projects: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'projects-changed'],
	data() {
		return {
			editing: false,
			saving: false,
			form: {
				id: null,
				title: '',
				description: '',
				systemPrompt: '',
				paths: [],
			},
		}
	},
	methods: {
		t,
		onNew() {
			this.form = { id: null, title: '', description: '', systemPrompt: '', paths: [] }
			this.editing = true
		},
		onEdit(project) {
			this.form = {
				id: project.id,
				title: project.title,
				description: project.description || '',
				systemPrompt: project.systemPrompt || '',
				paths: [...(project.paths || [])],
			}
			this.editing = true
		},
		async onSave() {
			this.saving = true
			try {
				if (this.form.id) {
					await updateProject(this.form.id, {
						title: this.form.title,
						description: this.form.description,
						systemPrompt: this.form.systemPrompt,
					})
				} else {
					await createProject({
						title: this.form.title,
						description: this.form.description,
						systemPrompt: this.form.systemPrompt,
					})
				}
				this.editing = false
				this.$emit('projects-changed')
			} catch (err) {
				console.error('Failed to save project:', err)
			} finally {
				this.saving = false
			}
		},
		async onDelete(id) {
			try {
				await deleteProject(id)
				this.$emit('projects-changed')
			} catch (err) {
				console.error('Failed to delete project:', err)
			}
		},
		async onAddFile() {
			try {
				const picker = getFilePickerBuilder('Select files')
					.setMultiSelect(true)
					.setType(1)
					.allowDirectories(false)
					.build()

				const paths = await picker.pick()
				const pathList = Array.isArray(paths) ? paths : [paths]

				for (const p of pathList) {
					const { data } = await addProjectPath(this.form.id, p, 'file')
					this.form.paths.push(data)
				}
				this.$emit('projects-changed')
			} catch (err) {
				if (!(err instanceof FilePickerClosed)) {
					console.error('File picker error:', err)
				}
			}
		},
		async onAddDirectory() {
			try {
				const picker = getFilePickerBuilder('Select directory')
					.setType(1)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.build()

				const path = await picker.pick()
				const { data } = await addProjectPath(this.form.id, path, 'directory')
				this.form.paths.push(data)
				this.$emit('projects-changed')
			} catch (err) {
				if (!(err instanceof FilePickerClosed)) {
					console.error('Directory picker error:', err)
				}
			}
		},
		async onRemovePath(pathId) {
			try {
				await removeProjectPath(this.form.id, pathId)
				this.form.paths = this.form.paths.filter(p => p.id !== pathId)
				this.$emit('projects-changed')
			} catch (err) {
				console.error('Failed to remove path:', err)
			}
		},
	},
}
</script>

<style scoped>
.project-manager {
	min-height: 300px;
}

.project-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.project-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.project-desc {
	font-size: 13px;
	color: var(--color-text-lighter);
}

.project-meta {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.project-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.list-actions {
	margin-top: 16px;
	display: flex;
	justify-content: flex-end;
}

/* Form */
.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	font-weight: 600;
}

.prompt-input {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: var(--font-face);
	font-size: 13px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.path-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.path-type {
	flex-shrink: 0;
}

.path-value {
	flex: 1;
	font-size: 13px;
	font-family: 'Menlo', 'Consolas', monospace;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.path-remove {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	font-size: 14px;
	padding: 2px 6px;
	border-radius: 50%;
}

.path-remove:hover {
	color: var(--color-error);
}

.path-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
