<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="instructions">
		<label class="instructions__label" for="aiquila-instance-instructions">
			{{ t('aiquila', 'Instructions for every user') }}
		</label>
		<textarea id="aiquila-instance-instructions"
			v-model="text"
			class="instructions__textarea"
			rows="6"
			:maxlength="maxLength"
			:placeholder="t('aiquila', 'Organization name, language, conventions, policy…')"
			@input="dirty = true" />
		<p class="instructions__hint">
			{{ t('aiquila', '{user} and {locale} are replaced with the user\'s display name and locale. Users see these instructions on their personal settings page and cannot remove them.') }}
		</p>

		<p class="instructions__label">
			{{ t('aiquila', 'Apply to') }}
		</p>
		<NcCheckboxRadioSwitch v-for="scope in scopeOptions"
			:key="scope.id"
			:model-value="scopes[scope.id]"
			@update:model-value="v => { scopes[scope.id] = v; dirty = true }">
			{{ scope.label }}
		</NcCheckboxRadioSwitch>

		<div class="instructions__actions">
			<NcButton type="primary" :disabled="saving || !dirty" @click="save">
				{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import { getInstructions, saveInstructions } from '../../settings-api.js'

export default {
	name: 'InstanceInstructions',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},
	data() {
		return {
			text: '',
			scopes: { chat: true, talk: true, coworkers: false, tasks: false },
			maxLength: 20000,
			dirty: false,
			saving: false,
			message: '',
			messageType: 'success',
		}
	},
	computed: {
		scopeOptions() {
			return [
				{ id: 'chat', label: t('aiquila', 'AIquila chat') },
				{ id: 'talk', label: t('aiquila', 'The AIquila bot in Talk') },
				{ id: 'coworkers', label: t('aiquila', 'Coworkers') },
				{ id: 'tasks', label: t('aiquila', 'Assistant chat and Context Agent (TaskProcessing)') },
			]
		},
	},
	async mounted() {
		try {
			const { data } = await getInstructions()
			this.apply(data)
		} catch (err) {
			this.messageType = 'error'
			this.message = err.response?.data?.error || err.message
		}
	},
	methods: {
		t,
		apply(data) {
			this.text = data.instructions || ''
			this.scopes = { ...this.scopes, ...data.scopes }
			this.maxLength = data.maxLength || this.maxLength
			this.dirty = false
		},
		async save() {
			this.saving = true
			this.message = ''
			try {
				const { data } = await saveInstructions({ instructions: this.text, scopes: this.scopes })
				this.apply(data)
				this.messageType = 'success'
				this.message = t('aiquila', 'Saved.')
			} catch (err) {
				this.messageType = 'error'
				this.message = err.response?.data?.error || err.message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.instructions {
	max-width: 640px;
}

.instructions__label {
	display: block;
	margin: 12px 0 4px;
	font-weight: 600;
}

.instructions__textarea {
	width: 100%;
	resize: vertical;
}

.instructions__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.instructions__actions {
	margin-top: 12px;
}
</style>
