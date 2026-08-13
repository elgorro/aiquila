<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="model-picker">
		<NcSelect v-model="providerChoice"
			class="model-picker__select"
			input-id="conversation-provider"
			:options="providerOptions"
			:aria-label-combobox="t('aiquila', 'Provider for this conversation')"
			:placeholder="t('aiquila', 'Provider')"
			:clearable="false"
			:disabled="saving"
			label="label"
			@update:model-value="onProviderChange" />

		<NcSelect v-model="modelChoice"
			class="model-picker__select"
			input-id="conversation-model"
			:options="modelOptions"
			:aria-label-combobox="t('aiquila', 'Model for this conversation')"
			:placeholder="t('aiquila', 'Model')"
			:clearable="false"
			:disabled="saving || modelOptions.length === 0"
			@update:model-value="onModelChange" />

		<span v-if="error" class="model-picker__error">{{ error }}</span>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import { setConversationModel } from '../api.js'
import { getUserProviders } from '../settings-api.js'

/** Sentinel for "no pin — follow my personal default". */
const FOLLOW_DEFAULT = ''

export default {
	name: 'ConversationModelPicker',
	components: { NcSelect },
	props: {
		conversation: {
			type: Object,
			required: true,
		},
	},
	emits: ['conversation-updated'],
	data() {
		return {
			providers: [],
			defaultProvider: '',
			providerChoice: null,
			modelChoice: null,
			saving: false,
			error: '',
		}
	},
	computed: {
		/**
		 * Only providers this user can actually reach: a card with no key would
		 * pin a conversation to a provider that errors on the next message.
		 */
		usableProviders() {
			return this.providers.filter(p => p.configured)
		},
		providerOptions() {
			const followLabel = this.providers.find(p => p.id === this.defaultProvider)?.label || this.defaultProvider
			return [
				{ id: FOLLOW_DEFAULT, label: t('aiquila', 'Follow my default ({provider})', { provider: followLabel }) },
				...this.usableProviders.map(p => ({ id: p.id, label: p.label })),
			]
		},
		/** The provider actually serving this conversation, pinned or not. */
		effectiveProviderId() {
			return this.conversation.provider || this.defaultProvider
		},
		modelOptions() {
			const provider = this.providers.find(p => p.id === this.effectiveProviderId)
			const modelField = provider?.fields?.find(f => f.id === 'model')
			return modelField?.options || []
		},
	},
	watch: {
		'conversation.id': 'syncFromConversation',
		providers: 'syncFromConversation',
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load() {
			try {
				const { data } = await getUserProviders(false)
				this.providers = data.providers
				this.defaultProvider = data.defaultProvider
				this.syncFromConversation()
			} catch (err) {
				this.error = err.response?.data?.message || err.message
			}
		},
		syncFromConversation() {
			const pinned = this.conversation.provider || FOLLOW_DEFAULT
			this.providerChoice = this.providerOptions.find(o => o.id === pinned) || this.providerOptions[0]
			// An unpinned conversation follows the user's setting for the model
			// too, so its stored model is a snapshot that may belong to a
			// provider it no longer uses. Show what will actually be used.
			this.modelChoice = pinned
				? (this.conversation.model || null)
				: (this.providers.find(p => p.id === this.defaultProvider)?.currentModel || null)
		},
		async onProviderChange(option) {
			// Send the model as blank so the backend re-snapshots the new
			// provider's default; the old model id means nothing to it.
			await this.persist({ provider: option?.id ?? FOLLOW_DEFAULT, model: '' })
		},
		async onModelChange(model) {
			await this.persist({ model: model || '' })
		},
		async persist(payload) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await setConversationModel(this.conversation.id, payload)
				this.$emit('conversation-updated', data)
				this.modelChoice = data.model || null
			} catch (err) {
				this.error = err.response?.data?.error || err.message
				this.syncFromConversation()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.model-picker {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.model-picker__select {
	min-width: 180px;
}

.model-picker__error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
