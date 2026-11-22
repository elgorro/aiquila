<template>
	<fieldset class="extract-fields">
		<legend>{{ t('aiquila', 'Fields to extract') }}</legend>
		<div v-for="(field, i) in modelValue" :key="i" class="extract-field">
			<input :value="field.name"
				type="text"
				class="name"
				:aria-label="t('aiquila', 'Field name')"
				:placeholder="t('aiquila', 'e.g. total')"
				:class="{ invalid: field.name !== '' && !nameValid(field.name, i) }"
				@input="update(i, { name: $event.target.value })">
			<select :value="field.type"
				:aria-label="t('aiquila', 'Field type')"
				@change="update(i, { type: $event.target.value })">
				<option v-for="type in types" :key="type.id" :value="type.id">
					{{ type.label }}
				</option>
			</select>
			<input :value="field.description"
				type="text"
				class="description"
				maxlength="300"
				:aria-label="t('aiquila', 'What to look for')"
				:placeholder="t('aiquila', 'What to look for (optional)')"
				@input="update(i, { description: $event.target.value })">
			<button type="button"
				class="remove"
				:aria-label="t('aiquila', 'Remove field')"
				:disabled="modelValue.length === 1"
				@click="remove(i)">
				×
			</button>
		</div>
		<button type="button" :disabled="modelValue.length >= maxFields" @click="add">
			{{ t('aiquila', 'Add field') }}
		</button>
		<small>{{ t('aiquila', 'Names use lowercase letters, digits and underscores. Each document becomes one row; a field it does not contain stays empty.') }}</small>
	</fieldset>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

// Mirrors ExtractOperation::validateOptions(); the server decides.
const NAME = /^[a-z][a-z0-9_]{0,39}$/
const RESERVED = ['file_id', 'file', 'extracted_at']

/** True when a field list is one the server accepts. */
export function fieldsValid(fields) {
	if (!Array.isArray(fields) || fields.length === 0 || fields.length > 30) {
		return false
	}
	const names = fields.map(f => f.name)
	return fields.every(f => NAME.test(f.name) && !RESERVED.includes(f.name))
		&& new Set(names).size === names.length
}

export default {
	name: 'ExtractFieldsEditor',

	props: {
		/** [{ name, type, description }] */
		modelValue: {
			type: Array,
			required: true,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { maxFields: 30 }
	},

	computed: {
		types() {
			return [
				{ id: 'string', label: t('aiquila', 'Text') },
				{ id: 'number', label: t('aiquila', 'Number') },
				{ id: 'integer', label: t('aiquila', 'Whole number') },
				{ id: 'date', label: t('aiquila', 'Date') },
				{ id: 'boolean', label: t('aiquila', 'Yes/no') },
			]
		},
	},

	methods: {
		t,
		nameValid(name, index) {
			return NAME.test(name) && !RESERVED.includes(name)
				&& this.modelValue.findIndex(f => f.name === name) === index
		},
		update(index, patch) {
			this.$emit('update:modelValue', this.modelValue.map((f, i) => i === index ? { ...f, ...patch } : f))
		},
		add() {
			this.$emit('update:modelValue', [...this.modelValue, { name: '', type: 'string', description: '' }])
		},
		remove(index) {
			this.$emit('update:modelValue', this.modelValue.filter((_, i) => i !== index))
		},
	},
}
</script>

<style scoped>
.extract-fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.extract-field {
	display: flex;
	gap: 6px;
	align-items: center;
}

.extract-field .name {
	width: 9em;
}

.extract-field .description {
	flex: 1;
	min-width: 0;
}

.extract-field .invalid {
	border-color: var(--color-error);
}
</style>
