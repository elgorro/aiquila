<template>
	<div v-if="project" class="project-editor">
		<h2 class="editor-title">{{ project.title }}</h2>

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
				rows="5"
				:placeholder="t('aiquila', 'Custom instructions for this project…')" />
		</div>

		<div class="form-group">
			<label>{{ t('aiquila', 'Paths') }}</label>
			<div v-if="form.paths.length === 0" class="paths-empty">
				{{ t('aiquila', 'No files or directories attached yet.') }}
			</div>
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
			<NcButton type="primary"
				:disabled="!isDirty || !form.title.trim() || saving"
				@click="onSave">
				{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
			</NcButton>
		</div>

		<p v-if="status" :class="['status-msg', statusType]">
			{{ status }}
		</p>

		<!-- Related Chats -->
		<div class="form-group related-chats-section">
			<label>{{ t('aiquila', 'Related chats') }}</label>
			<div v-if="relatedChats.length === 0" class="paths-empty">
				{{ t('aiquila', 'No conversations linked to this project yet.') }}
			</div>
			<div v-for="chat in relatedChats"
				:key="chat.id"
				class="path-item chat-item"
				@click="$emit('navigate-to-chat', chat.id)">
				<ChatIcon :size="16" />
				<span class="path-value">{{ chat.title || t('aiquila', 'Untitled') }}</span>
			</div>
			<div class="path-actions">
				<NcButton type="secondary" @click="$emit('new-project-chat', project.id)">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('aiquila', 'New chat') }}
				</NcButton>
			</div>
		</div>
	</div>
	<NcEmptyContent v-else
		:name="t('aiquila', 'No project selected')">
		<template #icon>
			<span class="empty-icon">📦</span>
		</template>
		<template #action>
			<NcButton type="primary" @click="$emit('create-project')">
				{{ t('aiquila', 'New project') }}
			</NcButton>
		</template>
		{{ t('aiquila', 'Select a project from the sidebar or create a new one.') }}
	</NcEmptyContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ChatIcon from 'vue-material-design-icons/Chat.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'

import {
	updateProject,
	addProjectPath,
	removeProjectPath,
} from '../api.js'

export default {
	name: 'ProjectEditor',
	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		ChatIcon,
		PlusIcon,
	},
	props: {
		project: {
			type: Object,
			default: null,
		},
		conversations: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['project-updated', 'create-project', 'navigate-to-chat', 'new-project-chat'],
	data() {
		return {
			form: this.initForm(),
			saving: false,
			status: '',
			statusType: '',
		}
	},
	computed: {
		isDirty() {
			if (!this.project) return false
			return this.form.title !== this.project.title
				|| this.form.description !== (this.project.description || '')
				|| this.form.systemPrompt !== (this.project.systemPrompt || '')
		},
		relatedChats() {
			if (!this.project) return []
			return this.conversations.filter(c => c.projectId === this.project.id)
		},
	},
	watch: {
		project: {
			handler() {
				this.form = this.initForm()
				this.status = ''
			},
			deep: true,
		},
	},
	methods: {
		t,
		initForm() {
			if (!this.project) {
				return { title: '', description: '', systemPrompt: '', paths: [] }
			}
			return {
				title: this.project.title,
				description: this.project.description || '',
				systemPrompt: this.project.systemPrompt || '',
				paths: [...(this.project.paths || [])],
			}
		},
		async onSave() {
			this.saving = true
			this.status = ''
			try {
				const { data } = await updateProject(this.project.id, {
					title: this.form.title,
					description: this.form.description,
					systemPrompt: this.form.systemPrompt,
				})
				this.status = t('aiquila', 'Saved!')
				this.statusType = 'success'
				this.$emit('project-updated', { ...data, paths: this.form.paths })
			} catch (err) {
				this.status = t('aiquila', 'Error saving: ') + err.message
				this.statusType = 'error'
			} finally {
				this.saving = false
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
					const { data } = await addProjectPath(this.project.id, p, 'file')
					this.form.paths.push(data)
				}
				this.$emit('project-updated', { ...this.project, paths: this.form.paths })
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
				const { data } = await addProjectPath(this.project.id, path, 'directory')
				this.form.paths.push(data)
				this.$emit('project-updated', { ...this.project, paths: this.form.paths })
			} catch (err) {
				if (!(err instanceof FilePickerClosed)) {
					console.error('Directory picker error:', err)
				}
			}
		},
		async onRemovePath(pathId) {
			try {
				await removeProjectPath(this.project.id, pathId)
				this.form.paths = this.form.paths.filter(p => p.id !== pathId)
				this.$emit('project-updated', { ...this.project, paths: this.form.paths })
			} catch (err) {
				console.error('Failed to remove path:', err)
			}
		},
	},
}
</script>

<style scoped>
.project-editor {
	padding: 20px 24px;
	max-width: 700px;
}

.editor-title {
	font-size: 20px;
	font-weight: 700;
	margin-bottom: 20px;
}

.form-group {
	margin-bottom: 16px;
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

.paths-empty {
	font-size: 13px;
	color: var(--color-text-lighter);
	padding: 8px 0;
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
	gap: 8px;
	margin-top: 20px;
}

.status-msg {
	margin-top: 8px;
	font-size: 13px;
}

.status-msg.success {
	color: var(--color-success);
}

.status-msg.error {
	color: var(--color-error);
}

.related-chats-section {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.chat-item {
	cursor: pointer;
	border-radius: var(--border-radius);
	padding: 6px 8px;
}

.chat-item:hover {
	background: var(--color-background-hover);
}

.empty-icon {
	font-size: 64px;
}
</style>
