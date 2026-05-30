<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<ul class="section-nav">
		<NcAppNavigationItem v-for="section in sections"
			:key="section.id"
			:name="section.label"
			:title="section.description"
			:to="{ name: section.id }"
			:exact="false">
			<template #icon>
				<component :is="section.icon" :size="20" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import ChatIcon from 'vue-material-design-icons/ChatOutline.vue'
import FolderIcon from 'vue-material-design-icons/FolderMultipleOutline.vue'
import RobotIcon from 'vue-material-design-icons/RobotOutline.vue'

export default {
	name: 'SectionNav',
	components: { NcAppNavigationItem },
	computed: {
		sections() {
			return [
				{
					id: 'chat',
					label: t('aiquila', 'Chat'),
					description: t('aiquila', 'Talk to Claude — ad-hoc conversations and files'),
					icon: ChatIcon,
				},
				{
					id: 'projects',
					label: t('aiquila', 'Projects'),
					description: t('aiquila', 'Reusable system prompts and shared context for grouped chats'),
					icon: FolderIcon,
				},
				{
					id: 'cowork',
					label: t('aiquila', 'Cowork'),
					description: t('aiquila', 'Agentic workflows that act on your Nextcloud'),
					icon: RobotIcon,
				},
			]
		},
	},
}
</script>

<style scoped>
.section-nav {
	list-style: none;
	padding: 0;
	margin: 0 0 8px 0;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}
</style>
