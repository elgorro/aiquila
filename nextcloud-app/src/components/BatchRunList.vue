<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="batch-runs">
		<div class="batch-runs-header">
			<h3>{{ t('aiquila', 'Runs from Files') }}</h3>
			<button class="link" @click="refresh">{{ t('aiquila', 'Refresh') }}</button>
		</div>
		<p class="muted">
			{{ t('aiquila', 'Started with “Process with AIquila…” on files and folders in Files.') }}
		</p>
		<p v-if="loading && runs.length === 0" class="muted">{{ t('aiquila', 'Loading…') }}</p>
		<p v-else-if="runs.length === 0" class="muted">{{ t('aiquila', 'No runs yet.') }}</p>
		<ul v-else class="runs">
			<li v-for="run in runs"
				:id="`batch-run-${run.id}`"
				:key="run.id"
				class="run-entry"
				:class="{ focused: run.id === focusRunId }">
				<div class="run">
					<button class="toggle"
						:aria-expanded="expanded === run.id ? 'true' : 'false'"
						:aria-controls="`batch-run-detail-${run.id}`"
						@click="toggle(run)">
						<span aria-hidden="true">{{ expanded === run.id ? '▾' : '▸' }}</span>
						<span class="op">{{ operationLabel(run.operation) }}</span>
						<span class="status" :class="run.status">{{ statusLabel(run.status) }}</span>
					</button>
					<span class="progress">{{ run.itemsProcessed }}/{{ run.itemsTotal }}</span>
					<span v-if="run.itemsFailed > 0" class="failed">{{ n('aiquila', '%n failed', '%n failed', run.itemsFailed) }}</span>
					<span v-if="run.itemsSkipped > 0" class="skipped">{{ n('aiquila', '%n skipped', '%n skipped', run.itemsSkipped) }}</span>
					<span class="when">{{ formatTime(run.startedAt) }}</span>
					<span v-if="run.error" class="err" :title="run.error">{{ run.error }}</span>
					<span v-else-if="run.summary" class="summary" :title="run.summary">{{ firstLine(run.summary) }}</span>
					<button v-if="isActive(run)"
						class="link stop"
						:disabled="cancelling === run.id"
						@click="cancel(run)">
						{{ t('aiquila', 'Cancel') }}
					</button>
				</div>
				<div v-if="expanded === run.id" :id="`batch-run-detail-${run.id}`" class="detail">
					<p v-if="run.status === 'queued'" class="muted">
						{{ t('aiquila', 'Waiting for the next background job to start it.') }}
					</p>
					<p v-else-if="run.status === 'pending'" class="muted">
						{{ t('aiquila', 'Submitted as a batch. Results arrive within 24 hours.') }}
					</p>
					<p v-if="run.summary" class="detail-text">{{ run.summary }}</p>
					<template v-if="run.outputs.length > 0">
						<p class="detail-heading">{{ n('aiquila', '%n result', '%n results', run.outputs.length) }}</p>
						<ul class="outputs">
							<li v-for="path in run.outputs" :key="path">
								<a :href="filesLink(path)" :title="path">{{ path }}</a>
							</li>
						</ul>
					</template>
					<p v-if="logState(run.id) === 'loading'" class="muted">{{ t('aiquila', 'Loading log…') }}</p>
					<p v-else-if="logState(run.id) === 'error'" class="muted">{{ t('aiquila', 'The log could not be loaded.') }}</p>
					<ol v-else class="log">
						<li v-for="(entry, i) in logOf(run.id)" :key="i" class="log-entry" :class="entry.level">
							<time class="log-ts" :datetime="isoTime(entry.ts)">{{ formatTime(entry.ts) }}</time>
							<a v-if="entry.item && entry.item.startsWith('/')"
								class="log-item"
								:href="filesLink(entry.item)"
								:title="entry.item">{{ entry.item }}</a>
							<span v-else-if="entry.item" class="log-item">{{ entry.item }}</span>
							<span class="log-message">{{ entry.message }}</span>
						</li>
					</ol>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { cancelBatchRun, getBatchRun, listBatchRuns } from '../api.js'

// Never poll faster than this, whatever a run suggests.
const MIN_POLL_SECONDS = 10

