<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="aiquila-cowork-widget">
		<p v-if="loading" class="muted">{{ t('aiquila', 'Loading…') }}</p>

		<!-- Picker: shown when no coworker is selected -->
		<div v-else-if="!selectedId" class="picker">
			<p class="muted">{{ t('aiquila', 'Choose a coworker to display its latest result.') }}</p>
			<p v-if="coworkers.length === 0" class="muted">
				{{ t('aiquila', 'No coworkers yet.') }}
			</p>
			<select v-else v-model="pendingId" class="picker-select">
				<option :value="null" disabled>{{ t('aiquila', 'Select a coworker…') }}</option>
				<option v-for="c in coworkers" :key="c.id" :value="c.id">{{ c.title }}</option>
			</select>
			<button v-if="coworkers.length > 0" class="primary" :disabled="!pendingId" @click="choose">
				{{ t('aiquila', 'Show') }}
			</button>
		</div>

		<!-- Output: latest run summary -->
		<div v-else class="output">
			<div class="output-header">
				<span class="title">{{ coworkerTitle }}</span>
				<button class="link" @click="clear">{{ t('aiquila', 'Change') }}</button>
			</div>
			<p v-if="run" class="meta">
				<span class="status" :class="run.status">{{ run.status }}</span>
				<span class="when">{{ formatTime(run.startedAt) }}</span>
			</p>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div v-if="run && run.summary" class="summary" v-html="renderedSummary"></div>
			<p v-else-if="run && run.error" class="err">{{ run.error }}</p>
			<p v-else class="muted">{{ t('aiquila', 'No results yet — this coworker has not run.') }}</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	listCoworkers,
	getCoworkerRuns,
	getDashboardCoworker,
	setDashboardCoworker,
} from '../../api.js'
import { renderMarkdown } from '../../utils/markdown.js'

export default {
	name: 'CoworkerOutputWidget',
	data() {
		return {
			loading: true,
			coworkers: [],
			selectedId: null,
			pendingId: null,
			run: null,
		}
	},
	computed: {
		coworkerTitle() {
			const c = this.coworkers.find(x => x.id === this.selectedId)
			return c ? c.title : t('aiquila', 'Coworker')
		},
		renderedSummary() {
			return this.run && this.run.summary ? renderMarkdown(this.run.summary) : ''
		},
	},
	async mounted() {
		try {
			const [coworkersRes, selectionRes] = await Promise.all([
				listCoworkers(),
				getDashboardCoworker(),
			])
			this.coworkers = coworkersRes.data || []
			const id = selectionRes.data ? selectionRes.data.coworkerId : null
			if (id) {
				await this.load(id)
			}
		} catch (e) {
			this.coworkers = []
		} finally {
			this.loading = false
		}
	},
	methods: { t,
		async load(id) {
			this.selectedId = id
			try {
				const res = await getCoworkerRuns(id, 1)
				this.run = (res.data && res.data[0]) || null
			} catch (e) {
				this.run = null
			}
		},
		async choose() {
			if (!this.pendingId) return
			try {
				await setDashboardCoworker(this.pendingId)
				await this.load(this.pendingId)
			} catch (e) {
				// keep picker open on failure
			}
		},
		async clear() {
			try {
				await setDashboardCoworker(null)
			} catch (e) {
				// ignore — still drop the local selection
			}
			this.selectedId = null
			this.pendingId = null
			this.run = null
		},
		formatTime(epoch) {
			if (!epoch) return ''
			return new Date(epoch * 1000).toLocaleString()
		},
	},
}
</script>

<style scoped>
.aiquila-cowork-widget { font-size: 14px; }
.muted { color: var(--color-text-lighter); }
.picker { display: flex; flex-direction: column; gap: 8px; }
.picker-select { width: 100%; }
.output-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.output-header .title { font-weight: 600; }
.meta { display: flex; gap: 10px; align-items: center; font-size: 12px; margin: 0 0 8px; }
.status { text-transform: capitalize; font-weight: 600; }
.status.success { color: var(--color-success); }
.status.error { color: var(--color-error); }
.status.running { color: var(--color-warning); }
.when { color: var(--color-text-lighter); }
.summary { line-height: 1.4; overflow-wrap: anywhere; }
.summary :deep(p) { margin: 0 0 6px; }
.err { color: var(--color-error); }
.link { background: none; border: none; color: var(--color-primary-element); cursor: pointer; padding: 0; }
</style>
