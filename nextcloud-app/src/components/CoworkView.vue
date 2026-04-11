<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="cowork-view">
		<div class="cowork-header">
			<h2>{{ t('aiquila', 'Cowork') }}</h2>
			<p class="subtitle">{{ t('aiquila', 'Automate your Nextcloud workflows with Claude.') }}</p>
		</div>

		<div class="feature-cards">
			<div class="feature-card">
				<div class="card-icon">⚡</div>
				<h3>{{ t('aiquila', 'Automated Jobs') }}</h3>
				<p>{{ t('aiquila', 'Schedule recurring tasks — daily backups, weekly reports, security audits — all powered by Claude and your Nextcloud data.') }}</p>
			</div>

			<div class="feature-card">
				<div class="card-icon">🛠️</div>
				<h3>{{ t('aiquila', 'Commands') }}</h3>
				<p>{{ t('aiquila', 'User-friendly actions that Claude can execute: file operations, calendar management, note creation, and more — all from a simple interface.') }}</p>
			</div>

			<div class="feature-card">
				<div class="card-icon">📦</div>
				<h3>{{ t('aiquila', 'Project Integration') }}</h3>
				<p>{{ t('aiquila', 'Attach commands and settings to your projects. Define reusable workflows that combine files, prompts, and automated actions.') }}</p>
			</div>

			<div class="feature-card">
				<div class="card-icon">📋</div>
				<h3>{{ t('aiquila', 'Settings & Presets') }}</h3>
				<p>{{ t('aiquila', 'Save and share configuration presets for different workflows. Include model preferences, system prompts, and tool access in a single bundle.') }}</p>
			</div>
		</div>

		<div class="coming-banner">
			<span class="banner-icon">🚀</span>
			<span>{{ t('aiquila', 'These features are in development. Stay tuned!') }}</span>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CoworkView',
	methods: { t },
}
</script>

<style scoped>
.cowork-view {
	padding: 32px 24px;
	max-width: 800px;
	margin: 0 auto;
}

.cowork-header {
	margin-bottom: 32px;
}

.cowork-header h2 {
	font-size: 24px;
	font-weight: 700;
	margin: 0 0 4px;
}

.subtitle {
	color: var(--color-text-lighter);
	font-size: 14px;
	margin: 0;
}

.feature-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
	margin-bottom: 32px;
}

.feature-card {
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.card-icon {
	font-size: 28px;
	margin-bottom: 8px;
}

.feature-card h3 {
	font-size: 15px;
	font-weight: 600;
	margin: 0 0 6px;
}

.feature-card p {
	font-size: 13px;
	color: var(--color-text-lighter);
	line-height: 1.5;
	margin: 0;
}

.coming-banner {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: 14px;
	font-weight: 500;
}

.banner-icon {
	font-size: 20px;
}
</style>
