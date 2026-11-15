<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!--
		Sequences in three groups. Personal page: built-ins (switch on or off
		for yourself), the organization's (read-only, or duplicate one to
		edit it as your own), and yours. Admin page: built-ins (on or off for
		everyone), the organization's (edit), and the policy.

		There is no Run button: this page talks to Nextcloud, and Nextcloud
		cannot be sure to reach the MCP server. Sequences run from an MCP
		client, as list_sequences / run_sequence or their own seq_* tool.
	-->
	<div class="seq-list">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="admin">
				<h3>{{ t('aiquila', 'Policy') }}</h3>
				<NcCheckboxRadioSwitch :model-value="policy.allowPersonal" @update:model-value="v => savePolicy({ allowPersonal: v })">
					{{ t('aiquila', 'Users may write their own sequences') }}
				</NcCheckboxRadioSwitch>
				<div class="seq-list__policy">
					<NcTextField v-for="field in policyFields"
						:key="field.key"
						:model-value="String(policyDraft[field.key] ?? policy[field.key])"
						type="number"
						:label="field.label"
						@update:model-value="v => policyDraft[field.key] = v" />
					<NcButton type="secondary" :disabled="!policyDirty" @click="savePolicy(policyNumbers())">
						{{ t('aiquila', 'Save limits') }}
					</NcButton>
				</div>
			</template>

			<h3>{{ t('aiquila', 'Built-in') }}</h3>
			<ul class="seq-list__items">
				<li v-for="b in builtins" :key="b.slug" class="seq-list__item">
					<div class="seq-list__text">
						<span class="seq-list__title">{{ b.title }} <code>seq_{{ b.slug }}</code></span>
						<span class="seq-list__description">{{ b.description }}</span>
						<span v-if="!admin && !b.adminEnabled" class="seq-list__description">
							{{ t('aiquila', 'Switched off by your administrator') }}
						</span>
					</div>
					<NcCheckboxRadioSwitch :model-value="admin ? b.adminEnabled : b.enabled"
						:disabled="!admin && !b.adminEnabled"
						type="switch"
						:aria-label="b.title"
						@update:model-value="v => setBuiltin(b, v)" />
				</li>
			</ul>

			<h3>{{ t('aiquila', 'Organization') }}</h3>
			<p v-if="organization.length === 0" class="seq-list__empty">
				{{ t('aiquila', 'No organization sequences.') }}
			</p>
			<ul class="seq-list__items">
				<li v-for="s in organization" :key="s.id" class="seq-list__item">
					<div class="seq-list__text">
						<span class="seq-list__title">{{ s.title }} <code>seq_{{ s.slug }}</code></span>
						<span v-if="s.description" class="seq-list__description">{{ s.description }}</span>
						<span v-if="!s.enabled" class="seq-list__description">{{ t('aiquila', 'Disabled') }}</span>
					</div>
					<template v-if="admin">
						<NcButton type="tertiary" :aria-label="t('aiquila', 'Edit')" @click="startEdit(s)">
							<template #icon>
								<PencilOutlineIcon :size="20" />
							</template>
						</NcButton>
						<NcButton type="tertiary" :aria-label="t('aiquila', 'Delete')" @click="remove(s)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</template>
					<NcButton v-else-if="policy.allowPersonal" type="tertiary" @click="duplicate(s)">
						{{ t('aiquila', 'Duplicate to mine') }}
					</NcButton>
				</li>
			</ul>
			<SequenceEditor v-if="admin && editing"
				:key="editKey"
				:sequence="editing.sequence"
				:catalog="catalog"
				scope="instance"
				:max-steps="policy.maxSteps"
				:saving="saving"
				:error="saveError"
				@save="save"
				@cancel="cancel" />
			<NcButton v-if="admin && !editing" type="secondary" @click="startEdit(null)">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('aiquila', 'Add sequence') }}
			</NcButton>

			<template v-if="!admin">
				<h3>{{ t('aiquila', 'Mine') }}</h3>
				<NcNoteCard v-if="!policy.allowPersonal" type="info">
					{{ t('aiquila', 'Your administrator does not allow personal sequences.') }}
				</NcNoteCard>
				<template v-else>
					<p v-if="mine.length === 0 && !editing" class="seq-list__empty">
						{{ t('aiquila', 'No sequences yet.') }}
					</p>
					<ul class="seq-list__items">
						<li v-for="s in mine" :key="s.id" class="seq-list__item">
							<div class="seq-list__text">
								<span class="seq-list__title">{{ s.title }} <code>seq_my_{{ s.slug }}</code></span>
								<span v-if="s.description" class="seq-list__description">{{ s.description }}</span>
								<span v-if="!s.enabled" class="seq-list__description">{{ t('aiquila', 'Disabled') }}</span>
							</div>
							<NcButton type="tertiary" :aria-label="t('aiquila', 'Edit')" @click="startEdit(s)">
								<template #icon>
									<PencilOutlineIcon :size="20" />
								</template>
							</NcButton>
							<NcButton type="tertiary" :aria-label="t('aiquila', 'Delete')" @click="remove(s)">
								<template #icon>
									<DeleteIcon :size="20" />
								</template>
							</NcButton>
						</li>
					</ul>
					<SequenceEditor v-if="editing"
						:key="editKey"
						:sequence="editing.sequence"
						:catalog="catalog"
						scope="user"
						:max-steps="policy.maxSteps"
						:saving="saving"
						:error="saveError"
						@save="save"
						@cancel="cancel" />
					<NcButton v-else
						type="secondary"
						:disabled="mine.length >= policy.maxPerUser"
						@click="startEdit(null)">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('aiquila', 'Add sequence') }}
					</NcButton>
				</template>
			</template>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import SequenceEditor from './SequenceEditor.vue'
