<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="cowork-view">
		<div class="cowork-header">
			<h2>{{ t('aiquila', 'Cowork') }}</h2>
			<p class="subtitle">{{ t('aiquila', 'Persistent, scheduled AI tasks over your Nextcloud data.') }}</p>
		</div>

		<!-- Template gallery -->
		<div v-if="!editing" class="templates">
			<h3>{{ t('aiquila', 'Add a coworker') }}</h3>
			<div class="template-cards">
				<button
					v-for="tpl in templates"
					:key="tpl.id"
					class="template-card"
					:disabled="busy"
					@click="addFromTemplate(tpl)">
					<div class="card-icon">🏷️</div>
					<h4>{{ tpl.title }}</h4>
					<p>{{ tpl.description }}</p>
				</button>
				<button class="template-card custom" :disabled="busy" @click="startCreate">
					<div class="card-icon">＋</div>
					<h4>{{ t('aiquila', 'Custom coworker') }}</h4>
					<p>{{ t('aiquila', 'Configure a task, provider and schedule yourself.') }}</p>
				</button>
			</div>
		</div>

		<!-- Editor -->
		<CoworkerForm
			v-if="editing"
			:model="editModel"
			:task-types="taskTypes"
			@saved="onSaved"
			@cancel="editing = false" />

		<!-- List -->
		<div v-if="!editing" class="list">
			<h3>{{ t('aiquila', 'Your coworkers') }}</h3>
			<p v-if="loading" class="muted">{{ t('aiquila', 'Loading…') }}</p>
			<p v-else-if="coworkers.length === 0" class="muted">{{ t('aiquila', 'No coworkers yet. Add one above.') }}</p>
			<div v-for="cw in coworkers" :key="cw.id" class="coworker-row">
				<div class="row-main">
					<span class="dot" :class="statusClass(cw)" />
					<span class="title">{{ cw.title }}</span>
					<span class="badges">
						<span class="badge">{{ providerLabel(cw.model) }}</span>
						<span class="badge">{{ cw.inputPath }}</span>
						<span class="badge mono">{{ cw.cronSchedule }}</span>
						<span v-if="cw.lastStatus" class="badge" :class="cw.lastStatus">{{ cw.lastStatus }}</span>
					</span>
				</div>
				<div class="row-actions">
					<button :disabled="busy" @click="runNow(cw)">{{ t('aiquila', 'Run now') }}</button>
					<button v-if="!cw.paused" :disabled="busy" @click="togglePause(cw, true)">{{ t('aiquila', 'Pause') }}</button>
					<button v-else :disabled="busy" @click="togglePause(cw, false)">{{ t('aiquila', 'Resume') }}</button>
					<button :disabled="busy" @click="startEdit(cw)">{{ t('aiquila', 'Edit') }}</button>
					<button :disabled="busy" @click="toggleHistory(cw)">{{ historyFor === cw.id ? t('aiquila', 'Hide') : t('aiquila', 'History') }}</button>
					<button class="danger" :disabled="busy" @click="remove(cw)">{{ t('aiquila', 'Delete') }}</button>
				</div>
				<CoworkerRunHistory v-if="historyFor === cw.id" :coworker-id="cw.id" />
			</div>
		</div>

		<p v-if="error" class="form-error">{{ error }}</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import CoworkerForm from './CoworkerForm.vue'
import CoworkerRunHistory from './CoworkerRunHistory.vue'
import {
	listCoworkers, listCoworkerTemplates, createCoworkerFromTemplate,
	deleteCoworker, pauseCoworker, resumeCoworker, runCoworker,
} from '../api.js'

export default {
	name: 'CoworkView',
	components: { CoworkerForm, CoworkerRunHistory },
	data() {
		return {
			coworkers: [],
			templates: [],
			taskTypes: [],
			loading: true,
			busy: false,
			editing: false,
			editModel: {},
			historyFor: null,
			error: '',
		}
	},
	mounted() {
		this.load()
	},
	methods: { t,
		async load() {
			this.loading = true
			try {
				const [cws, tpls] = await Promise.all([listCoworkers(), listCoworkerTemplates()])
				this.coworkers = cws.data
				this.templates = tpls.data.templates || []
				this.taskTypes = tpls.data.taskTypes || []
			} catch (e) {
				this.error = e?.response?.data?.error || t('aiquila', 'Could not load coworkers')
			} finally {
				this.loading = false
			}
		},
		statusClass(cw) {
			if (!cw.isActive) return 'disabled'
			if (cw.paused) return 'paused'
			return 'active'
		},
		providerLabel(model) {
			if (model === 'mistral') return 'Pixtral'
			if (model === 'anthropic') return 'Claude'
			return model || '—'
		},
		startCreate() {
			this.editModel = {}
			this.editing = true
		},
		startEdit(cw) {
			this.editModel = cw
			this.editing = true
		},
		onSaved() {
			this.editing = false
			this.load()
		},
		async addFromTemplate(tpl) {
			this.busy = true
			this.error = ''
			try {
				await createCoworkerFromTemplate(tpl.id)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || t('aiquila', 'Could not add coworker')
			} finally {
				this.busy = false
			}
		},
		async runNow(cw) {
			await this.act(() => runCoworker(cw.id))
		},
		async togglePause(cw, pause) {
			await this.act(() => (pause ? pauseCoworker(cw.id) : resumeCoworker(cw.id)))
		},
		async remove(cw) {
			if (!window.confirm(t('aiquila', 'Delete this coworker and its run history?'))) return
			await this.act(() => deleteCoworker(cw.id))
		},
		toggleHistory(cw) {
			this.historyFor = this.historyFor === cw.id ? null : cw.id
		},
		async act(fn) {
			this.busy = true
			this.error = ''
			try {
				await fn()
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || t('aiquila', 'Action failed')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cowork-view { padding: 32px 24px; max-width: 880px; margin: 0 auto; }
.cowork-header { margin-bottom: 24px; }
.cowork-header h2 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.subtitle { color: var(--color-text-lighter); font-size: 14px; margin: 0; }
h3 { font-size: 15px; margin: 24px 0 12px; }
.template-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.template-card {
	text-align: left;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
}
.template-card:hover { background: var(--color-background-hover); }
.template-card h4 { margin: 6px 0 4px; font-size: 14px; }
.template-card p { margin: 0; font-size: 12px; color: var(--color-text-lighter); }
.card-icon { font-size: 22px; }
.muted { color: var(--color-text-lighter); font-size: 13px; }
.coworker-row { padding: 12px 0; border-bottom: 1px solid var(--color-border); }
.row-main { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.title { font-weight: 600; }
.badges { display: flex; gap: 6px; flex-wrap: wrap; }
.badge { font-size: 11px; padding: 2px 6px; border-radius: var(--border-radius); background: var(--color-background-dark); color: var(--color-text-lighter); }
.badge.mono { font-family: monospace; }
.badge.success { color: var(--color-success); }
.badge.error { color: var(--color-error); }
.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.dot.active { background: var(--color-success); }
.dot.paused { background: var(--color-warning); }
.dot.disabled { background: var(--color-border-dark); }
.row-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
.row-actions button { font-size: 12px; }
.danger { color: var(--color-error); }
.form-error { color: var(--color-error); font-size: 13px; }
</style>
