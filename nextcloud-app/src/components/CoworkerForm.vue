<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="coworker-form">
		<h3>{{ model.id ? t('aiquila', 'Edit coworker') : t('aiquila', 'New coworker') }}</h3>

		<label class="field">
			<span>{{ t('aiquila', 'Title') }}</span>
			<input v-model="form.title" type="text" :placeholder="t('aiquila', 'Classify my photos')">
		</label>

		<label v-if="teams.length > 0 && !model.id" class="field">
			<span>{{ t('aiquila', 'Owner') }}</span>
			<select v-model="form.teamId">
				<option value="">{{ t('aiquila', 'Just me') }}</option>
				<option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
			</select>
			<small v-if="form.teamId">{{ t('aiquila', 'All members can see and run it. It runs as you, or as the team owner or an admin if you leave the team, and is deleted with the team. Use paths everyone has, such as the team folder.') }}</small>
		</label>
		<p v-else-if="model.teamId" class="muted">{{ t('aiquila', 'Owned by a team. Runs as {user}.', { user: model.userId }) }}</p>

		<label class="field">
			<span>{{ t('aiquila', 'Task') }}</span>
			<select v-model="form.task_type">
				<option v-for="tt in taskTypes" :key="tt.id" :value="tt.id">{{ tt.label }}</option>
			</select>
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Provider') }}</span>
			<select v-model="form.provider">
				<option value="anthropic">{{ t('aiquila', 'Claude') }}</option>
				<option value="mistral">{{ t('aiquila', 'Mistral') }}</option>
			</select>
		</label>

		<!--
			Claude only: Mistral's effort vocabulary differs and it has no
			thinking switch. Blank everywhere means "use the background-task
			defaults from the admin settings".
		-->
		<template v-if="isClaude">
			<label class="field">
				<span>{{ t('aiquila', 'Model') }}</span>
				<input v-model="form.model" type="text" :placeholder="t('aiquila', 'Leave empty for the default model')">
			</label>

			<label class="field">
				<span>{{ t('aiquila', 'Effort') }}</span>
				<select v-model="form.effort">
					<option value="">{{ t('aiquila', 'Default') }}</option>
					<option v-for="e in efforts" :key="e" :value="e">{{ e }}</option>
				</select>
				<small>{{ t('aiquila', 'Routine work rarely needs more than "low" or "medium".') }}</small>
			</label>

			<label class="field">
				<span>{{ t('aiquila', 'Thinking') }}</span>
				<select v-model="form.thinking">
					<option value="">{{ t('aiquila', 'Default') }}</option>
					<option value="auto">{{ t('aiquila', 'Follow the model') }}</option>
					<option value="on">{{ t('aiquila', 'On') }}</option>
					<option value="off">{{ t('aiquila', 'Off (where the model allows it)') }}</option>
				</select>
			</label>
		</template>

		<template v-if="isAgent">
			<label class="field">
				<span>{{ t('aiquila', 'Prompt') }}</span>
				<textarea v-model="form.prompt" rows="5" :placeholder="t('aiquila', 'Summarise my unread mail as a short list, urgent first.')" />
			</label>

			<fieldset class="field">
				<legend>{{ t('aiquila', 'Tools it may use') }}</legend>
				<small>{{ t('aiquila', 'Runs are unattended: allow only what the job needs, read-only where possible. None runs the prompt without tools.') }}</small>
				<p v-if="toolsLoading" class="muted">{{ t('aiquila', 'Loading…') }}</p>
				<p v-else-if="availableTools.length === 0 && form.tools.length === 0" class="muted">{{ t('aiquila', 'No MCP tools are configured.') }}</p>
				<label v-for="tool in toolChoices" :key="tool.name" class="checkbox" :title="tool.description">
					<input v-model="form.tools" type="checkbox" :value="tool.name">
					<span>{{ tool.name }}</span>
				</label>
			</fieldset>

			<label class="field">
				<span>{{ t('aiquila', 'Save the answer to') }}</span>
				<input v-model="form.output_path" type="text" :placeholder="t('aiquila', 'Leave empty to keep it in the run history only')">
			</label>
		</template>

		<label v-else class="field">
			<span>{{ t('aiquila', 'Input folder') }}</span>
			<input v-model="form.input_path" type="text" :placeholder="defaultInputPath">
		</label>

		<label class="field">
			<span>{{ t('aiquila', 'Schedule (cron)') }}</span>
			<input v-model="form.cron_schedule" type="text" placeholder="0 3 * * *">
			<small>{{ t('aiquila', 'min hour day-of-month month day-of-week. Example: 0 3 * * * = nightly at 03:00.') }}</small>
		</label>

		<label v-if="isVision" class="field">
			<span>{{ t('aiquila', 'Max tags per image') }}</span>
			<input v-model.number="form.maxTags" type="number" min="1" max="30">
		</label>

		<label v-if="isDocs" class="field">
			<span>{{ t('aiquila', 'Output folder') }}</span>
			<input v-model="form.output_path" type="text" :placeholder="t('aiquila', 'Leave empty to write beside each document')">
		</label>

		<label v-if="isTranslate" class="field">
			<span>{{ t('aiquila', 'Translate into') }}</span>
			<input v-model="form.targetLanguage" type="text" :placeholder="t('aiquila', 'German')">
		</label>

		<label v-if="isSummarize" class="field">
			<span>{{ t('aiquila', 'Summary style') }}</span>
			<select v-model="form.style">
				<option value="brief">{{ t('aiquila', 'Brief') }}</option>
				<option value="detailed">{{ t('aiquila', 'Detailed') }}</option>
				<option value="bullets">{{ t('aiquila', 'Bullet points') }}</option>
			</select>
		</label>

		<label v-if="!isAgent" class="field checkbox">
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
import { createCoworker, getTeams, listCoworkerTools, updateCoworker } from '../api.js'

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
			availableTools: [],
			toolsLoading: false,
			form: {
				prompt: m.customPrompt || '',
				tools: Array.isArray(options.tools) ? options.tools : [],
				title: m.title || '',
				task_type: m.taskType || (this.taskTypes[0] && this.taskTypes[0].id) || 'vision:classify',
				provider: m.provider || 'anthropic',
				input_path: m.inputPath || '',
				output_path: m.outputPath || '',
				cron_schedule: m.cronSchedule || '0 3 * * *',
				maxTags: options.maxTags || 8,
				targetLanguage: options.targetLanguage || '',
				style: options.style || 'brief',
				recursive: options.recursive !== false,
				model: m.model || '',
				effort: options.effort || '',
				thinking: options.thinking || '',
				is_active: m.isActive !== undefined ? !!m.isActive : true,
				teamId: m.teamId || '',
			},
			teams: [],
		}
	},
	computed: {
		// The form serves two task families with different shapes; the task
		// picker decides which fields mean anything.
		isVision() {
			return this.form.task_type.startsWith('vision:')
		},
		isDocs() {
			return this.form.task_type.startsWith('docs:')
		},
		isSummarize() {
			return this.form.task_type === 'docs:summarize'
		},
		isTranslate() {
			return this.form.task_type === 'docs:translate'
		},
		isAgent() {
			return this.form.task_type === 'agent:run'
		},
		// Allowed tools a server no longer offers stay listed, so saving
		// does not drop them silently.
		toolChoices() {
			const names = new Set(this.availableTools.map(tool => tool.name))
			return this.availableTools.concat(this.form.tools.filter(name => !names.has(name)).map(name => ({ name, description: '' })))
		},
		isClaude() {
			return this.form.provider === 'anthropic'
		},
		efforts() {
			return ['low', 'medium', 'high', 'xhigh', 'max']
		},
		defaultInputPath() {
			return this.isDocs ? '/Documents' : '/Photos'
		},
	},
	watch: {
		isAgent: { handler: 'loadTools', immediate: true },
	},
	async mounted() {
		try {
			this.teams = await getTeams()
		} catch (e) {
			// Without the Teams app a coworker is simply personal.
			this.teams = []
		}
	},
	methods: { t,
		async loadTools() {
			if (!this.isAgent || this.availableTools.length > 0) {
				return
			}
			this.toolsLoading = true
			try {
				this.availableTools = (await listCoworkerTools()).data
			} catch (e) {
				this.availableTools = []
			} finally {
				this.toolsLoading = false
			}
		},
		async save() {
			this.saving = true
			this.error = ''
			const options = this.isAgent ? { tools: this.form.tools } : { recursive: this.form.recursive }
			if (this.isVision) {
				options.maxTags = this.form.maxTags
			}
			if (this.isSummarize) {
				options.style = this.form.style
			}
			if (this.isTranslate) {
				options.targetLanguage = this.form.targetLanguage
			}
			if (this.isClaude && this.form.effort) {
				options.effort = this.form.effort
			}
			if (this.isClaude && this.form.thinking) {
				options.thinking = this.form.thinking
			}

			const payload = {
				title: this.form.title,
				task_type: this.form.task_type,
				provider: this.form.provider,
				input_type: this.isAgent ? 'none' : 'folder',
				input_path: this.isAgent ? null : (this.form.input_path || this.defaultInputPath),
				// Vision tasks write tags; docs tasks write files beside the
				// source; an agent writes its answer to one file, if asked.
				output_type: this.isAgent ? (this.form.output_path ? 'file' : 'none') : (this.isDocs ? 'files' : 'system_tags'),
				cron_schedule: this.form.cron_schedule,
				is_active: this.form.is_active,
				// A model id belongs to one provider; drop it when switching away.
				model: this.isClaude ? this.form.model : '',
				options,
			}
			if ((this.isDocs || this.isAgent) && this.form.output_path) {
				payload.output_path = this.form.output_path
			}
			if (this.isAgent) {
				payload.custom_prompt = this.form.prompt
			}
			// A coworker's team is set once, when it is created.
			if (!(this.model && this.model.id) && this.form.teamId) {
				payload.teamId = this.form.teamId
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
fieldset.field { border: none; padding: 0; margin: 0; }
fieldset.field legend { padding: 0; }
fieldset.field .checkbox { display: flex; align-items: center; gap: 8px; }
.muted { color: var(--color-text-lighter); font-size: 13px; }
.form-error { color: var(--color-error); font-size: 13px; }
.actions { display: flex; gap: 8px; margin-top: 8px; }
</style>
