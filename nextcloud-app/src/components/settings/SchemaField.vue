<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="schema-field">
		<!--
			One renderer for every provider field. The backend describes type,
			scope, options and current value, so adding a provider needs no
			change here — which is the whole point of the schema.
		-->
		<NcCheckboxRadioSwitch v-if="field.type === 'checkbox'"
			:model-value="!!modelValue"
			@update:model-value="emit">
			{{ field.title }}
		</NcCheckboxRadioSwitch>

		<template v-else>
			<label class="schema-field__label" :for="inputId">{{ field.title }}</label>

			<!--
				Principal picker for the per-provider access lists. There is no
				fixed option list — the backend answers as the admin types — so
				this branch owns its own async search, unlike the plain select
				whose options arrive with the schema.
			-->
			<NcSelect v-if="field.type === 'multiselect'"
				:model-value="selectedPrincipals"
				:input-id="inputId"
				:options="principalOptions"
				:placeholder="field.placeholder || t('aiquila', 'Search users and groups…')"
				:loading="principalsLoading"
				:multiple="true"
				:close-on-select="false"
				label="label"
				@search="onPrincipalSearch"
				@update:model-value="emitPrincipals" />

			<NcSelect v-else-if="field.type === 'select'"
				:model-value="modelValue || null"
				:input-id="inputId"
				:options="field.options || []"
				:placeholder="selectPlaceholder"
				:clearable="clearable"
				@update:model-value="emit" />

			<NcPasswordField v-else-if="field.type === 'password'"
				:model-value="modelValue || ''"
				:label="field.title"
				:label-visible="false"
				:placeholder="passwordPlaceholder"
				@update:model-value="emit" />

			<!--
				Multi-line secret (the local provider's extra request headers).
				Like the password field it never receives a value from the
				server, so the placeholder is what tells the admin whether one
				is already stored.
			-->
			<NcTextArea v-else-if="field.type === 'textarea'"
				:id="inputId"
				:model-value="stringValue"
				:label="field.title"
				:label-visible="false"
				:rows="4"
				:placeholder="textareaPlaceholder"
				@update:model-value="emit" />

			<NcTextField v-else
				:id="inputId"
				:model-value="stringValue"
				:label="field.title"
				:label-visible="false"
				:type="field.type === 'number' ? 'number' : 'text'"
				:placeholder="textPlaceholder"
				@update:model-value="emit" />
		</template>

		<p v-if="field.description" class="schema-field__hint">
			{{ field.description }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { searchPrincipals } from '../../settings-api.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'SchemaField',
	components: {
		NcCheckboxRadioSwitch,
		NcPasswordField,
		NcSelect,
		NcTextArea,
		NcTextField,
	},
	props: {
		/** A field descriptor from the provider settings schema. */
		field: {
			type: Object,
			required: true,
		},
		modelValue: {
			type: [String, Number, Boolean, Array, null],
			default: '',
		},
		/** Provider id, used only to keep input ids unique across cards. */
		providerId: {
			type: String,
			required: true,
		},
		/** Personal page: an empty value means "inherit the instance default". */
		userScope: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:modelValue'],
	data() {
		return {
			/** Options for the principal picker, refreshed as the admin types. */
			principalOptions: [],
			principalsLoading: false,
			/** Labels learned from earlier searches, so stored ids render as names. */
			principalLabels: {},
			searchTimer: null,
		}
	},
	computed: {
		inputId() {
			return `aiquila-${this.providerId}-${this.field.id}`
		},
		/**
		 * NcSelect works in option objects; the field's value is a list of ids.
		 * An id whose display name we have not seen yet renders as the id, which
		 * is still unambiguous.
		 */
		selectedPrincipals() {
			const ids = Array.isArray(this.modelValue) ? this.modelValue : []
			return ids.map((id) => ({ id, label: this.principalLabels[id] || id }))
		},
		stringValue() {
			return this.modelValue === null || this.modelValue === undefined ? '' : String(this.modelValue)
		},
		/** Clearing a user field is how you go back to inheriting. */
		clearable() {
			return this.userScope
		},
		inheritedHint() {
			const inherited = this.field.inherited
			return inherited === '' || inherited === null || inherited === undefined
				? t('aiquila', '(instance default)')
				: t('aiquila', '(instance default: {value})', { value: String(inherited) })
		},
		selectPlaceholder() {
			return this.userScope ? this.inheritedHint : (this.field.placeholder || '')
		},
		textPlaceholder() {
			return this.userScope ? this.inheritedHint : (this.field.placeholder || '')
		},
		textareaPlaceholder() {
			if (this.field.sensitive) {
				return this.field.hasValue
					? t('aiquila', 'Configured — enter new lines to replace them')
					: (this.field.placeholder || '')
			}
			return this.field.placeholder || ''
		},
		passwordPlaceholder() {
			if (this.field.hasValue) {
				return this.userScope
					? t('aiquila', 'Personal key configured — enter a new one to replace it')
					: t('aiquila', 'Key configured — enter a new one to replace it')
			}
			if (this.userScope) {
				return t('aiquila', 'Leave blank to use the instance key')
			}
			return this.field.optional
				? t('aiquila', 'Optional — leave blank if the endpoint needs no auth')
				: t('aiquila', 'Enter an API key…')
		},
	},
	methods: {
		t,
		emit(value) {
			this.$emit('update:modelValue', value)
		},
		/** Emit ids, not option objects — that is what the API stores. */
		emitPrincipals(options) {
			const list = (options || []).map((option) => {
				if (option && typeof option === 'object') {
					this.principalLabels[option.id] = option.label || option.id
					return option.id
				}
				return option
			})
			this.$emit('update:modelValue', list)
		},
		/**
		 * Debounced so a burst of keystrokes makes one request. The field's
		 * `principal_type` decides which half of the answer is offered, so a
		 * "blocked groups" picker never lists users.
		 */
		onPrincipalSearch(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.loadPrincipals(query), 300)
		},
		async loadPrincipals(query) {
			this.principalsLoading = true
			try {
				const { data } = await searchPrincipals(query || '')
				const wanted = this.field.principal_type === 'group' ? data.groups : data.users
				this.principalOptions = wanted || []
				this.principalOptions.forEach((option) => {
					this.principalLabels[option.id] = option.label || option.id
				})
			} catch (e) {
				this.principalOptions = []
			} finally {
				this.principalsLoading = false
			}
		},
	},
	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},
}
</script>

<style scoped>
.schema-field {
	margin-bottom: 16px;
}

.schema-field__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.schema-field__hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	line-height: 1.4;
}
</style>
