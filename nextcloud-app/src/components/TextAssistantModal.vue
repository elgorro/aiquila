<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!-- No `name`: NcModal draws it in the page-wide header bar, over the Viewer's
		own title. The heading lives in the dialog instead. -->
	<NcModal size="normal"
		label-id="aiquila-text-assistant-title"
		@close="close">
		<div class="aiquila-text-assistant">
			<h2 id="aiquila-text-assistant-title" class="aiquila-text-assistant__title">
				{{ typeDef ? typeDef.name : t('aiquila', 'AI action') }}
			</h2>
			<NcLoadingIcon v-if="loadingTypes" :size="32" />

			<template v-else-if="current === null">
				<NcSelect v-if="!taskType"
					v-model="selectedType"
					:input-label="t('aiquila', 'Action')"
					:options="typeOptions"
					label="name"
					:clearable="false" />

				<p v-if="typeDef && typeDef.description" class="aiquila-text-assistant__hint">
					{{ typeDef.description }}
				</p>

				<template v-for="field in fields" :key="field.key">
					<NcSelect v-if="field.type === 'Enum'"
						v-model="enumValues[field.key]"
						:input-label="field.name"
						:options="field.options"
						label="name"
						:clearable="false" />
					<NcTextArea v-else
						v-model="textValues[field.key]"
						:label="field.name"
						:placeholder="field.description"
						rows="6"
						resize="vertical" />
				</template>
			</template>

			<template v-else>
				<div v-if="isPending" class="aiquila-text-assistant__running">
					<NcLoadingIcon :size="20" />
					<span>{{ t('aiquila', 'Working… You can close this dialog; the result will be listed in the editor\'s Assistant menu.') }}</span>
				</div>
				<div v-else-if="isSuccessful" class="aiquila-text-assistant__output">
					{{ outputText }}
				</div>
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="aiquila-text-assistant__actions">
				<template v-if="current === null">
					<NcButton variant="primary"
						:disabled="!canRun || scheduling"
						@click="run">
						{{ t('aiquila', 'Run') }}
					</NcButton>
				</template>
				<template v-else-if="isSuccessful">
					<NcButton v-if="!task" @click="reset">
						{{ t('aiquila', 'Try again') }}
					</NcButton>
					<NcButton @click="copy">
						{{ t('aiquila', 'Copy') }}
					</NcButton>
					<NcButton v-for="(button, i) in actionButtons"
						:key="i"
						:variant="button.variant || 'secondary'"
						@click="onAction(button)">
						{{ button.label || button.title }}
					</NcButton>
				</template>
				<template v-else-if="!isPending && !task">
					<NcButton @click="reset">
						{{ t('aiquila', 'Try again') }}
					</NcButton>
				</template>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

import { getTask, listTaskTypes, scheduleTask } from '../api.js'

const POLL_INTERVAL_MS = 2000
const PENDING = ['STATUS_SCHEDULED', 'STATUS_RUNNING', 'STATUS_UNKNOWN']

/** A task type this dialog can drive: text and choice inputs, a text output. */
function isSupported(def) {
	return def.outputShape?.output?.type === 'Text'
		&& Object.values(def.inputShape || {}).every(f => f.type === 'Text' || f.type === 'Enum')
}

