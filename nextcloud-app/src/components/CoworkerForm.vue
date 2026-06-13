<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="coworker-form">
		<h3>{{ model.id ? t('aiquila', 'Edit coworker') : t('aiquila', 'New coworker') }}</h3>

		<label class="field">
			<span>{{ t('aiquila', 'Title') }}</span>
			<input v-model="form.title" type="text" :placeholder="t('aiquila', 'Classify my photos')">
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Task') }}</span>
			<select v-model="form.task_type">
				<option v-for="tt in taskTypes" :key="tt.id" :value="tt.id">{{ tt.label }}</option>
			</select>
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Provider') }}</span>
			<select v-model="form.model">
				<option value="anthropic">{{ t('aiquila', 'Claude vision') }}</option>
				<option value="mistral">{{ t('aiquila', 'Mistral Pixtral') }}</option>
			</select>
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Input folder') }}</span>
			<input v-model="form.input_path" type="text" placeholder="/Photos">
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Schedule (cron)') }}</span>
			<input v-model="form.cron_schedule" type="text" placeholder="0 3 * * *">
			<small>{{ t('aiquila', 'min hour day-of-month month day-of-week. Example: 0 3 * * * = nightly at 03:00.') }}</small>
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Max tags per image') }}</span>
			<input v-model.number="form.maxTags" type="number" min="1" max="30">
		</label>

		<label class="field checkbox">
			<input v-model="form.recursive" type="checkbox">
			<span>{{ t('aiquila', 'Include sub-folders') }}</span>
		</label>

		<label class="field checkbox">
			<input v-model="form.is_active" type="checkbox">
			<span>{{ t('aiquila', 'Enabled') }}</span>
		</label>

		<p v-if="error" class="form-error">{{ error }}</p>

		<div class="actions">
			<button class="primary" :disabled="saving" @click="save">{{ t('aiquila', 'Save') }}</button>
			<button :disabled="saving" @click="$emit('cancel')">{{ t('aiquila', 'Cancel') }}</button>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { createCoworker, updateCoworker } from '../api.js'

export default {
	name: 'CoworkerForm',
	props: {
		model: { type: Object, default: () => ({}) },
		taskTypes: { type: Array, default: () => [] },
	},
	emits: ['saved', 'cancel'],
	data() {
		const m = this.model || {}
		let options = {}
		if (m.options) {
			try { options = typeof m.options === 'string' ? JSON.parse(m.options) : m.options } catch (e) { options = {} }
		}
		return {
			saving: false,
			error: '',
			form: {
				title: m.title || '',
				task_type: m.taskType || (this.taskTypes[0] && this.taskTypes[0].id) || 'vision:classify',
				model: m.model || 'anthropic',
				input_path: m.inputPath || '/Photos',
				cron_schedule: m.cronSchedule || '0 3 * * *',
				maxTags: options.maxTags || 8,
				recursive: options.recursive !== false,
				is_active: m.isActive !== undefined ? !!m.isActive : true,
			},
		}
	},
	methods: { t,
		async save() {
			this.saving = true
			this.error = ''
			const payload = {
				title: this.form.title,
				task_type: this.form.task_type,
				model: this.form.model,
				input_type: 'folder',
				input_path: this.form.input_path,
				output_type: 'system_tags',
				cron_schedule: this.form.cron_schedule,
				is_active: this.form.is_active,
				options: { maxTags: this.form.maxTags, recursive: this.form.recursive },
			}
			try {
				const res = this.model && this.model.id
					? await updateCoworker(this.model.id, payload)
					: await createCoworker(payload)
				this.$emit('saved', res.data)
			} catch (e) {
				this.error = e?.response?.data?.error || t('aiquila', 'Could not save coworker')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.coworker-form {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	max-width: 520px;
}
.coworker-form h3 { margin: 0 0 12px; font-size: 16px; }
.field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; font-size: 13px; }
.field > span { font-weight: 600; }
.field input[type="text"], .field input[type="number"], .field select {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.field small { color: var(--color-text-lighter); }
.field.checkbox { flex-direction: row; align-items: center; gap: 8px; }
.form-error { color: var(--color-error); font-size: 13px; }
.actions { display: flex; gap: 8px; margin-top: 8px; }
</style>
