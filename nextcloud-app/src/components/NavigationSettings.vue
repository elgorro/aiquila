<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="nav-settings">
		<details class="settings-section" open>
			<summary>{{ t('aiquila', 'Model & API') }}</summary>
			<div class="section-content">
				<div v-if="providerOptions.length > 1" class="setting-group">
					<label for="settings-provider">{{ t('aiquila', 'AI provider') }}</label>
					<NcSelect v-model="selectedProvider"
						input-id="settings-provider"
						:options="providerOptions"
						:placeholder="t('aiquila', '(admin default)')"
						:clearable="false"
						@input="onProviderChange" />
				</div>

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
			</div>
		</details>

		<details class="settings-section">
			<summary>{{ t('aiquila', 'Defaults') }}</summary>
			<div class="section-content">
				<div class="setting-group">
					<label for="settings-system-prompt">{{ t('aiquila', 'Default system prompt') }}</label>
					<textarea id="settings-system-prompt"
						v-model="defaultSystemPrompt"
						class="system-prompt-input"
						rows="3"
						:placeholder="t('aiquila', 'Custom instructions for the model…')"
						@input="dirty = true" />
				</div>

				<div class="setting-group">
					<NcCheckboxRadioSwitch :checked="defaultVerbose"
						@update:checked="val => { defaultVerbose = val; dirty = true }">
						{{ t('aiquila', 'Show verbose mode by default') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</details>

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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

import { getSettings, saveSettings } from '../api.js'

export default {
	name: 'NavigationSettings',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcCheckboxRadioSwitch,
	},
	emits: [],
	data() {
		return {
			// providers: [{ id, label, configured, hasUserKey, userModel, availableModels }]
			providers: [],
			selectedProvider: null, // { id, label } option object
			// Per-provider chosen model, keyed by provider id.
			userModels: {},
			apiKey: '',
			clearKey: false,
			defaultSystemPrompt: '',
			defaultVerbose: false,
			saving: false,
			dirty: false,
			status: '',
			statusType: '',
			loaded: false,
		}
	},
	computed: {
		providerOptions() {
			return this.providers.map(p => ({ id: p.id, label: p.label }))
		},
		currentProvider() {
			const id = this.selectedProvider?.id
			return this.providers.find(p => p.id === id) || null
		},
		modelOptions() {
			return this.currentProvider?.availableModels || []
		},
		selectedModel: {
			get() {
				const id = this.selectedProvider?.id
				return id ? (this.userModels[id] || null) : null
			},
			set(val) {
				const id = this.selectedProvider?.id
				if (id) this.userModels = { ...this.userModels, [id]: val }
			},
		},
		currentHasUserKey() {
			return !!this.currentProvider?.hasUserKey
		},
		keyPlaceholder() {
			if (this.clearKey) return t('aiquila', 'Key will be cleared on save')
			if (this.currentHasUserKey) return t('aiquila', 'Personal key configured — enter new to replace')
			return t('aiquila', 'Enter a personal API key (leave blank to keep admin key)')
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
				this.providers = (data.providers || []).map(p => ({
					id: p.id,
					label: p.label,
					configured: !!p.configured,
					hasUserKey: !!p.hasUserKey,
					userModel: p.userModel || null,
					availableModels: p.availableModels || [],
				}))
				// Fallback for older backends without a providers array.
				if (this.providers.length === 0) {
					this.providers = [{
						id: data.provider || 'anthropic',
						label: data.provider || 'anthropic',
						configured: !!data.hasUserKey,
						hasUserKey: !!data.hasUserKey,
						userModel: data.userModel || null,
						availableModels: data.availableModels || [],
					}]
				}
				const activeId = data.provider || this.providers[0].id
				this.selectedProvider = this.providerOptions.find(o => o.id === activeId) || this.providerOptions[0]
				this.userModels = Object.fromEntries(this.providers.map(p => [p.id, p.userModel]))
				this.defaultSystemPrompt = data.defaultSystemPrompt || ''
				this.defaultVerbose = !!data.defaultVerbose
				this.loaded = true
			} catch (err) {
				this.status = t('aiquila', 'Failed to load settings: ') + err.message
				this.statusType = 'error'
			}
		},
		onProviderChange() {
			// Switching provider scopes the key field; clear any pending entry.
			this.apiKey = ''
			this.clearKey = false
			this.dirty = true
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
			this.loadSettings()
		},
		async onSave() {
			this.saving = true
			this.status = ''
			const providerId = this.selectedProvider?.id || ''
			try {
				const payload = {
					provider: providerId,
					model: this.selectedModel || '',
					default_system_prompt: this.defaultSystemPrompt,
					default_verbose: this.defaultVerbose ? '1' : '0',
				}
				// Only touch the key when the user cleared it or typed a new one;
				// otherwise leave the scoped provider's key untouched.
				if (this.clearKey) {
					payload.api_key = ''
				} else if (this.apiKey) {
					payload.api_key = this.apiKey
				}
				await saveSettings(payload)
				this.status = t('aiquila', 'Saved!')
				this.statusType = 'success'
				this.dirty = false
				// Reflect key state for the edited provider in the local cache.
				const entry = this.currentProvider
				if (entry) {
					if (this.clearKey) entry.hasUserKey = false
					else if (this.apiKey) entry.hasUserKey = true
				}
				this.apiKey = ''
				this.clearKey = false
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

.settings-section {
	margin-bottom: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.settings-section summary {
	padding: 8px 12px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	background: var(--color-background-hover);
	user-select: none;
}

.settings-section summary:hover {
	background: var(--color-background-dark);
}

.section-content {
	padding: 8px 12px 12px;
}

.setting-group {
	margin-bottom: 10px;
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

.system-prompt-input {
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
