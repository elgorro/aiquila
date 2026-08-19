<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="aiquila-admin">
		<h2>AIquila</h2>
		<p class="aiquila-admin__intro">
			{{ t('aiquila', 'AIquila connects Nextcloud to large language models so your users can chat, summarise, translate and more, directly inside Nextcloud. Pick a provider, add its key, and use Test connection to confirm it works.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<SettingsTabs v-else v-model="tab" :tabs="tabs">
			<template #providers>
				<NcNoteCard type="info">
					{{ t('aiquila', 'The provider marked as default serves every user who has not picked their own. Users can override the provider and the model in their personal settings, and pin either one per conversation.') }}
				</NcNoteCard>

				<div class="aiquila-admin__cards">
					<ProviderCard v-for="provider in providers"
						:key="provider.id"
						:provider="provider"
						:default-provider="defaultProvider"
						:refreshing="refreshing"
						scope="admin"
						@make-default="makeDefault"
						@refresh-models="refreshModels"
						@saved="load(false)" />
				</div>
			</template>

			<template #defaults>
				<NcSettingsSection :name="t('aiquila', 'Search integration')"
					:description="t('aiquila', 'Expose AIquila conversations to Nextcloud\'s unified search.')">
					<NcCheckboxRadioSwitch :model-value="searchEnabled" @update:model-value="saveSearch">
						{{ t('aiquila', 'Include AIquila in unified search') }}
					</NcCheckboxRadioSwitch>
				</NcSettingsSection>

				<NcSettingsSection :name="t('aiquila', 'Model defaults')"
					:description="t('aiquila', 'Model, token limits, effort and thinking are configured per provider, because they mean different things to each one. Open a provider card on the Providers tab to change them.')">
					<NcButton type="secondary" @click="tab = 'providers'">
						{{ t('aiquila', 'Go to providers') }}
					</NcButton>
				</NcSettingsSection>
			</template>

			<template #mcp>
				<NcSettingsSection :name="t('aiquila', 'MCP servers')"
					:description="t('aiquila', 'Model Context Protocol servers give the model tools. Tool calls are dispatched by this Nextcloud instance unless the native connector below is enabled.')">
					<McpServerList />
				</NcSettingsSection>
			</template>

			<template #advanced>
				<NcSettingsSection :name="t('aiquila', 'Native MCP connector (beta)')"
					:description="t('aiquila', 'When enabled, providers that support it call MCP servers directly over HTTPS instead of routing every tool call through this instance. Servers must be reachable from the provider, not just from Nextcloud.')">
					<NcNoteCard type="warning">
						{{ t('aiquila', 'Your MCP servers must be publicly reachable over HTTPS for this path. Servers on localhost or a private network will not work.') }}
					</NcNoteCard>

					<NcCheckboxRadioSwitch v-model="nativeMcp.enabled">
						{{ t('aiquila', 'Enable the native MCP connector by default') }}
					</NcCheckboxRadioSwitch>

					<div class="aiquila-admin__field">
						<NcTextField v-model="nativeMcp.extraUrl"
							:label="t('aiquila', 'Extra MCP server URL')"
							placeholder="https://mcp.example.com/mcp" />
					</div>

					<div class="aiquila-admin__field">
						<NcPasswordField v-model="nativeMcp.extraToken"
							:label="t('aiquila', 'Bearer token for the extra server')"
							:placeholder="nativeMcp.hasExtraToken ? t('aiquila', 'Token configured — enter a new one to replace it') : t('aiquila', 'Optional')" />
					</div>

					<div class="aiquila-admin__actions">
						<NcButton type="primary" :disabled="savingNativeMcp" @click="saveNativeMcp">
							{{ savingNativeMcp ? t('aiquila', 'Saving…') : t('aiquila', 'Save') }}
						</NcButton>
						<NcButton type="secondary" @click="loadNativeMcp">
							{{ t('aiquila', 'Refresh reachability') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="nativeMcpMessage" :type="nativeMcpMessageType">
						{{ nativeMcpMessage }}
					</NcNoteCard>

					<ul v-if="nativeMcp.servers.length" class="aiquila-admin__probe">
						<li v-for="server in nativeMcp.servers" :key="server.url">
							<span :class="server.reachable ? 'is-ok' : 'is-bad'">{{ server.reachable ? '✓' : '✗' }}</span>
							{{ server.name }} — {{ server.message }}
						</li>
					</ul>
				</NcSettingsSection>

				<NcSettingsSection :name="t('aiquila', 'Resources')">
					<ul class="aiquila-admin__links">
						<li><a href="https://github.com/elgorro/aiquila" target="_blank" rel="noopener noreferrer">{{ t('aiquila', 'Documentation and source') }}</a></li>
						<li><a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer">Anthropic console</a></li>
						<li><a href="https://console.mistral.ai/" target="_blank" rel="noopener noreferrer">Mistral console</a></li>
						<li><a href="https://platform.deepseek.com/" target="_blank" rel="noopener noreferrer">DeepSeek platform</a></li>
						<li><a href="https://experiments.hetzner.com/inference" target="_blank" rel="noopener noreferrer">Hetzner Inference</a></li>
					</ul>
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
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import McpServerList from '../components/settings/McpServerList.vue'
import ProviderCard from '../components/settings/ProviderCard.vue'
import SettingsTabs from '../components/settings/SettingsTabs.vue'
import {
	getAdminProviders,
	getNativeMcpStatus,
	saveAdminProvider,
	saveAdminSettings,
} from '../settings-api.js'

export default {
	name: 'AdminSettings',
	components: {
		McpServerList,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSettingsSection,
		NcTextField,
		ProviderCard,
		SettingsTabs,
	},
	data() {
		return {
			tab: 'providers',
			loading: true,
			refreshing: false,
			providers: [],
			defaultProvider: '',
			searchEnabled: false,
			savingNativeMcp: false,
			nativeMcpMessage: '',
			nativeMcpMessageType: 'success',
			nativeMcp: {
				enabled: false,
				extraUrl: '',
				extraToken: '',
				hasExtraToken: false,
				servers: [],
			},
		}
	},
	computed: {
		tabs() {
			return [
				{ id: 'providers', label: t('aiquila', 'Providers') },
				{ id: 'defaults', label: t('aiquila', 'Defaults') },
				{ id: 'mcp', label: t('aiquila', 'MCP servers') },
				{ id: 'advanced', label: t('aiquila', 'Advanced') },
			]
		},
	},
	async mounted() {
		this.searchEnabled = document.getElementById('aiquila-admin-settings')?.dataset.searchEnabled === '1'
		await Promise.all([this.load(), this.loadNativeMcp()])
	},
	methods: {
		t,
		async load(showSpinner = true) {
			if (showSpinner) {
				this.loading = true
			}
			try {
				const { data } = await getAdminProviders(false)
				this.providers = data.providers
				this.defaultProvider = data.defaultProvider
			} finally {
				this.loading = false
			}
		},
		/** Bypasses the model-list cache; one outbound call per provider. */
		async refreshModels() {
			this.refreshing = true
			try {
				const { data } = await getAdminProviders(true)
				this.providers = data.providers
			} finally {
				this.refreshing = false
			}
		},
		async makeDefault(providerId) {
			// Optimistic: the radio has already moved visually.
			this.defaultProvider = providerId
			await saveAdminProvider(providerId, {}, true)
			await this.load(false)
		},
		async saveSearch(enabled) {
			this.searchEnabled = enabled
			await saveAdminSettings({ search_enabled: enabled ? '1' : '0' })
		},
		async loadNativeMcp() {
			const { data } = await getNativeMcpStatus()
			this.nativeMcp = {
				enabled: data.enabled,
				extraUrl: data.extraUrl,
				// Never populated from the server; blank means "keep the stored one".
				extraToken: '',
				hasExtraToken: data.hasExtraToken,
				servers: data.servers || [],
			}
		},
		async saveNativeMcp() {
			this.savingNativeMcp = true
			this.nativeMcpMessage = ''
			try {
				await saveAdminSettings({
					native_mcp_enabled: this.nativeMcp.enabled ? '1' : '0',
					native_mcp_extra_url: this.nativeMcp.extraUrl,
					...(this.nativeMcp.extraToken ? { native_mcp_extra_token: this.nativeMcp.extraToken } : {}),
				})
				this.nativeMcpMessageType = 'success'
				this.nativeMcpMessage = t('aiquila', 'Saved.')
				await this.loadNativeMcp()
			} catch (err) {
				this.nativeMcpMessageType = 'error'
				this.nativeMcpMessage = err.response?.data?.error || err.message
			} finally {
				this.savingNativeMcp = false
			}
		},
	},
}
</script>

<style scoped>
.aiquila-admin {
	padding: 8px 0;
}

.aiquila-admin__intro {
	max-width: 900px;
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
}

.aiquila-admin__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: 16px;
	margin-top: 16px;
	align-items: start;
}

.aiquila-admin__field {
	max-width: 480px;
	margin: 12px 0;
}

.aiquila-admin__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.aiquila-admin__probe,
.aiquila-admin__links {
	margin-top: 12px;
	padding-left: 0;
	list-style: none;
}

.aiquila-admin__probe .is-ok {
	color: var(--color-success);
}

.aiquila-admin__probe .is-bad {
	color: var(--color-error);
}

.aiquila-admin__links a {
	text-decoration: underline;
}
</style>
