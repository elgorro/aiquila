<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="tab-selector">
		<router-link v-for="tab in tabs"
			:key="tab.id"
			:to="{ name: tab.id }"
			class="tab-btn"
			active-class="active">
			<span class="tab-icon">{{ tab.icon }}</span>
			<span class="tab-label">{{ tab.label }}</span>
		</router-link>
	</div>
</template>

<script>
export default {
	name: 'TabSelector',
	data() {
		return {
			tabs: [
				{ id: 'projects', label: 'Projects', icon: '📦' },
				{ id: 'chat', label: 'Chat', icon: '💬' },
				{ id: 'cowork', label: 'Cowork', icon: '🤖' },
			],
		}
	},
}
</script>

<style scoped>
.tab-selector {
	display: flex;
	gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.tab-btn {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border: none;
	border-radius: 20px;
	background: transparent;
	color: var(--color-text-lighter);
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.15s ease;
	white-space: nowrap;
	text-decoration: none;
}

.tab-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.tab-btn.active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.tab-icon {
	font-size: 14px;
	line-height: 1;
}

.tab-label {
	line-height: 1;
}
</style>
