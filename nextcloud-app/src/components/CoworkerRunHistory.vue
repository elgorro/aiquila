<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="run-history">
		<div class="run-history-header">
			<span>{{ t('aiquila', 'Run history') }}</span>
			<button class="link" @click="refresh">{{ t('aiquila', 'Refresh') }}</button>
		</div>
		<p v-if="loading" class="muted">{{ t('aiquila', 'Loading…') }}</p>
		<p v-else-if="runs.length === 0" class="muted">{{ t('aiquila', 'No runs yet.') }}</p>
		<ul v-else class="runs">
			<li v-for="run in runs" :key="run.id" class="run">
				<span class="status" :class="run.status">{{ run.status }}</span>
				<span class="progress">{{ run.itemsProcessed }}/{{ run.itemsTotal }}</span>
				<span class="when">{{ formatTime(run.startedAt) }}</span>
				<span v-if="run.error" class="err" :title="run.error">{{ run.error }}</span>
				<span v-else-if="run.summary" class="summary" :title="run.summary">{{ firstLine(run.summary) }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCoworkerRuns } from '../api.js'

export default {
	name: 'CoworkerRunHistory',
	props: {
		coworkerId: { type: Number, required: true },
	},
	data() {
		return { runs: [], loading: false }
	},
	watch: {
		coworkerId: 'refresh',
	},
	mounted() {
		this.refresh()
	},
	methods: { t,
		async refresh() {
			this.loading = true
			try {
				const res = await getCoworkerRuns(this.coworkerId)
				this.runs = res.data
			} catch (e) {
				this.runs = []
			} finally {
				this.loading = false
			}
		},
		formatTime(epoch) {
			if (!epoch) return ''
			return new Date(epoch * 1000).toLocaleString()
		},
		firstLine(text) {
			return (text || '').split('\n')[0]
		},
	},
}
</script>

<style scoped>
.run-history { margin-top: 16px; }
.run-history-header { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
.muted { color: var(--color-text-lighter); font-size: 13px; }
.runs { list-style: none; margin: 0; padding: 0; }
.run { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--color-border); font-size: 12px; }
.status { text-transform: capitalize; font-weight: 600; }
.status.success { color: var(--color-success); }
.status.error { color: var(--color-error); }
.status.running { color: var(--color-warning); }
.when { color: var(--color-text-lighter); }
.summary, .err { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 320px; }
.err { color: var(--color-error); }
.link { background: none; border: none; color: var(--color-primary-element); cursor: pointer; padding: 0; }
</style>