import { sequencesApi } from '../../settings-api.js'

let editKey = 0

function message(err) {
	return err.response?.data?.error || err.message
}

export default {
	name: 'SequenceList',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		DeleteIcon,
		PencilOutlineIcon,
		PlusIcon,
		SequenceEditor,
	},
	props: {
		/** 'user' (personal page) or 'admin' */
		scope: {
			type: String,
			default: 'user',
		},
	},
	data() {
		return {
			api: sequencesApi(this.scope),
			loading: true,
			error: '',
			sequences: [],
			builtins: [],
			policy: { allowPersonal: true, maxPerUser: 50, maxSteps: 20, maxPinned: 10 },
			policyDraft: {},
			catalog: { tools: [] },
			/** `{sequence}` being edited; sequence null for a new one. */
			editing: null,
			editKey: 0,
			saving: false,
			saveError: '',
		}
	},
	computed: {
		admin() {
			return this.scope === 'admin'
		},
		organization() {
			return this.sequences.filter(s => s.scope === 'instance')
		},
		mine() {
			return this.sequences.filter(s => s.scope === 'user')
		},
		policyFields() {
			return [
				{ key: 'maxPerUser', label: t('aiquila', 'Sequences per user') },
				{ key: 'maxSteps', label: t('aiquila', 'Steps per sequence') },
				{ key: 'maxPinned', label: t('aiquila', 'Personal sequences shown as their own tool') },
			]
		},
		policyDirty() {
			return Object.keys(this.policyDraft).length > 0
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
				const [{ data }, { data: catalog }] = await Promise.all([this.api.list(), this.api.catalog()])
				this.sequences = data.sequences
				this.builtins = data.builtins
				this.policy = data.policy
				this.catalog = catalog
			} catch (err) {
				this.error = message(err)
			} finally {
				this.loading = false
			}
		},
		async refresh() {
			const { data } = await this.api.list()
			this.sequences = data.sequences
			this.builtins = data.builtins
			this.policy = data.policy
		},
		startEdit(sequence) {
			this.saveError = ''
			this.editKey = ++editKey
			this.editing = { sequence }
		},
		duplicate(sequence) {
			this.startEdit({ ...sequence, id: null, slug: sequence.slug.replace(/^my[_-]/, '') })
		},
		cancel() {
			this.editing = null
		},
		async save(payload) {
			this.saving = true
			this.saveError = ''
			try {
				const id = this.editing.sequence?.id
				if (id) {
					await this.api.update(id, payload)
				} else {
					await this.api.create(payload)
				}
				this.editing = null
				await this.refresh()
			} catch (err) {
				this.saveError = message(err)
			} finally {
				this.saving = false
			}
		},
		async remove(sequence) {
			this.error = ''
			try {
				await this.api.remove(sequence.id)
				this.sequences = this.sequences.filter(s => s.id !== sequence.id)
			} catch (err) {
				this.error = message(err)
			}
		},
		async setBuiltin(builtin, enabled) {
			this.error = ''
			try {
				await this.api.setBuiltin(builtin.slug, enabled)
				await this.refresh()
			} catch (err) {
				this.error = message(err)
			}
		},
		policyNumbers() {
			return Object.fromEntries(Object.entries(this.policyDraft).map(([k, v]) => [k, parseInt(v, 10)]))
		},
		async savePolicy(changes) {
			this.error = ''
			try {
				const { data } = await this.api.savePolicy(changes)
				this.policy = data.policy
				this.builtins = data.builtins
				this.policyDraft = {}
			} catch (err) {
				this.error = message(err)
			}
		},
	},
}
</script>

<style scoped>
.seq-list {
	max-width: 900px;
}

.seq-list h3 {
	margin-top: 16px;
}

.seq-list__policy {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	max-width: 640px;
}

.seq-list__items {
	margin-bottom: 12px;
}

.seq-list__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.seq-list__text {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.seq-list__title {
	font-weight: 600;
}

.seq-list__description,
.seq-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
