<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="aiquila-personal">
		<h2>AIquila</h2>
		<p class="aiquila-personal__intro">
			{{ t('aiquila', 'Choose which AI provider and model AIquila uses for you, and set the defaults new conversations start with. Individual conversations can pin a different provider from the chat header.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<SettingsTabs v-else v-model="tab" :tabs="tabs">
			<template #providers>
				<!--
					An admin can block every provider for a user. Say so plainly
					rather than showing an empty page with a note about defaults
					that no longer apply to anything.
				-->
				<NcNoteCard v-if="providers.length === 0" type="warning">
					{{ t('aiquila', 'No AI provider is available for your account. Ask your administrator for access.') }}
				</NcNoteCard>

				<NcNoteCard v-else type="info">
					{{ inheritNote }}
				</NcNoteCard>

				<div class="aiquila-personal__cards">
					<ProviderCard v-for="provider in providers"
						:key="provider.id"
						:provider="provider"
						:default-provider="selectedProvider"
						:refreshing="refreshing"
						scope="user"
						@make-default="makeDefault"
						@refresh-models="refreshModels"
						@saved="load(false)" />
				</div>

				<NcButton v-if="userProvider && providers.length > 0" type="tertiary" @click="followInstanceDefault">
					{{ t('aiquila', 'Follow the instance default again') }}
				</NcButton>
			</template>

			<template #defaults>
				<NcSettingsSection :name="t('aiquila', 'New conversations')"
					:description="t('aiquila', 'Applied when you start a conversation. Existing conversations keep whatever they were created with.')">
					<div class="aiquila-personal__field">
						<label class="aiquila-personal__label" for="aiquila-system-prompt">
							{{ t('aiquila', 'Default system prompt') }}
						</label>
						<textarea id="aiquila-system-prompt"
							v-model="defaultSystemPrompt"
							class="aiquila-personal__textarea"
							rows="4"
							:placeholder="t('aiquila', 'Custom instructions for the model…')"
							@input="dirty = true" />
					</div>

					<NcCheckboxRadioSwitch :model-value="defaultVerbose"
						@update:model-value="v => { defaultVerbose = v; dirty = true }">
						{{ t('aiquila', 'Show verbose mode by default') }}
					</NcCheckboxRadioSwitch>

					<div class="aiquila-personal__actions">
						<NcButton type="primary" :disabled="saving || !dirty" @click="saveDefaults">
							{{ saving ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="message" :type="messageType">
						{{ message }}
					</NcNoteCard>
				</NcSettingsSection>
			</template>

			<template #connectors>
				<NcSettingsSection :name="t('aiquila', 'Native MCP connector')"
					:description="t('aiquila', 'When the connector is in use, the provider calls MCP servers directly over HTTPS instead of routing every tool call through this Nextcloud instance. Your administrator sets the default; you can override it for your own conversations.')">
					<NcCheckboxRadioSwitch v-for="option in nativeMcpOptions"
						:key="option.value"
						:model-value="nativeMcpOverride"
						:value="option.value"
						name="aiquila-native-mcp"
						type="radio"
						@update:model-value="saveNativeMcp">
						{{ option.label }}
					</NcCheckboxRadioSwitch>

					<p class="aiquila-personal__hint">
						{{ effectiveNativeMcpLabel }}
					</p>
				</NcSettingsSection>
			</template>
		</SettingsTabs>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import ProviderCard from '../components/settings/ProviderCard.vue'
import SettingsTabs from '../components/settings/SettingsTabs.vue'
import {
	getSettings,
	getUserProviders,
	saveSettings,
	saveUserProvider,
} from '../settings-api.js'

export default {
	name: 'PersonalSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		ProviderCard,
		SettingsTabs,
	},
	data() {
		return {
			tab: 'providers',
			loading: true,
			refreshing: false,
			saving: false,
			dirty: false,
			message: '',
			messageType: 'success',
			providers: [],
			/** The effective provider — a personal override, or the instance default. */
			selectedProvider: '',
			/** The personal override alone; '' means "follow the instance default". */
			userProvider: '',
			adminProvider: '',
			defaultSystemPrompt: '',
			defaultVerbose: false,
			nativeMcpOverride: '',
			nativeMcpAdminDefault: false,
			nativeMcpEffective: false,
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'providers', label: t('aiquila', 'Providers') },
				{ id: 'defaults', label: t('aiquila', 'Defaults') },
				{ id: 'connectors', label: t('aiquila', 'Connectors') },
			]
		},
		inheritNote() {
			const label = this.providers.find(p => p.id === this.adminProvider)?.label || this.adminProvider
			return this.userProvider
				? t('aiquila', 'You have picked your own provider. Leave a field blank on a card to fall back to the instance setting.')
				: t('aiquila', 'You are following the instance default ({provider}). Picking a provider here overrides it for your conversations.', { provider: label })
		},
		nativeMcpOptions() {
			const inherited = this.nativeMcpAdminDefault ? t('aiquila', 'on') : t('aiquila', 'off')
			return [
				{ value: '', label: t('aiquila', 'Follow the instance default (currently {state})', { state: inherited }) },
				{ value: '1', label: t('aiquila', 'Always use the native connector when reachable') },
				{ value: '0', label: t('aiquila', 'Always use the local tool loop') },
			]
		},
		effectiveNativeMcpLabel() {
			return this.nativeMcpEffective
				? t('aiquila', 'In effect right now: the native connector.')
				: t('aiquila', 'In effect right now: the local tool loop.')
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load(showSpinner = true) {
			if (showSpinner) {
				this.loading = true
			}
			try {
				const [{ data: providerData }, { data: settings }] = await Promise.all([
					getUserProviders(false),
					getSettings(),
				])
				this.providers = providerData.providers
				this.selectedProvider = providerData.defaultProvider
				this.userProvider = providerData.userProvider
				this.adminProvider = providerData.adminProvider

				this.defaultSystemPrompt = settings.defaultSystemPrompt || ''
				this.defaultVerbose = !!settings.defaultVerbose
				this.nativeMcpOverride = settings.nativeMcpUserOverride ?? ''
				this.nativeMcpAdminDefault = !!settings.nativeMcpAdminDefault
				this.nativeMcpEffective = !!settings.nativeMcpEffective
				this.dirty = false
			} finally {
				this.loading = false
			}
		},
		async refreshModels() {
			this.refreshing = true
			try {
				const { data } = await getUserProviders(true)
				this.providers = data.providers
			} finally {
				this.refreshing = false
			}
		},
		async makeDefault(providerId) {
			this.selectedProvider = providerId
			await saveUserProvider(providerId, {}, true)
			await this.load(false)
		},
		/** Clears the personal override so the instance default applies again. */
		async followInstanceDefault() {
			await saveUserProvider(this.selectedProvider, {}, false)
			await this.load(false)
		},
		async saveDefaults() {
			this.saving = true
			this.message = ''
			try {
				await saveSettings({
					default_system_prompt: this.defaultSystemPrompt,
					default_verbose: this.defaultVerbose ? '1' : '0',
				})
				this.dirty = false
				this.messageType = 'success'
				this.message = t('aiquila', 'Saved.')
			} catch (err) {
				this.messageType = 'error'
				this.message = err.response?.data?.error || err.message
			} finally {
				this.saving = false
			}
		},
		async saveNativeMcp(value) {
			this.nativeMcpOverride = value
			await saveSettings({ native_mcp_enabled: value })
			await this.load(false)
		},
	},
}
</script>

<style scoped>
.aiquila-personal {
	padding: 8px 0;
}

.aiquila-personal__intro {
	max-width: 900px;
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
}

.aiquila-personal__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: 16px;
	margin: 16px 0;
	align-items: start;
}

.aiquila-personal__field {
	max-width: 640px;
	margin-bottom: 16px;
}

.aiquila-personal__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.aiquila-personal__textarea {
	width: 100%;
	resize: vertical;
}

.aiquila-personal__actions {
	margin-top: 16px;
}

.aiquila-personal__hint {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