export default {
	name: 'BatchRunList',
	props: {
		/** A run to open and scroll to, e.g. from the toast after starting one. */
		focusRunId: { type: Number, default: null },
	},
	data() {
		return {
			runs: [],
			loading: false,
			expanded: null,
			cancelling: null,
			// runId → { state: 'loading'|'ready'|'error', log: [] }
			logs: {},
			timer: null,
		}
	},
	watch: {
		focusRunId() {
			this.openFocused()
		},
	},
	async mounted() {
		await this.refresh()
		await this.openFocused()
	},
	beforeUnmount() {
		clearTimeout(this.timer)
	},
	methods: { t, n,
		operationLabel(op) {
			return { summarize: t('aiquila', 'Summarize'), translate: t('aiquila', 'Translate') }[op] || op
		},
		statusLabel(status) {
			return {
				queued: t('aiquila', 'Queued'),
				running: t('aiquila', 'Running'),
				pending: t('aiquila', 'Waiting for batch'),
				success: t('aiquila', 'Done'),
				error: t('aiquila', 'Failed'),
				cancelled: t('aiquila', 'Cancelled'),
			}[status] || status
		},
		isActive(run) {
			return run.pollIntervalSeconds !== null
		},
		logState(id) {
			return this.logs[id]?.state ?? 'loading'
		},
		logOf(id) {
			return this.logs[id]?.log ?? []
		},
		async openFocused() {
			if (this.focusRunId === null || !this.runs.some(r => r.id === this.focusRunId)) {
				return
			}
			this.expanded = this.focusRunId
			await this.loadDetail(this.focusRunId)
			await this.$nextTick()
			document.getElementById(`batch-run-${this.focusRunId}`)?.scrollIntoView({ block: 'center' })
		},
		async toggle(run) {
			if (this.expanded === run.id) {
				this.expanded = null
				return
			}
			this.expanded = run.id
			await this.loadDetail(run.id)
		},
		async loadDetail(id) {
			this.logs = { ...this.logs, [id]: { state: 'loading', log: this.logOf(id) } }
			try {
				const { data } = await getBatchRun(id)
				this.logs = { ...this.logs, [id]: { state: 'ready', log: data.log } }
				this.runs = this.runs.map(r => (r.id === id ? { ...data, log: undefined } : r))
			} catch (e) {
				this.logs = { ...this.logs, [id]: { state: 'error', log: [] } }
			}
		},
		async cancel(run) {
			this.cancelling = run.id
			try {
				await cancelBatchRun(run.id)
			} catch (e) {
				// 409: it ended meanwhile. The refresh shows how.
			} finally {
				this.cancelling = null
				await this.refresh()
			}
		},
		async refresh() {
			clearTimeout(this.timer)
			this.loading = true
			try {
				const { data } = await listBatchRuns()
				this.runs = data
			} catch (e) {
				this.runs = []
			} finally {
				this.loading = false
			}
			if (this.expanded !== null && this.runs.some(r => r.id === this.expanded)) {
				await this.loadDetail(this.expanded)
			}
			this.schedule()
		},
		// Refresh while a run is open, at the slowest interval the open runs
		// suggest that still shows a running one moving.
		schedule() {
			const intervals = this.runs.map(r => r.pollIntervalSeconds).filter(s => s !== null)
			if (intervals.length === 0) {
				return
			}
			const seconds = Math.max(MIN_POLL_SECONDS, Math.min(...intervals))
			this.timer = setTimeout(() => this.refresh(), seconds * 1000)
		},
		filesLink(path) {
			const slash = path.lastIndexOf('/')
			const dir = slash <= 0 ? '/' : path.slice(0, slash)
			const params = new URLSearchParams({ dir, openfile: path.slice(slash + 1) })
			return generateUrl(`/apps/files/?${params.toString()}`)
		},
		formatTime(epoch) {
			return epoch ? new Date(epoch * 1000).toLocaleString() : ''
		},
		isoTime(epoch) {
			return epoch ? new Date(epoch * 1000).toISOString() : ''
		},
		firstLine(text) {
			return (text || '').split('\n')[0]
		},
	},
}
</script>

<style scoped>
.batch-runs { margin-top: 24px; }
.batch-runs-header { display: flex; justify-content: space-between; align-items: center; }
.batch-runs-header h3 { font-size: 15px; margin: 0; }
.muted { color: var(--color-text-maxcontrast); font-size: 13px; margin: 4px 0; }
.runs { list-style: none; margin: 8px 0 0; padding: 0; }
.run-entry { border-bottom: 1px solid var(--color-border); }
.run-entry.focused { background: var(--color-primary-element-light); border-radius: var(--border-radius); }
.run { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 6px 4px; font-size: 13px; }
.toggle { display: inline-flex; gap: 6px; align-items: center; background: none; border: none; padding: 0; cursor: pointer; color: inherit; font-size: 13px; }
.op { font-weight: 600; }
.status { font-weight: 600; }
.status.success { color: var(--color-success-text); }
.status.error { color: var(--color-error-text); }
.status.running, .status.queued { color: var(--color-warning-text); }
/* Neutral: a pending run is healthy, its batch may take hours. */
.status.pending, .status.cancelled { color: var(--color-text-maxcontrast); }
.skipped { color: var(--color-warning-text); }
.failed, .err { color: var(--color-error-text); }
.when { color: var(--color-text-maxcontrast); }
.summary, .err { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 360px; }
.link { background: none; border: none; color: var(--color-primary-element); cursor: pointer; padding: 0; }
.link.stop { margin-left: auto; color: var(--color-error-text); }
.detail { padding: 4px 4px 12px 20px; font-size: 12px; }
.detail-text { white-space: pre-wrap; margin: 0 0 6px; }
.detail-heading { font-weight: 600; margin: 6px 0 2px; }
.outputs { margin: 0 0 6px; padding-left: 16px; }
.outputs a, a.log-item { color: var(--color-primary-element); }
.log { list-style: none; margin: 4px 0 0; padding: 0; max-height: 320px; overflow-y: auto; }
.log-entry { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 8px; padding: 2px 0; }
.log-ts { color: var(--color-text-maxcontrast); white-space: nowrap; }
.log-entry.warn .log-message { color: var(--color-warning-text); }
.log-entry.error .log-message { color: var(--color-error-text); }
.log-item { grid-column: 2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-message { grid-column: 2; overflow-wrap: anywhere; }
</style>