export default {
	name: 'TextAssistantModal',

	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
	},

	props: {
		appId: {
			type: String,
			required: true,
		},
		customId: {
			type: String,
			default: '',
		},
		/** Task type to run; null lets the user pick one. */
		taskType: {
			type: String,
			default: null,
		},
		/** Initial input values, keyed by input name (Text passes `input`). */
		inputs: {
			type: Object,
			default: () => ({}),
		},
		/** The caller's buttons; each gets `onClick(task)` with the finished task. */
		actionButtons: {
			type: Array,
			default: () => [],
		},
		/** An existing task to show instead of the form. */
		task: {
			type: Object,
			default: null,
		},
		onClose: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			loadingTypes: true,
			types: {},
			selectedType: null,
			textValues: { ...this.inputs },
			enumValues: {},
			current: this.task,
			scheduling: false,
			error: '',
			pollTimer: null,
		}
	},

	computed: {
		typeOptions() {
			return Object.entries(this.types)
				.filter(([, def]) => isSupported(def))
				.map(([id, def]) => ({ id, name: def.name }))
		},
		typeId() {
			return this.current?.type || this.taskType || this.selectedType?.id || null
		},
		typeDef() {
			return this.typeId ? this.types[this.typeId] || null : null
		},
		fields() {
			if (!this.typeDef) return []
			return Object.entries(this.typeDef.inputShape || {}).map(([key, f]) => ({
				key,
				...f,
				options: f.type === 'Enum' ? this.typeDef.inputShapeEnumValues?.[key] || [] : [],
			}))
		},
		canRun() {
			return this.typeDef !== null
				&& isSupported(this.typeDef)
				&& this.fields.every(f => f.type === 'Enum'
					? this.enumValues[f.key]
					: (this.textValues[f.key] || '').trim() !== '')
		},
		status() {
			return this.current?.status || null
		},
		isPending() {
			return PENDING.includes(this.status)
		},
		isSuccessful() {
			return this.status === 'STATUS_SUCCESSFUL'
		},
		outputText() {
			return this.current?.output?.output ?? ''
		},
	},

	watch: {
		typeDef(def) {
			if (def) this.applyEnumDefaults(def)
		},
	},

	async mounted() {
		try {
			const { data } = await listTaskTypes()
			this.types = data.ocs.data.types
		} catch {
			this.error = t('aiquila', 'Could not load the available AI actions.')
		} finally {
			this.loadingTypes = false
		}
		if (this.taskType && this.typeDef === null && !this.error) {
			this.error = t('aiquila', 'This AI action is not available.')
		}
		if (this.current) this.track()
	},

	beforeUnmount() {
		clearTimeout(this.pollTimer)
	},

	methods: {
		t,

		applyEnumDefaults(def) {
			for (const [key, f] of Object.entries(def.inputShape || {})) {
				if (f.type !== 'Enum' || this.enumValues[key]) continue
				const options = def.inputShapeEnumValues?.[key] || []
				const fallback = def.inputShapeDefaults?.[key]
				this.enumValues[key] = options.find(o => o.value === fallback) || options[0] || null
			}
		},

		async run() {
			this.scheduling = true
			this.error = ''
			const input = {}
			for (const f of this.fields) {
				input[f.key] = f.type === 'Enum' ? this.enumValues[f.key].value : this.textValues[f.key]
			}
			try {
				const { data } = await scheduleTask({
					type: this.typeId,
					input,
					appId: this.appId,
					customId: this.customId,
				})
				this.current = data.ocs.data.task
				this.track()
			} catch (err) {
				this.error = err.response?.data?.ocs?.data?.message
					|| err.message
					|| t('aiquila', 'Could not start the AI action.')
			} finally {
				this.scheduling = false
			}
		},

		/** Poll the task until it leaves the pending states. */
		track() {
			clearTimeout(this.pollTimer)
			if (this.status === 'STATUS_FAILED' || this.status === 'STATUS_CANCELLED') {
				this.error = this.current.errorMessage || t('aiquila', 'The AI action failed.')
				return
			}
			if (!this.isPending) return
			this.pollTimer = setTimeout(async () => {
				try {
					const { data } = await getTask(this.current.id)
					this.current = data.ocs.data.task
				} catch {
					// Transient; try again on the next tick.
				}
				this.track()
			}, POLL_INTERVAL_MS)
		},

		reset() {
			clearTimeout(this.pollTimer)
			this.current = null
			this.error = ''
		},

		async copy() {
			try {
				await navigator.clipboard.writeText(this.outputText)
				showSuccess(t('aiquila', 'Result copied'))
			} catch {
				showError(t('aiquila', 'Could not copy the result'))
			}
		},

		onAction(button) {
			const task = this.current
			this.close()
			button.onClick?.(task)
		},

		close() {
			clearTimeout(this.pollTimer)
			this.onClose()
		},
	},
}
</script>

<style scoped>
.aiquila-text-assistant {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.aiquila-text-assistant__title {
	margin: 0;
	font-size: 1.25em;
}

.aiquila-text-assistant__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.aiquila-text-assistant__running {
	display: flex;
	align-items: center;
	gap: 8px;
}

.aiquila-text-assistant__output {
	white-space: pre-wrap;
	max-height: 50vh;
	overflow-y: auto;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.aiquila-text-assistant__actions {
	display: flex;
	justify-content: flex-end;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
