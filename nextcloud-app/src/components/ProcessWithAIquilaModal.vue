<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcDialog :name="t('aiquila', 'Process with AIquila')"
		size="normal"
		is-form
		@submit="onSubmit"
		@closing="onClose">
		<div class="aiquila-process">
			<p class="selection">
				{{ selectionText }}
			</p>
			<NcNoteCard v-if="nonTextFiles > 0" type="info">
				{{ n('aiquila', '{count} selected file is not a text or PDF document and will be skipped.', '{count} selected files are not text or PDF documents and will be skipped.', nonTextFiles, { count: nonTextFiles }) }}
			</NcNoteCard>
			<NcNoteCard v-if="overCap" type="error">
				{{ t('aiquila', 'A run processes at most {max} files. Select fewer, or select their folder instead.', { max: limits.maxItems }) }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else-if="operations.length > 0">
				<fieldset class="group">
					<legend>{{ t('aiquila', 'Operation') }}</legend>
					<NcCheckboxRadioSwitch v-for="op in operations"
						:key="op.id"
						v-model="operation"
						:value="op.id"
						name="aiquila-operation"
						type="radio">
						{{ operationLabel(op) }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<fieldset v-if="operation === 'summarize'" class="group">
					<legend>{{ t('aiquila', 'Summary style') }}</legend>
					<NcCheckboxRadioSwitch v-for="s in styles"
						:key="s.id"
						v-model="style"
						:value="s.id"
						name="aiquila-style"
						type="radio">
						{{ s.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<NcTextField v-if="operation === 'translate'"
					v-model="targetLanguage"
					:label="t('aiquila', 'Target language')"
					:placeholder="t('aiquila', 'For example German')"
					:error="targetLanguage !== '' && !languageValid"
					:helper-text="targetLanguage !== '' && !languageValid ? t('aiquila', 'A language name in letters, such as Brazilian Portuguese') : ''" />

				<fieldset v-if="limits.batchAvailable" class="group">
					<legend>{{ t('aiquila', 'When') }}</legend>
					<NcCheckboxRadioSwitch v-model="mode"
						value="batch"
						name="aiquila-mode"
						type="radio">
						{{ t('aiquila', 'Batch: half price, results within 24 hours') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="mode"
						value="immediate"
						name="aiquila-mode"
						type="radio">
						{{ t('aiquila', 'Now: full price, results in minutes') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<fieldset v-if="folderIds.length > 0" class="group">
					<legend>{{ t('aiquila', 'Folders') }}</legend>
					<NcCheckboxRadioSwitch v-model="recursive">
						{{ t('aiquila', 'Include subfolders') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="force">
						{{ t('aiquila', 'Redo documents whose output is already up to date') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<div class="group output">
					<span class="output-label">{{ t('aiquila', 'Save results') }}</span>
					<span class="output-value">{{ outputFolder || t('aiquila', 'beside each document') }}</span>
					<NcButton variant="tertiary" @click="pickOutputFolder">
						{{ t('aiquila', 'Choose folder…') }}
					</NcButton>
					<NcButton v-if="outputFolder" variant="tertiary" @click="outputFolder = ''">
						{{ t('aiquila', 'Reset') }}
					</NcButton>
				</div>

				<p class="hint">
					{{ t('aiquila', 'Text documents only. Results are written as Markdown files ({example}), and each processed document is tagged.', { example: outputExample }) }}
				</p>
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('aiquila', 'Cancel') }}
			</NcButton>
			<NcButton type="submit"
				variant="primary"
				:disabled="!canStart">
				<template #icon>
					<NcLoadingIcon v-if="starting" :size="20" />
				</template>
				{{ t('aiquila', 'Start') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { FilePickerClosed, getFilePickerBuilder, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import { batchRunUrl, getBatchRunOperations, startBatchRun } from '../api.js'

// Mirrors BatchDocumentRunner::DEFAULT_MIME_TYPES; the server decides.
const isText = (mime) => typeof mime === 'string' && (mime.startsWith('text/') || mime === 'application/json' || mime === 'application/pdf')

const LANGUAGE = /^[A-Za-z][A-Za-z \-]{1,31}$/

export default {
	name: 'ProcessWithAIquilaModal',

	components: {
		NcButton,
		NcDialog,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** @nextcloud/files Nodes: files and/or folders. */
		nodes: {
			type: Array,
			required: true,
		},
		onClose: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			starting: false,
			error: '',
			operations: [],
			limits: { maxItems: 200, batchAvailable: false },
			operation: 'summarize',
			style: 'brief',
			targetLanguage: '',
			mode: 'immediate',
			recursive: true,
			force: false,
			outputFolder: '',
		}
	},

	computed: {
		files() {
			return this.nodes.filter(node => node.type === 'file')
		},
		fileIds() {
			return this.files.map(node => node.fileid)
		},
		folderIds() {
			return this.nodes.filter(node => node.type === 'folder').map(node => node.fileid)
		},
		nonTextFiles() {
			return this.files.filter(node => !isText(node.mime)).length
		},
		overCap() {
			return this.fileIds.length > this.limits.maxItems || this.folderIds.length > this.limits.maxItems
		},
		selectionText() {
			const files = n('aiquila', '%n file', '%n files', this.fileIds.length)
			const folders = n('aiquila', '%n folder', '%n folders', this.folderIds.length)
			if (this.folderIds.length === 0) {
				return t('aiquila', '{files} selected.', { files })
			}
			if (this.fileIds.length === 0) {
				return t('aiquila', '{folders} selected. Up to {max} files are processed per run.', { folders, max: this.limits.maxItems })
			}
			return t('aiquila', '{files} and {folders} selected. Up to {max} files are processed per run.', { files, folders, max: this.limits.maxItems })
		},
		styles() {
			return [
				{ id: 'brief', label: t('aiquila', 'Brief') },
				{ id: 'detailed', label: t('aiquila', 'Detailed') },
				{ id: 'bullets', label: t('aiquila', 'Bullet points') },
			]
		},
		languageValid() {
			return LANGUAGE.test(this.targetLanguage.trim())
		},
		outputExample() {
			if (this.operation === 'translate') {
				const lang = this.languageValid ? this.targetLanguage.trim().toLowerCase().replace(/ /g, '-') : 'german'
				return `report.${lang}.md`
			}
			return 'report.summary.md'
		},
		canStart() {
			if (this.loading || this.starting || this.overCap || this.operations.length === 0) {
				return false
			}
			return this.operation !== 'translate' || this.languageValid
		},
	},

	async mounted() {
		try {
			const { data } = await getBatchRunOperations()
			this.operations = data.operations
			this.limits = data
			this.mode = data.defaultMode
		} catch (e) {
			this.error = t('aiquila', 'AIquila could not be reached. Try again later.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		n,
		operationLabel(op) {
			return {
				summarize: t('aiquila', 'Summarize'),
				translate: t('aiquila', 'Translate'),
			}[op.id] || op.label
		},
		onSubmit(event) {
			event?.preventDefault?.()
			this.start()
		},
		async pickOutputFolder() {
			try {
				const picker = getFilePickerBuilder(t('aiquila', 'Save results in'))
					.setType(1)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.build()
				const path = await picker.pick()
				this.outputFolder = path && path !== '/' ? path : ''
			} catch (e) {
				if (!(e instanceof FilePickerClosed)) {
					this.error = t('aiquila', 'The folder picker could not be opened.')
				}
			}
		},
		async start() {
			if (!this.canStart) {
				return
			}
			this.starting = true
			this.error = ''
			const options = {}
			if (this.operation === 'summarize') {
				options.style = this.style
			} else {
				options.targetLanguage = this.targetLanguage.trim()
			}
			if (this.folderIds.length > 0) {
				options.recursive = this.recursive
				options.force = this.force
			}
			if (this.outputFolder) {
				options.outputFolder = this.outputFolder
			}
			try {
				const { data: run } = await startBatchRun({
					operation: this.operation,
					options,
					mode: this.limits.batchAvailable ? this.mode : null,
					fileIds: this.fileIds,
					folderIds: this.folderIds,
				})
				showSuccess(
					this.mode === 'batch' && this.limits.batchAvailable
						? t('aiquila', 'Submitted to AIquila. Results arrive within 24 hours. Click to follow the run.')
						: t('aiquila', 'AIquila is processing your selection. Click to follow the run.'),
					{ onClick: () => window.open(batchRunUrl(run.id), '_blank', 'noopener') },
				)
				this.onClose()
			} catch (e) {
				// The server's message names the problem: a limit, a language,
				// a file that is gone.
				this.error = e?.response?.data?.error || t('aiquila', 'The run could not be started.')
			} finally {
				this.starting = false
			}
		},
	},
}
</script>

<style scoped>
.aiquila-process {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.selection, .hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.group {
	display: flex;
	flex-direction: column;
	gap: 2px;
	border: none;
	padding: 0;
	margin: 0;
}

.group legend, .output-label {
	font-weight: 600;
	margin-bottom: 4px;
}

.output {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.output-value {
	overflow-wrap: anywhere;
}
</style>
