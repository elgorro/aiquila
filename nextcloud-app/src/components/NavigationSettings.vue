<template>
	<div class="nav-settings">
		<div class="setting-group">
			<label for="settings-model">{{ t('aiquila', 'Model preference') }}</label>
			<NcSelect v-model="selectedModel"
				input-id="settings-model"
				:options="modelOptions"
				:placeholder="t('aiquila', '(admin default)')"
				:clearable="true"
				@input="dirty = true" />
		</div>

		<div class="setting-group">
			<label for="settings-api-key">
				{{ t('aiquila', 'Personal API key') }}
				<span class="hint">{{ t('aiquila', '(overrides admin key)') }}</span>
			</label>
			<div class="key-row">
				<NcTextField id="settings-api-key"
					v-model="apiKey"
					type="password"
					:placeholder="keyPlaceholder"
					@update:model-value="dirty = true" />
				<NcButton type="tertiary" @click="onClearKey">
					{{ t('aiquila', 'Clear key') }}
				</NcButton>
			</div>
		</div>

		<div class="setting-actions">
			<NcButton type="secondary" @click="onCancel">
				{{ t('aiquila', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !dirty" @click="onSave">
				{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
			</NcButton>
		</div>

		<p v-if="status" :class="['status-msg', statusType]">
			{{ status }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { getSettings, saveSettings } from '../api.js'

export default {
	name: 'NavigationSettings',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			modelOptions: [],
			selectedModel: null,
			apiKey: '',
			hasUserKey: false,
			clearKey: false,
			saving: false,
			dirty: false,
			status: '',
			statusType: '',
			loaded: false,
		}
	},
	computed: {
		keyPlaceholder() {
			if (this.clearKey) return t('aiquila', 'Key will be cleared on save')
			if (this.hasUserKey) return t('aiquila', 'Personal key configured — enter new to replace')
			return t('aiquila', 'sk-ant-… (leave blank to keep admin key)')
		},
	},
	async mounted() {
		await this.loadSettings()
	},
	methods: {
		t,
		async loadSettings() {
			try {
				const { data } = await getSettings()
				this.modelOptions = (data.availableModels || [])
				this.selectedModel = data.userModel || null
				this.hasUserKey = !!data.hasUserKey
				this.loaded = true
			} catch (err) {
				this.status = t('aiquila', 'Failed to load settings: ') + err.message
				this.statusType = 'error'
			}
		},
		onClearKey() {
			this.apiKey = ''
			this.clearKey = true
			this.dirty = true
		},
		onCancel() {
			this.apiKey = ''
			this.clearKey = false
			this.dirty = false
			this.status = ''
		},
		async onSave() {
			this.saving = true
			this.status = ''
			try {
				await saveSettings({
					model: this.selectedModel || '',
					api_key: this.clearKey ? '' : this.apiKey,
				})
				this.status = t('aiquila', 'Saved!')
				this.statusType = 'success'
				this.dirty = false
				this.apiKey = ''
				this.clearKey = false
				if (this.clearKey) {
					this.hasUserKey = false
				} else if (this.apiKey) {
					this.hasUserKey = true
				}
			} catch (err) {
				this.status = t('aiquila', 'Error saving: ') + err.message
				this.statusType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.nav-settings {
	padding: 8px;
}

.setting-group {
	margin-bottom: 12px;
}

.setting-group label {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	font-weight: 600;
}

.setting-group .hint {
	font-weight: normal;
	opacity: 0.6;
}

.key-row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.key-row .v-select,
.key-row input {
	flex: 1;
}

.setting-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
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
</style>
