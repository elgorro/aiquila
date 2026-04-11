<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="cowork-sidebar">
		<div class="sidebar-header">
			<span class="header-icon">⚡</span>
			<span class="header-text">{{ t('aiquila', 'Jobs') }}</span>
		</div>
		<div class="placeholder-items">
			<div class="placeholder-item disabled">
				<span class="item-icon">📊</span>
				<span class="item-label">{{ t('aiquila', 'Daily backup check') }}</span>
			</div>
			<div class="placeholder-item disabled">
				<span class="item-icon">📝</span>
				<span class="item-label">{{ t('aiquila', 'Weekly report') }}</span>
			</div>
			<div class="placeholder-item disabled">
				<span class="item-icon">🔍</span>
				<span class="item-label">{{ t('aiquila', 'Security scan') }}</span>
			</div>
		</div>
		<p class="coming-soon">{{ t('aiquila', 'Coming soon') }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CoworkSidebar',
	methods: { t },
}
</script>

<style scoped>
.cowork-sidebar {
	padding: 12px;
}

.sidebar-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 4px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.placeholder-items {
	margin-top: 8px;
}

.placeholder-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 8px;
	border-radius: var(--border-radius-large);
	font-size: 13px;
}

.placeholder-item.disabled {
	opacity: 0.4;
	cursor: default;
}

.item-icon {
	font-size: 16px;
}

.coming-soon {
	margin-top: 20px;
	text-align: center;
	font-size: 12px;
	color: var(--color-text-lighter);
	font-style: italic;
}
</style>
