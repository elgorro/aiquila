<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="provider-card" :class="{ 'provider-card--default': isDefault, 'provider-card--open': open }">
		<div class="provider-card__head">
			<div class="provider-card__identity">
				<NcCheckboxRadioSwitch :model-value="defaultProvider"
					:value="provider.id"
					:name="`aiquila-default-provider-${scope}`"
					type="radio"
					@update:model-value="$emit('make-default', provider.id)">
					<span class="provider-card__name">{{ provider.label }}</span>
				</NcCheckboxRadioSwitch>
			</div>

			<NcButton type="tertiary"
				:aria-expanded="open ? 'true' : 'false'"
				@click="open = !open">
				{{ open ? t('aiquila', 'Close') : t('aiquila', 'Configure') }}
			</NcButton>
		</div>

		<div class="provider-card__summary">
			<span class="provider-card__status" :class="`provider-card__status--${status.kind}`">
				{{ status.label }}
			</span>
			<span v-if="provider.currentModel" class="provider-card__model">{{ provider.currentModel }}</span>
		</div>

		<!--
			Capability chips make the cost of switching provider legible before
			you switch: losing vision or tool use mid-project is otherwise only
			discoverable by hitting an error.
		-->
		<ul v-if="capabilityChips.length" class="provider-card__caps">
			<li v-for="cap in capabilityChips" :key="cap" class="provider-card__cap">{{ cap }}</li>
		</ul>

		<div v-if="open" class="provider-card__body">
			<slot name="prefix" />

			<SchemaField v-for="field in basicFields"
				:key="field.id"
				:field="field"
				:provider-id="provider.id"
				:user-scope="scope === 'user'"
				:model-value="draft[field.id]"
				@update:model-value="v => setValue(field.id, v)" />

			<details v-if="advancedFields.length" class="provider-card__advanced">
				<summary>{{ t('aiquila', 'Advanced') }}</summary>
				<SchemaField v-for="field in advancedFields"
					:key="field.id"
					:field="field"
					:provider-id="provider.id"
					:user-scope="scope === 'user'"
					:model-value="draft[field.id]"
					@update:model-value="v => setValue(field.id, v)" />
			</details>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<div class="provider-card__actions">
				<NcButton type="primary" :disabled="saving || !dirty" @click="save">
					{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
				</NcButton>
				<NcButton v-if="scope === 'admin'" type="secondary" :disabled="testing" @click="test">
					{{ testing ? t('aiquila', 'Testing…') : t('aiquila', 'Test connection') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="refreshing" @click="$emit('refresh-models')">
					{{ refreshing ? t('aiquila', 'Refreshing…') : t('aiquila', 'Refresh models') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import SchemaField from './SchemaField.vue'
import { saveAdminProvider, saveUserProvider, testProvider } from '../../settings-api.js'

/** Capability flag → chip label. Flags absent from here are not worth a chip. */
const CAPABILITY_LABELS = {
	vision: 'vision',
	tools: 'tools',
	thinking: 'thinking',
	effort: 'effort',
	native_mcp: 'native MCP',
	documents: 'documents',
}

export default {
	name: 'ProviderCard',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		SchemaField,
	},
	props: {
		/** A provider description from /api/providers or /api/admin/providers. */
		provider: {
			type: Object,
			required: true,
		},
		/** 'admin' writes instance config, 'user' writes personal overrides. */
		scope: {
			type: String,
			required: true,
		},
		defaultProvider: {
			type: String,
			default: '',
		},
		refreshing: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['make-default', 'refresh-models', 'saved'],
	data() {
		return {
			open: false,
			saving: false,
			testing: false,
			dirty: false,
			message: '',
			messageType: 'success',
			draft: {},
		}
	},
	computed: {
		isDefault() {
			return this.provider.id === this.defaultProvider
		},
		fields() {
			return this.provider.fields || []
		},
		basicFields() {
			return this.fields.filter(f => (f.group || 'basic') === 'basic')
		},
		advancedFields() {
			return this.fields.filter(f => f.group === 'advanced')
		},
		capabilityChips() {
			const caps = this.provider.capabilities || {}
			return Object.keys(CAPABILITY_LABELS)
				.filter(key => caps[key])
				.map(key => CAPABILITY_LABELS[key])
		},
		/**
		 * The personal page cares whether *this user* can talk to the provider,
		 * so an inherited instance key reads differently from a personal one.
		 */
		status() {
			if (!this.provider.configured) {
				return { kind: 'unconfigured', label: t('aiquila', 'Not configured') }
			}
			if (this.isDefault) {
				return { kind: 'default', label: t('aiquila', 'Default') }
			}
			if (this.scope === 'user' && !this.provider.hasUserKey) {
				return { kind: 'inherited', label: t('aiquila', 'Using the instance key') }
			}
			return { kind: 'ready', label: t('aiquila', 'Ready') }
		},
	},
	watch: {
		// A refresh replaces the provider object; discard any stale draft with it.
		provider: {
			immediate: true,
			handler() {
				this.resetDraft()
			},
		},
	},
	methods: {
		t,
		resetDraft() {
			const draft = {}
			for (const field of this.fields) {
				// Sensitive fields never carry a value from the server; an empty
				// box means "leave the stored key alone", so they start blank
				// and are only submitted when the admin types something.
				if (field.sensitive) {
					draft[field.id] = ''
				} else if (field.type === 'multiselect') {
					// A list field is always a list, so an untouched card submits
					// the same shape the backend validates.
					draft[field.id] = Array.isArray(field.value) ? [...field.value] : []
				} else {
					draft[field.id] = field.value ?? ''
				}
			}
			this.draft = draft
			this.dirty = false
			this.message = ''
		},
		setValue(id, value) {
			this.draft = { ...this.draft, [id]: value }
			this.dirty = true
		},
		/** Only send what the user actually touched, so blanks don't wipe keys. */
		payload() {
			const values = {}
			for (const field of this.fields) {
				const value = this.draft[field.id]
				if (field.sensitive && value === '') {
					continue
				}
				values[field.id] = value
			}
			return values
		},
		async save() {
			this.saving = true
			this.message = ''
			try {
				const call = this.scope === 'admin' ? saveAdminProvider : saveUserProvider
				const { data } = await call(this.provider.id, this.payload())
				this.dirty = false
				if (data.rejected?.length) {
					this.messageType = 'warning'
					this.message = t('aiquila', 'Saved, but these settings are not yours to change: {fields}', {
						fields: data.rejected.join(', '),
					})
				} else {
					this.messageType = 'success'
					this.message = t('aiquila', 'Saved.')
				}
				this.$emit('saved')
			} catch (err) {
				this.messageType = 'error'
				this.message = err.response?.data?.message || err.message
			} finally {
				this.saving = false
			}
		},
		/** Tests the typed key when there is one, so it can be checked before saving. */
		async test() {
			this.testing = true
			this.message = ''
			try {
				const { data } = await testProvider(this.provider.id, this.draft.api_key || '')
				this.messageType = data.success ? 'success' : 'error'
				this.message = data.success
					? t('aiquila', 'Connection works. The provider answered: {response}', { response: data.message })
					: data.message
			} catch (err) {
				this.messageType = 'error'
				this.message = err.response?.data?.message || err.message
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped>
.provider-card {
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px 16px;
	background-color: var(--color-main-background);
}

.provider-card--default {
	border-color: var(--color-primary-element);
}

.provider-card--open {
	grid-column: 1 / -1;
}

.provider-card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.provider-card__name {
	font-weight: 600;
}

.provider-card__summary {
	display: flex;
	align-items: baseline;
	gap: 8px;
	margin: 4px 0 0 4px;
	font-size: 0.9em;
}

.provider-card__status--default {
	color: var(--color-primary-element);
	font-weight: 600;
}

.provider-card__status--ready {
	color: var(--color-success);
}

.provider-card__status--inherited,
.provider-card__status--unconfigured {
	color: var(--color-text-maxcontrast);
}

.provider-card__model {
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
}

.provider-card__caps {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 8px 0 0 4px;
	padding: 0;
	list-style: none;
}

.provider-card__cap {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.provider-card__body {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.provider-card__advanced {
	margin-bottom: 16px;
}

.provider-card__advanced summary {
	cursor: pointer;
	padding: 8px 0;
	font-weight: 600;
}

.provider-card__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}
</style>
