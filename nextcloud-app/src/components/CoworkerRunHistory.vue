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
			<li v-for="run in runs" :key="run.id" class="run-entry">
				<div class="run">
					<button class="toggle"
						:aria-expanded="expanded === run.id ? 'true' : 'false'"
						:aria-controls="`run-detail-${run.id}`"
						:title="expanded === run.id ? t('aiquila', 'Hide details') : t('aiquila', 'Show details')"
						@click="toggle(run)">
						<span aria-hidden="true">{{ expanded === run.id ? '▾' : '▸' }}</span>
						<span class="status" :class="run.status">{{ statusLabel(run.status) }}</span>
					</button>
					<span class="progress">{{ run.itemsProcessed }}/{{ run.itemsTotal }}</span>
					<span v-if="run.itemsFailed > 0" class="failed">{{ n('aiquila', '%n failed', '%n failed', run.itemsFailed) }}</span>
					<span class="when">{{ formatTime(run.startedAt) }}</span>
					<span class="who">{{ t('aiquila', 'as {user}', { user: run.userId }) }}</span>
					<span v-if="run.startedBy" class="origin">{{ originLabel(run.startedBy) }}</span>
					<span v-if="run.error" class="err" :title="run.error">{{ run.error }}</span>
					<span v-else-if="run.summary" class="summary" :title="run.summary">{{ firstLine(run.summary) }}</span>
					<button v-if="isActive(run)"
						class="link stop"
						:disabled="stopping"
						@click="stop">
						{{ t('aiquila', 'Stop') }}
					</button>
				</div>
				<div v-if="expanded === run.id" :id="`run-detail-${run.id}`" class="detail">
					<p v-if="run.error" class="detail-text err-full">{{ run.error }}</p>
					<p v-if="run.summary" class="detail-text">{{ run.summary }}</p>
					<p v-if="run.finishedAt" class="muted">
						{{ t('aiquila', 'Finished {time}', { time: formatTime(run.finishedAt) }) }}
					</p>
					<p v-if="detailState(run.id) === 'loading'" class="muted">{{ t('aiquila', 'Loading log…') }}</p>
					<p v-else-if="detailState(run.id) === 'error'" class="muted">{{ t('aiquila', 'The log could not be loaded.') }}</p>
					<p v-else-if="logOf(run.id).length === 0" class="muted">{{ t('aiquila', 'No log entries for this run.') }}</p>
					<ol v-else class="log">
						<li v-for="(entry, i) in logOf(run.id)" :key="i" class="log-entry" :class="entry.level">
							<time class="log-ts" :datetime="isoTime(entry.ts)">{{ formatTime(entry.ts) }}</time>
							<span class="log-level">{{ levelLabel(entry.level) }}</span>
							<a v-if="entry.item && filesLink(run, entry.item)"
								class="log-item"
								:href="filesLink(run, entry.item)"
								:title="entry.item">{{ entry.item }}</a>
							<span v-else-if="entry.item" class="log-item" :title="entry.item">{{ entry.item }}</span>
							<span class="log-message">{{ entry.message }}</span>
						</li>
					</ol>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getCoworkerRun, getCoworkerRuns, stopCoworker } from '../api.js'

