<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!--
		List, add, edit and delete library entries: prompts or project
		templates, in one scope. The admin page passes the instance API, the
		personal page the user's own; rows from another scope are filtered out
		so a user's list never offers to edit an organization entry.
	-->
	<div class="library">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="!loading && items.length === 0 && !editing" class="library__empty">
			{{ emptyText }}
		</p>

		<ul v-if="items.length > 0" class="library__list">
			<li v-for="item in items" :key="item.id" class="library__item">
				<div class="library__text">
					<span class="library__title">{{ item.title }}</span>
					<span v-if="item.description" class="library__description">{{ item.description }}</span>
				</div>
				<NcButton type="tertiary"
					:aria-label="t('aiquila', 'Edit')"
					:title="t('aiquila', 'Edit')"
					@click="startEdit(item)">
					<template #icon>
						<PencilOutlineIcon :size="20" />
					</template>
				</NcButton>
				<NcButton type="tertiary"
					:aria-label="t('aiquila', 'Delete')"
					:title="t('aiquila', 'Delete')"
					@click="remove(item)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>

		<form v-if="editing" class="library__form" @submit.prevent="save">
			<NcTextField v-model="form.title"
				:label="t('aiquila', 'Title')"
				:maxlength="255"
				required />
			<NcTextField v-model="form.description"
				:label="t('aiquila', 'Description (optional)')"
				:maxlength="1000" />
			<label class="library__label" :for="textId">
				{{ kind === 'prompts' ? t('aiquila', 'Prompt') : t('aiquila', 'System prompt') }}
			</label>
			<textarea :id="textId"
				v-model="form.text"
				class="library__textarea"
				rows="6"
				:maxlength="maxLength" />
			<template v-if="kind === 'project-templates'">
				<label class="library__label" :for="pathsId">
					{{ t('aiquila', 'Suggested paths, one per line (end a folder with /)') }}
				</label>
				<textarea :id="pathsId"
					v-model="form.paths"
					class="library__textarea"
					rows="3"
					placeholder="/Documents/Taxes/" />
			</template>
			<div class="library__actions">
				<NcButton type="primary" native-type="submit" :disabled="saving || !canSave">
					{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
				</NcButton>
				<NcButton type="tertiary" @click="cancel">
					{{ t('aiquila', 'Cancel') }}
				</NcButton>
			</div>
		</form>

		<NcButton v-else type="secondary" @click="startEdit(null)">
			<template #icon>
				<PlusIcon :size="20" />
			</template>
			{{ kind === 'prompts' ? t('aiquila', 'Add prompt') : t('aiquila', 'Add template') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

let uid = 0

/** "/a/b/" → directory, "/a/b.md" → file. */
function parsePaths(text) {
	return text.split('\n')
		.map(line => line.trim())
		.filter(Boolean)
		.map(line => line.endsWith('/') && line.length > 1
			? { path: line.replace(/\/+$/, ''), pathType: 'directory' }
			: { path: line, pathType: 'file' })
}

function formatPaths(paths) {
	return (paths || []).map(p => p.pathType === 'directory' ? p.path + '/' : p.path).join('\n')
}

export default {
	name: 'LibraryEditor',
	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
		DeleteIcon,
		PencilOutlineIcon,
		PlusIcon,
	},
	props: {
		/** 'prompts' or 'project-templates' */
		kind: {
			type: String,
			required: true,
		},
		/** `{list, create, update, remove}` from settings-api.js */
		api: {
			type: Object,
			required: true,
		},
		/** Only rows of this scope are shown and edited. */
		scope: {
			type: String,
			default: 'user',
		},
		emptyText: {
			type: String,
			default: '',
		},
		maxLength: {
			type: Number,
			default: 20000,
		},
	},
	data() {
		uid += 1
		return {
			textId: `aiquila-library-text-${uid}`,
			pathsId: `aiquila-library-paths-${uid}`,
			items: [],
			loading: true,
			error: '',
			editing: false,
			editId: null,
			saving: false,
			form: { title: '', description: '', text: '', paths: '' },
		}
	},
	computed: {
		canSave() {
			return this.form.title.trim() !== ''
				&& (this.kind !== 'prompts' || this.form.text.trim() !== '')
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load() {
			this.loading = true
			try {
				const { data } = await this.api.list()
				this.items = data.filter(item => item.scope === this.scope)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},
		startEdit(item) {
			this.error = ''
			this.editing = true
			this.editId = item?.id ?? null
			this.form = {
				title: item?.title || '',
				description: item?.description || '',
				text: (this.kind === 'prompts' ? item?.content : item?.systemPrompt) || '',
				paths: formatPaths(item?.paths),
			}
		},
		cancel() {
			this.editing = false
			this.editId = null
		},
		payload() {
			const base = { title: this.form.title, description: this.form.description }
			return this.kind === 'prompts'
				? { ...base, content: this.form.text }
				: { ...base, systemPrompt: this.form.text, paths: parsePaths(this.form.paths) }
		},
		async save() {
			this.saving = true
			this.error = ''
			try {
				if (this.editId === null) {
					await this.api.create(this.payload())
				} else {
					await this.api.update(this.editId, this.payload())
				}
				this.cancel()
				await this.load()
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.saving = false
			}
		},
		async remove(item) {
			this.error = ''
			try {
				await this.api.remove(item.id)
				this.items = this.items.filter(x => x.id !== item.id)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			}
		},
	},
}
</script>

<style scoped>
.library {
	max-width: 640px;
}

.library__empty {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.library__list {
	margin-bottom: 12px;
}

.library__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.library__text {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.library__title {
	font-weight: 600;
}

.library__description {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.library__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.library__label {
	font-weight: 600;
}

.library__textarea {
	width: 100%;
	resize: vertical;
}

.library__actions {
	display: flex;
	gap: 8px;
}
</style>
