<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="settings-tabs">
		<!--
			Nextcloud has no page-level tab component, so the idiomatic segmented
			control is a radio-style NcCheckboxRadioSwitch with button styling.
			It stays a real radio group, which is what gives us keyboard
			navigation and screen-reader semantics for free.
		-->
		<div class="settings-tabs__bar" role="tablist">
			<NcCheckboxRadioSwitch v-for="tab in tabs"
				:key="tab.id"
				:model-value="modelValue"
				:value="tab.id"
				:button-variant="true"
				name="aiquila-settings-tab"
				type="radio"
				button-variant-grouped="horizontal"
				@update:model-value="select">
				{{ tab.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="settings-tabs__panel">
			<slot :name="modelValue" />
		</div>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

export default {
	name: 'SettingsTabs',
	components: { NcCheckboxRadioSwitch },
	props: {
		/** @type {{id: string, label: string}[]} */
		tabs: {
			type: Array,
			required: true,
		},
		modelValue: {
			type: String,
			required: true,
		},
	},
	emits: ['update:modelValue'],
	mounted() {
		// The hash makes a tab linkable, so an admin can be pointed straight at
		// the one that matters ("see Settings → AIquila#mcp").
		const fromHash = window.location.hash.replace('#', '')
		if (fromHash && this.tabs.some(t => t.id === fromHash) && fromHash !== this.modelValue) {
			this.$emit('update:modelValue', fromHash)
		}
	},
	methods: {
		select(id) {
			if (!id || id === this.modelValue) {
				return
			}
			this.$emit('update:modelValue', id)
			window.history.replaceState(null, '', `#${id}`)
		},
	},
}
</script>

<style scoped>
.settings-tabs__bar {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 24px;
}

.settings-tabs__panel {
	max-width: 900px;
}
</style>
