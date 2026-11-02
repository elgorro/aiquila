<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!--
		Picks a prompt from the library and emits its text. The list is loaded
		each time the menu opens, so prompts added on the settings page show up
		without a reload.
	-->
	<NcActions :disabled="disabled"
		:aria-label="t('aiquila', 'Insert a prompt')"
		:title="t('aiquila', 'Insert a prompt')"
		type="tertiary"
		@open="load">
		<template #icon>
			<TextBoxMultipleOutlineIcon :size="20" />
		</template>
		<NcActionCaption v-if="loading" :name="t('aiquila', 'Loading…')" />
		<NcActionCaption v-else-if="error" :name="error" />
		<NcActionCaption v-else-if="prompts.length === 0"
			:name="t('aiquila', 'No prompts yet. Add some in the AIquila personal settings.')" />
		<template v-else>
			<template v-for="group in groups" :key="group.scope">
				<NcActionCaption :name="group.label" />
				<NcActionButton v-for="prompt in group.items"
					:key="prompt.id"
					:description="prompt.description || ''"
					close-after-click
					@click="$emit('insert', prompt.content)">
					{{ prompt.title }}
				</NcActionButton>
			</template>
		</template>
	</NcActions>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import TextBoxMultipleOutlineIcon from 'vue-material-design-icons/TextBoxMultipleOutline.vue'

import { listPrompts } from '../api.js'

export default {
	name: 'PromptPicker',
	components: {
		NcActions,
		NcActionButton,
		NcActionCaption,
		TextBoxMultipleOutlineIcon,
	},
	props: {
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['insert'],
	data() {
		return {
			prompts: [],
			loading: false,
			error: '',
		}
	},
	computed: {
		groups() {
			return [
				{ scope: 'instance', label: t('aiquila', 'Organization'), items: this.prompts.filter(p => p.scope === 'instance') },
				{ scope: 'user', label: t('aiquila', 'My prompts'), items: this.prompts.filter(p => p.scope !== 'instance') },
			].filter(g => g.items.length > 0)
		},
	},
	methods: {
		t,
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await listPrompts()
				this.prompts = data
			} catch (err) {
				console.error('Failed to load prompts:', err)
				this.error = t('aiquila', 'Could not load prompts')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