export default {
	name: 'CoworkerRunHistory',
	props: {
		coworkerId: { type: Number, required: true },
	},
	data() {
		return {
			runs: [],
			loading: false,
			stopping: false,
			// Id of the run whose details are open; one at a time keeps the list scannable.
			expanded: null,
			// runId → { state: 'loading'|'ready'|'error', log: [] }. Fetched lazily:
			// the list endpoint carries no log.
			details: {},
		}
	},
	watch: {
		coworkerId() {
			this.expanded = null
			this.refresh()
		},
	},
	mounted() {
		this.refresh()
	},
	methods: { t, n,
		// 'pending' reads as "something is stuck" on its own; the run is
		// waiting on a batch that Anthropic may take hours to return.
		statusLabel(status) {
			if (status === 'pending') {
				return t('aiquila', 'Waiting for batch')
			}
			return status === 'cancelled' ? t('aiquila', 'Cancelled') : status
		},
		originLabel(startedBy) {
			return {
				schedule: t('aiquila', 'scheduled'),
				manual: t('aiquila', 'run by hand'),
				files: t('aiquila', 'file change'),
				url: t('aiquila', 'trigger URL'),
			}[startedBy] || startedBy
		},
		levelLabel(level) {
			return {
				info: t('aiquila', 'Info'),
				warn: t('aiquila', 'Warning'),
				error: t('aiquila', 'Error'),
			}[level] || level
		},
		isActive(run) {
			return run.status === 'running' || run.status === 'pending'
		},
		detailState(runId) {
			return this.details[runId]?.state ?? 'loading'
		},
		logOf(runId) {
			return this.details[runId]?.log ?? []
		},
		async toggle(run) {
			if (this.expanded === run.id) {
				this.expanded = null
				return
			}
			this.expanded = run.id
			// A finished run's log no longer changes, so it is fetched once.
			// An active one is fetched on every open to show its progress.
			if (this.details[run.id]?.state === 'ready' && !this.isActive(run)) {
				return
			}
			await this.loadDetail(run.id)
		},
		async loadDetail(runId) {
			this.details = { ...this.details, [runId]: { state: 'loading', log: this.logOf(runId) } }
			try {
				const res = await getCoworkerRun(this.coworkerId, runId)
				const log = Array.isArray(res.data?.log) ? res.data.log : []
				this.details = { ...this.details, [runId]: { state: 'ready', log } }
			} catch (e) {
				this.details = { ...this.details, [runId]: { state: 'error', log: [] } }
			}
		},
		// `item` is a path in the Files of the account the run acted as. It
		// only resolves for that account, so other viewers (an admin, a team
		// member) see it as text.
		filesLink(run, item) {
			if (typeof item !== 'string' || !item.startsWith('/') || getCurrentUser()?.uid !== run.userId) {
				return null
			}
			const slash = item.lastIndexOf('/')
			const dir = slash <= 0 ? '/' : item.slice(0, slash)
			const params = new URLSearchParams({ dir, openfile: item.slice(slash + 1) })
			return generateUrl(`/apps/files/?${params.toString()}`)
		},
		// Stops the coworker's run in flight; a running one ends at its next
		// item, so the row may still say "running" until the next refresh.
		async stop() {
			this.stopping = true
			try {
				await stopCoworker(this.coworkerId)
			} catch (e) {
				// 404: it finished meanwhile. The refresh shows how.
			} finally {
				this.stopping = false
				await this.refresh()
			}
		},
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
			this.details = {}
			if (this.expanded !== null) {
				if (this.runs.some(r => r.id === this.expanded)) {
					await this.loadDetail(this.expanded)
				} else {
					this.expanded = null
				}
			}
		},
		formatTime(epoch) {
			if (!epoch) return ''
			return new Date(epoch * 1000).toLocaleString()
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
.run-history { margin-top: 16px; }
.run-history-header { display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
.muted { color: var(--color-text-lighter); font-size: 13px; }
.runs { list-style: none; margin: 0; padding: 0; }
.run-entry { border-bottom: 1px solid var(--color-border); }
.run { display: flex; gap: 10px; align-items: center; padding: 6px 0; font-size: 12px; }
.toggle { display: inline-flex; gap: 4px; align-items: center; background: none; border: none; padding: 0; cursor: pointer; color: inherit; font-size: 12px; }
.who { color: var(--color-text-lighter); }
.status { text-transform: capitalize; font-weight: 600; }
.origin { color: var(--color-text-lighter); }
.status.success { color: var(--color-success); }
.status.error { color: var(--color-error); }
.failed { color: var(--color-error); }
.status.running { color: var(--color-warning); }
/* Neutral rather than warning: a pending run is healthy, just slow — its
   batch is with Anthropic and may take hours. */
.status.pending { color: var(--color-text-lighter); }
.status.cancelled { color: var(--color-text-lighter); }
.link.stop { margin-left: auto; color: var(--color-error); }
.when { color: var(--color-text-lighter); }
.summary, .err { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 320px; }
.err { color: var(--color-error); }
.link { background: none; border: none; color: var(--color-primary-element); cursor: pointer; padding: 0; }
.detail { padding: 4px 0 10px 18px; font-size: 12px; }
.detail-text { white-space: pre-wrap; margin: 0 0 6px; }
.err-full { color: var(--color-error); }
.log { list-style: none; margin: 4px 0 0; padding: 0; max-height: 320px; overflow-y: auto; }
.log-entry { display: grid; grid-template-columns: auto auto minmax(0, 1fr); column-gap: 8px; padding: 2px 0; }
.log-ts { color: var(--color-text-lighter); white-space: nowrap; }
.log-level { font-weight: 600; }
.log-entry.warn .log-level { color: var(--color-warning); }
.log-entry.error .log-level { color: var(--color-error); }
.log-item { grid-column: 3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
a.log-item { color: var(--color-primary-element); }
.log-message { grid-column: 3; overflow-wrap: anywhere; }
</style>
