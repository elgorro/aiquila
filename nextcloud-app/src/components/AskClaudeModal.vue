<template>
	<NcModal :show="true"
		:name="t('aiquila', 'Ask Claude about {filename}', { filename: file.basename })"
		@close="onClose">
		<div class="aiquila-modal-content">
			<!-- File size warning -->
			<NcNoteCard v-if="isLargeFile" type="warning">
				<p>
					{{ t('aiquila', 'This file is {size}MB. Processing large files may take longer or fail.', { size: fileSizeMB }) }}
				</p>
				<p>
					{{ t('aiquila', 'Consider selecting a smaller portion or asking your administrator to increase limits.') }}
				</p>
			</NcNoteCard>

			<!-- Input field for question -->
			<NcTextField :value.sync="prompt"
				:label="t('aiquila', 'What would you like to know?')"
				:placeholder="t('aiquila', 'Ask a question about this file...')"
				type="textarea"
				:rows="4"
				class="aiquila-prompt-input" />

			<!-- Action buttons -->
			<div class="aiquila-actions">
				<NcButton type="secondary"
					:disabled="loading"
					@click="summarize">
					<template #icon>
						<FileDocumentIcon :size="20" />
					</template>
					{{ t('aiquila', 'Summarize') }}
				</NcButton>

				<NcButton type="primary"
					:disabled="!prompt || loading"
					@click="ask">
					<template #icon>
						<CommentQuestionIcon :size="20" />
					</template>
					{{ t('aiquila', 'Ask') }}
				</NcButton>
			</div>

			<!-- Loading indicator -->
			<NcLoadingIcon v-if="loading" :size="32" class="aiquila-loading" />

			<!-- Response display -->
			<div v-if="response" class="aiquila-response">
				<h3>{{ t('aiquila', 'Response') }}</h3>
				<div class="aiquila-response-text">
					{{ response }}
				</div>
			</div>

			<!-- Error display -->
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import CommentQuestionIcon from 'vue-material-design-icons/CommentQuestion.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AskClaudeModal',

	components: {
		NcModal,
		NcTextField,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		FileDocumentIcon,
		CommentQuestionIcon,
	},

	props: {
		file: {
			type: Object,
			required: true,
		},
		onClose: {
			type: Function,
			required: true,
		},
	},

	data() {
		return {
			prompt: '',
			response: '',
			error: '',
			loading: false,
		}
	},

	computed: {
		fileSizeMB() {
			return (this.file.size / (1024 * 1024)).toFixed(2)
		},

		isLargeFile() {
			return this.file.size > 5 * 1024 * 1024 // 5MB
		},

		filePath() {
			return this.file.path || this.file.source
		},
	},

	methods: {
		t,

		async getFileContent() {
			try {
				const response = await axios.get(this.file.source)
				return response.data
			} catch (err) {
				console.error('[AIquila] Error fetching file content:', err)
				throw new Error(t('aiquila', 'Failed to read file content'))
			}
		},

		async callClaudeAPI(endpoint, data) {
			try {
				const response = await axios.post(
					generateUrl(`/apps/aiquila/api/${endpoint}`),
					data
				)
				return response.data
			} catch (err) {
				console.error('[AIquila] API error:', err)
				throw new Error(
					err.response?.data?.error
					|| t('aiquila', 'Failed to communicate with Claude API')
				)
			}
		},

		async summarize() {
			this.loading = true
			this.error = ''
			this.response = ''

			try {
				const content = await this.getFileContent()
				const result = await this.callClaudeAPI('summarize', { content })

				if (result.error) {
					this.error = result.error
				} else {
					this.response = result.response
				}
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},

		async ask() {
			if (!this.prompt) return

			this.loading = true
			this.error = ''
			this.response = ''

			try {
				const content = await this.getFileContent()
				const result = await this.callClaudeAPI('ask', {
					prompt: this.prompt,
					context: content,
				})

				if (result.error) {
					this.error = result.error
				} else {
					this.response = result.response
				}
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.aiquila-modal-content {
	padding: 20px;
	min-width: 500px;
	max-width: 700px;
}

.aiquila-prompt-input {
	margin: 20px 0;
}

.aiquila-actions {
	display: flex;
	gap: 10px;
	margin: 20px 0;
}

.aiquila-loading {
	display: flex;
	justify-content: center;
	margin: 20px 0;
}

.aiquila-response {
	margin-top: 20px;
	padding: 15px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.aiquila-response h3 {
	margin-top: 0;
	margin-bottom: 10px;
}

.aiquila-response-text {
	white-space: pre-wrap;
	font-family: var(--font-face);
	line-height: 1.6;
}
</style>
