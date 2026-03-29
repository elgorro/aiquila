<template>
	<NcModal
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

			<!-- Image preview -->
			<div v-if="isImage" class="aiquila-image-preview">
				<img v-if="previewUrl"
					:src="previewUrl"
					:alt="file.basename"
					class="preview-image" />
				<NcLoadingIcon v-else :size="32" />
			</div>

			<!-- Input field for question -->
			<NcTextField v-model="prompt"
				:label="t('aiquila', 'What would you like to know?')"
				:placeholder="isImage ? t('aiquila', 'Ask a question about this image...') : t('aiquila', 'Ask a question about this file...')"
				type="textarea"
				:rows="4"
				class="aiquila-prompt-input" />

			<!-- Action buttons -->
			<div class="aiquila-actions">
				<NcButton type="secondary"
					:disabled="loading"
					@click="summarize">
					<template #icon>
						<FileDocumentIcon v-if="!isImage" :size="20" />
						<ImageIcon v-else :size="20" />
					</template>
					{{ isImage ? t('aiquila', 'Describe') : t('aiquila', 'Summarize') }}
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
import { NcModal, NcTextField, NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import CommentQuestionIcon from 'vue-material-design-icons/CommentQuestion.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'

import { isImageMime, getFilePreview, analyzeFile } from '../api.js'

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
		ImageIcon,
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
			previewUrl: null,
		}
	},

	computed: {
		fileSizeMB() {
			return (this.file.size / (1024 * 1024)).toFixed(2)
		},

		isLargeFile() {
			return this.file.size > 5 * 1024 * 1024 // 5MB
		},

		isImage() {
			return isImageMime(this.file.mime)
		},

		isPdf() {
			return this.file.mime === 'application/pdf'
		},

		filePath() {
			return this.file.path || this.file.source
		},
	},

	mounted() {
		if (this.isImage) {
			this.loadPreview()
		}
	},

	methods: {
		t,

		async loadPreview() {
			try {
				const { data } = await getFilePreview(this.filePath, 400, 400)
				this.previewUrl = 'data:' + data.mimeType + ';base64,' + data.content
			} catch {
				// Preview not available
			}
		},

		async getFileContent() {
			try {
				const response = await axios.get(this.file.source)
				return response.data
			} catch (err) {
				console.error('[AIquila] Error fetching file content:', err)
				throw new Error(t('aiquila', 'Failed to read file content'))
			}
		},

		async summarize() {
			this.loading = true
			this.error = ''
			this.response = ''

			try {
				if (this.isImage || this.isPdf) {
					const defaultPrompt = this.isImage
						? 'Describe this image in detail.'
						: 'Summarize this document.'
					const result = await this.analyzeViaBackend(defaultPrompt)
					this.response = result.response
				} else {
					const content = await this.getFileContent()
					const result = await this.callClaudeAPI('summarize', { content })
					if (result.error) {
						this.error = result.error
					} else {
						this.response = result.response
					}
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
				if (this.isImage || this.isPdf) {
					const result = await this.analyzeViaBackend(this.prompt)
					this.response = result.response
				} else {
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
				}
			} catch (err) {
				this.error = err.message
			} finally {
				this.loading = false
			}
		},

		async analyzeViaBackend(prompt) {
			try {
				const { data } = await analyzeFile(this.filePath, prompt)
				if (data.error) {
					throw new Error(data.error)
				}
				return data
			} catch (err) {
				throw new Error(
					err.response?.data?.error
					|| err.message
					|| t('aiquila', 'Failed to analyze file'),
				)
			}
		},

		async callClaudeAPI(endpoint, data) {
			try {
				const { generateUrl } = await import('@nextcloud/router')
				const response = await axios.post(
					generateUrl(`/apps/aiquila/api/${endpoint}`),
					data,
				)
				return response.data
			} catch (err) {
				console.error('[AIquila] API error:', err)
				throw new Error(
					err.response?.data?.error
					|| t('aiquila', 'Failed to communicate with Claude API'),
				)
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

.aiquila-image-preview {
	display: flex;
	justify-content: center;
	margin-bottom: 16px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	min-height: 100px;
	align-items: center;
}

.preview-image {
	max-width: 100%;
	max-height: 300px;
	border-radius: var(--border-radius);
	object-fit: contain;
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
