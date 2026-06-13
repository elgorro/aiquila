<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="cowork-sidebar">
		<div class="sidebar-header">
			<span class="header-icon">⚡</span>
			<span class="header-text">{{ t('aiquila', 'Coworkers') }}</span>
		</div>
		<p v-if="loading" class="muted">{{ t('aiquila', 'Loading…') }}</p>
		<p v-else-if="coworkers.length === 0" class="muted">{{ t('aiquila', 'None yet') }}</p>
		<ul v-else class="items">
			<li v-for="cw in coworkers" :key="cw.id" class="item">
				<span class="dot" :class="statusClass(cw)" />
				<span class="item-label">{{ cw.title }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { listCoworkers } from '../api.js'

export default {
	name: 'CoworkSidebar',
	data() {
		return { coworkers: [], loading: true }
	},
	mounted() {
		this.load()
	},
	methods: { t,
		async load() {
			try {
				const res = await listCoworkers()
				this.coworkers = res.data
			} catch (e) {
				this.coworkers = []
			} finally {
				this.loading = false
			}
		},
		statusClass(cw) {
			if (!cw.isActive) return 'disabled'
			if (cw.paused) return 'paused'
			return 'active'
		},
	},
}
</script>

<style scoped>
.cowork-sidebar { padding: 12px; }
.sidebar-header { display: flex; align-items: center; gap: 8px; padding: 8px 4px; font-size: 14px; font-weight: 600; color: var(--color-text-lighter); }
.muted { color: var(--color-text-lighter); font-size: 12px; padding: 8px 4px; }
.items { list-style: none; margin: 8px 0 0; padding: 0; }
.item { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: var(--border-radius-large); font-size: 13px; }
.item-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.dot.active { background: var(--color-success); }
.dot.paused { background: var(--color-warning); }
.dot.disabled { background: var(--color-border-dark); }
</style>
