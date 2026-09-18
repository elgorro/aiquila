<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="chat-input"
		@dragover.prevent="onDragOver"
		@dragenter.prevent="onDragEnter"
		@dragleave.prevent="onDragLeave"
		@drop.prevent="onDrop">
		<!-- Drop overlay -->
		<div v-if="dragging" class="drop-overlay">
			{{ t('aiquila', 'Drop files here') }}
		</div>

		<div v-if="uploads.length > 0" class="upload-progress">
			<div v-for="upload in uploads" :key="upload.name" class="upload-row">
				<span class="upload-name" :title="upload.name">{{ upload.name }}</span>
				<NcProgressBar :value="upload.percent" />
				<span class="upload-percent">{{ upload.percent }}%</span>
			</div>
		</div>

		<div v-if="attachedFiles.length > 0" class="file-chips">
			<span v-for="file in attachedFiles"
				:key="file.path"
				:class="['file-chip', { 'file-chip--dir': file.mime === 'httpd/unix-directory' }]">
				<img v-if="previews[file.path]"
					:src="previews[file.path]"
					class="file-thumb"
					:alt="file.name" />
				<span class="name" :title="file.path">{{ file.mime === 'httpd/unix-directory' ? '📁 ' + file.name : file.name }}</span>
				<span v-if="file.size" class="size">{{ humanSize(file.size) }}</span>
				<button class="remove" :title="t('aiquila', 'Remove')" @click="removeFile(file.path)">
					✕
				</button>
			</span>
		</div>
		<div class="input-wrapper">
			<div v-if="menuVisible" class="slash-menu">
				<div v-for="(cmd, i) in filteredCommands"
					:key="cmd.id"
					:class="['slash-menu-item', { active: i === menuActiveIndex }]"
					@click="selectCommand(cmd)">
					<span class="icon">{{ cmd.icon }}</span>
					<span class="label">{{ cmd.label }}</span>
					<span class="description">{{ cmd.description }}</span>
				</div>
			</div>
			<textarea ref="input"
				v-model="prompt"
				:placeholder="t('aiquila', 'Ask Claude anything… Type / for commands')"
				:disabled="disabled"
				rows="3"
				@input="onInput"
				@keydown="onKeydown"
				@paste="onPaste" />
		</div>
		<div class="input-actions">
			<NcButton type="tertiary"
				:disabled="disabled"
				:title="t('aiquila', 'Attach files')"
				@click="handleAddFile('')">
				<template #icon>
					<PaperclipIcon :size="20" />
				</template>
			</NcButton>
			<NcButton type="primary"
				:disabled="disabled || (!prompt.trim() && attachedFiles.length === 0)"
				@click="onSend">
				{{ t('aiquila', 'Send') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import { getFilePickerBuilder, FilePickerClosed, showError } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'
import '@nextcloud/dialogs/style.css'

import { getFileInfo, getFilePreview, isImageMime } from '../api.js'

// Attachments dropped or pasted into the chat land here.
const UPLOAD_FOLDER = '/AIquila Uploads'

// Matches the chunk size the Files app uses. Anything bigger than one chunk is
// uploaded through the chunked endpoint rather than as a single PUT.
const CHUNK_SIZE = 10 * 1024 * 1024

/**
 * Percent-encode each segment of a DAV path, leaving the separators alone.
 * The upload folder has a space in its name, so the raw path is not a URL.
 */
function encodeDavPath(path) {
	return path.split('/').map(encodeURIComponent).join('/')
}

const SLASH_COMMANDS = [
	{
		id: 'add-file',
		label: '/add-file',
		icon: '📎',
		description: 'Attach a file from Nextcloud',
	},
	{
		id: 'add-directory',
		label: '/add-directory',
		icon: '📁',
		description: 'Attach a directory listing as context',
	},
	{
		id: 'add-project',
		label: '/add-project',
		icon: '📦',
		description: 'Attach a project context',
	},
	{
		id: 'remove-project',
		label: '/remove-project',
		icon: '🗑️',
		description: 'Detach project from conversation',
	},
	{
		id: 'verbose',
		label: '/verbose',
		icon: '🔍',
		description: 'Toggle verbose mode (show detailed stats)',
	},
	{
		id: 'search',
		label: '/search',
		icon: '🔎',
		description: 'Search across conversation messages',
	},
	{
		id: 'effort',
		label: '/effort',
		icon: '⚡',
		description: 'Set effort for this conversation — allowed values depend on the provider and model (e.g. /effort:high; no value resets to default)',
	},
	{
		id: 'thinking',
		label: '/thinking',
		icon: '🧠',
		description: 'Toggle adaptive thinking for this conversation (/thinking:on or /thinking:off; no value resets to default)',
	},
	{
		id: 'thinking-budget',
		label: '/thinking-budget',
		icon: '🎚️',
		description: 'Cap thinking at a fixed number of tokens (e.g. /thinking-budget:8000; no value or "off" returns to adaptive)',
	},
	{
		id: 'fast',
		label: '/fast',
		icon: '🚀',
		description: 'Faster output at premium pricing, on Opus 5 and Opus 4.8 only (/fast:on or /fast:off; no value resets to the instance default)',
	},
]

export default {
	name: 'ChatInput',
	components: {
		NcButton,
		NcProgressBar,
		PaperclipIcon,
	},
	props: {
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['send', 'command'],
	data() {
		return {
			prompt: '',
			attachedFiles: [],
			previews: {},
			menuVisible: false,
			menuActiveIndex: 0,
			filteredCommands: [],
			dragging: false,
			dragCounter: 0,
			uploads: [],
		}
	},
	methods: {
		t,
		onInput() {
			const text = this.prompt
			if (text.startsWith('/') && !text.includes(' ')) {
				const filter = text.substring(1).split(':')[0]
				this.filteredCommands = SLASH_COMMANDS.filter(cmd =>
					cmd.label.startsWith('/' + filter),
				)
				this.menuVisible = this.filteredCommands.length > 0
				this.menuActiveIndex = 0
			} else {
				this.menuVisible = false
			}
		},
		onKeydown(e) {
			if (this.menuVisible) {
				if (e.key === 'ArrowDown') {
					e.preventDefault()
					this.menuActiveIndex = Math.min(this.menuActiveIndex + 1, this.filteredCommands.length - 1)
					return
				}
				if (e.key === 'ArrowUp') {
					e.preventDefault()
					this.menuActiveIndex = Math.max(this.menuActiveIndex - 1, 0)
					return
				}
				if (e.key === 'Enter') {
					e.preventDefault()
					if (this.menuActiveIndex >= 0 && this.filteredCommands[this.menuActiveIndex]) {
						this.selectCommand(this.filteredCommands[this.menuActiveIndex])
					}
					return
				}
				if (e.key === 'Escape') {
					e.preventDefault()
					this.menuVisible = false
					this.prompt = ''
					return
				}
			}

			if (e.key === 'Enter' && !e.shiftKey) {
				const text = this.prompt.trim()
				if (text.startsWith('/')) {
					e.preventDefault()
					const cmdName = text.substring(1).split(':')[0].split(' ')[0]
					const cmd = SLASH_COMMANDS.find(c => c.id === cmdName)
					if (cmd) {
						this.selectCommand(cmd)
						return
					}
				}
				e.preventDefault()
				this.onSend()
			}
		},
		selectCommand(cmd) {
			const text = this.prompt.trim()
			const colonIdx = text.indexOf(':')
			const args = colonIdx !== -1 ? text.substring(colonIdx + 1).trim() : ''
			this.prompt = ''
			this.menuVisible = false

			switch (cmd.id) {
			case 'add-file':
				this.handleAddFile(args)
				break
			case 'add-directory':
				this.handleAddDirectory(args)
				break
			case 'add-project':
				this.$emit('command', { type: 'add-project', args })
				break
			case 'remove-project':
				this.$emit('command', { type: 'remove-project' })
				break
			case 'verbose':
				this.$emit('command', { type: 'toggle-verbose' })
				break
			case 'search':
				this.$emit('command', { type: 'search', args })
				break
			case 'effort':
				this.$emit('command', { type: 'set-effort', args })
				break
			case 'thinking':
				this.$emit('command', { type: 'set-thinking', args })
				break
			case 'thinking-budget':
				this.$emit('command', { type: 'set-thinking-budget', args })
				break
			case 'fast':
				this.$emit('command', { type: 'set-fast', args })
				break
			}
		},
		async handleAddFile(args) {
			if (args) {
				try {
					const { data: info } = await getFileInfo(args)
					this.addFile({
						path: args,
						name: info.name || args.split('/').pop(),
						size: info.size || 0,
						mime: info.mimeType || 'application/octet-stream',
					})
				} catch {
					console.error('File not found:', args)
				}
			} else {
				try {
					const picker = getFilePickerBuilder('Select files')
						.setMultiSelect(true)
						.setType(1)
						.allowDirectories(false)
						.build()

					const paths = await picker.pick()
					const pathList = Array.isArray(paths) ? paths : [paths]

					for (const p of pathList) {
						try {
							const { data: info } = await getFileInfo(p)
							this.addFile({
								path: p,
								name: info.name || p.split('/').pop(),
								size: info.size || 0,
								mime: info.mimeType || 'application/octet-stream',
							})
						} catch {
							this.addFile({
								path: p,
								name: p.split('/').pop(),
								size: 0,
								mime: 'application/octet-stream',
							})
						}
					}
				} catch (err) {
					if (!(err instanceof FilePickerClosed)) {
						console.error('File picker error:', err)
					}
				}
			}

			this.$refs.input?.focus()
		},
		async handleAddDirectory(args) {
			if (args) {
				this.addFile({
					path: args,
					name: args.split('/').pop() || args,
					size: 0,
					mime: 'httpd/unix-directory',
				})
			} else {
				try {
					const picker = getFilePickerBuilder('Select directory')
						.setType(1)
						.allowDirectories(true)
						.setMimeTypeFilter(['httpd/unix-directory'])
						.build()

					const path = await picker.pick()
					this.addFile({
						path,
						name: path.split('/').pop() || path,
						size: 0,
						mime: 'httpd/unix-directory',
					})
				} catch (err) {
					if (!(err instanceof FilePickerClosed)) {
						console.error('Directory picker error:', err)
					}
				}
			}

			this.$refs.input?.focus()
		},
		addFile(file) {
			if (this.attachedFiles.some(f => f.path === file.path)) return
			this.attachedFiles.push(file)
			if (isImageMime(file.mime)) {
				this.loadPreview(file.path)
			}
		},
		async loadPreview(path) {
			try {
				const { data } = await getFilePreview(path, 48, 48)
				this.previews[path] = 'data:' + data.mimeType + ';base64,' + data.content
			} catch {
				// Preview not available, no thumbnail shown
			}
		},
		removeFile(path) {
			this.attachedFiles = this.attachedFiles.filter(f => f.path !== path)
			delete this.previews[path]
		},
		humanSize(bytes) {
			if (!bytes) return ''
			const units = ['B', 'KB', 'MB', 'GB']
			let i = 0
			let size = bytes
			while (size >= 1024 && i < units.length - 1) {
				size /= 1024
				i++
			}
			return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i]
		},
		onSend() {
			if (!this.prompt.trim() && this.attachedFiles.length === 0) return
			this.$emit('send', {
				prompt: this.prompt,
				files: [...this.attachedFiles],
			})
			this.prompt = ''
			this.attachedFiles = []
			this.previews = {}
		},
		onDragOver() {
			// Keep dragging state while over the element
		},
		onDragEnter() {
			this.dragCounter++
			this.dragging = true
		},
		onDragLeave() {
			this.dragCounter--
			if (this.dragCounter <= 0) {
				this.dragging = false
				this.dragCounter = 0
			}
		},
		async onDrop(e) {
			this.dragging = false
			this.dragCounter = 0

			const files = e.dataTransfer?.files
			if (files && files.length > 0) {
				for (const file of files) {
					if (isImageMime(file.type)) {
						const path = await this.uploadFile(file)
						if (path) {
							this.addFile({
								path,
								name: file.name,
								size: file.size,
								mime: file.type,
							})
						}
					}
				}
				return
			}

			// Try NC internal path from text/plain
			const textData = e.dataTransfer?.getData('text/plain')
			if (textData && textData.startsWith('/')) {
				try {
					const { data: info } = await getFileInfo(textData)
					this.addFile({
						path: textData,
						name: info.name || textData.split('/').pop(),
						size: info.size || 0,
						mime: info.mimeType || 'application/octet-stream',
					})
				} catch {
					// Not a valid file path
				}
			}
		},
		async onPaste(e) {
			const items = e.clipboardData?.files
			if (!items || items.length === 0) return

			const imageFiles = Array.from(items).filter(f => isImageMime(f.type))
			if (imageFiles.length === 0) return

			e.preventDefault()
			for (const file of imageFiles) {
				const ext = file.type.split('/')[1] || 'png'
				const name = 'paste-' + Date.now() + '.' + ext
				const renamedFile = new File([file], name, { type: file.type })
				const path = await this.uploadFile(renamedFile)
				if (path) {
					this.addFile({
						path,
						name,
						size: file.size,
						mime: file.type,
					})
				}
			}
		},
		/**
		 * Upload a local File into the user's upload folder and return its
		 * Nextcloud path, or null when the upload failed.
		 *
		 * Anything larger than one chunk goes through Nextcloud's chunked upload
		 * endpoint: a plain PUT of the whole body is capped by the server's
		 * post_max_size/upload_max_filesize, so a big attachment would otherwise
		 * just fail. The target name is resolved against what is already there
		 * first, so two attachments with the same name do not overwrite silently.
		 */
		async uploadFile(file) {
			const user = getCurrentUser()
			if (!user) return null

			const davBase = generateRemoteUrl('dav/files/' + user.uid)

			try {
				await axios({ method: 'MKCOL', url: davBase + encodeDavPath(UPLOAD_FOLDER) })
			} catch {
				// Folder already exists (405) or other non-fatal error
			}

			this.uploads.push({ name: file.name, percent: 0 })
			const entry = this.uploads[this.uploads.length - 1]
			const onProgress = (percent) => {
				entry.percent = percent
			}

			try {
				const filePath = await this.freeTargetPath(davBase, file.name)
				if (file.size > CHUNK_SIZE) {
					await this.putChunked(davBase, filePath, file, user.uid, onProgress)
				} else {
					await this.putWhole(davBase, filePath, file, onProgress)
				}
				return filePath
			} catch (err) {
				console.error('[AIquila] Failed to upload file:', err)
				showError(t('aiquila', 'Could not upload {name}', { name: file.name }))
				return null
			} finally {
				const i = this.uploads.indexOf(entry)
				if (i !== -1) this.uploads.splice(i, 1)
			}
		},
		/**
		 * The first free path for `name` in the upload folder.
		 *
		 * PUT to an occupied path replaces the file without a word, so pasted and
		 * dropped attachments that happen to share a name would quietly clobber
		 * each other. Suffix instead, the way the Files app does.
		 */
		async freeTargetPath(davBase, name) {
			const dot = name.lastIndexOf('.')
			const stem = dot > 0 ? name.slice(0, dot) : name
			const ext = dot > 0 ? name.slice(dot) : ''

			for (let i = 0; i < 100; i++) {
				const candidate = UPLOAD_FOLDER + '/' + (i === 0 ? name : `${stem} (${i})${ext}`)
				try {
					await axios({ method: 'HEAD', url: davBase + encodeDavPath(candidate) })
					// 2xx: the name is occupied, try the next suffix.
				} catch (err) {
					if (err.response !== undefined && err.response.status !== 404) {
						// Offline, 5xx, a proxy in the way: the probe says nothing about
						// whether the name is free, so every further suffix is a guess too.
						// Stop and take the unique fallback below — overwriting someone's
						// file is the failure mode worth avoiding here.
						break
					}
					// 404 — nothing there, this name is free.
					return candidate
				}
			}

			// 100 collisions on one name, or a probe we could not trust: fall back to
			// a name that cannot collide.
			return UPLOAD_FOLDER + '/' + `${stem}-${Date.now()}${ext}`
		},
		async putWhole(davBase, filePath, file, onProgress) {
			await axios.put(davBase + encodeDavPath(filePath), file, {
				headers: { 'Content-Type': file.type },
				onUploadProgress: (e) => {
					if (e.total) onProgress(Math.round((e.loaded / e.total) * 100))
				},
			})
		},
		/**
		 * Nextcloud's chunked upload: build the parts under a temporary upload
		 * directory, then MOVE its virtual `.file` onto the destination, which is
		 * what tells the server to assemble them.
		 */
		async putChunked(davBase, filePath, file, uid, onProgress) {
			const uploadId = 'aiquila-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10)
			const uploadUrl = generateRemoteUrl('dav/uploads/' + uid) + '/' + uploadId

			await axios({ method: 'MKCOL', url: uploadUrl })

			try {
				const chunks = Math.ceil(file.size / CHUNK_SIZE)
				for (let i = 0; i < chunks; i++) {
					const start = i * CHUNK_SIZE
					const end = Math.min(start + CHUNK_SIZE, file.size)
					await axios.put(uploadUrl + '/' + String(i).padStart(5, '0'), file.slice(start, end), {
						headers: { 'Content-Type': 'application/octet-stream' },
						onUploadProgress: (e) => {
							const done = start + (e.loaded || 0)
							onProgress(Math.min(99, Math.round((done / file.size) * 100)))
						},
					})
				}

				await axios({
					method: 'MOVE',
					url: uploadUrl + '/.file',
					headers: {
						Destination: davBase + encodeDavPath(filePath),
						'OC-Total-Length': String(file.size),
						Overwrite: 'F',
					},
				})
				onProgress(100)
			} catch (err) {
				// Leaving the parts behind would strand quota, so clear the staging
				// directory before reporting the failure.
				try {
					await axios({ method: 'DELETE', url: uploadUrl })
				} catch {
					// Best effort — the original error is the one that matters.
				}
				throw err
			}
		},
	},
}
</script>

<style scoped>
.chat-input {
	padding: 12px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
	position: relative;
}

.drop-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--border-radius);
	font-size: 16px;
	font-weight: 600;
	color: var(--color-primary-element);
	z-index: 10;
	pointer-events: none;
}

.upload-progress {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
}

.upload-row {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.upload-name {
	flex: 0 1 auto;
	max-width: 40%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.upload-row .progress-bar {
	flex: 1 1 auto;
}

.upload-percent {
	flex: 0 0 auto;
	font-variant-numeric: tabular-nums;
}

.file-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 8px;
}

.file-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 16px;
	font-size: 13px;
	max-width: 260px;
}

.file-chip--dir {
	background: #3d3100;
	border-color: #665200;
	color: #ffd54f;
}

.file-thumb {
	width: 32px;
	height: 32px;
	object-fit: cover;
	border-radius: 4px;
	flex-shrink: 0;
}

.file-chip .name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-chip .size {
	color: var(--color-text-lighter);
	font-size: 12px;
	white-space: nowrap;
}

.file-chip .remove {
	background: none;
	border: none;
	cursor: pointer;
	padding: 0 2px;
	font-size: 14px;
	line-height: 1;
	color: var(--color-text-lighter);
	border-radius: 50%;
}

.file-chip .remove:hover {
	color: var(--color-error);
	background: var(--color-background-hover);
}

.input-wrapper {
	position: relative;
}

.input-wrapper textarea {
	width: 100%;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: var(--font-face);
	font-size: 14px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.input-actions {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 4px;
	margin-top: 8px;
}

/* Slash menu */
.slash-menu {
	position: absolute;
	bottom: 100%;
	left: 0;
	right: 0;
	margin-bottom: 4px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	max-height: 280px;
	overflow-y: auto;
	z-index: 1000;
}

.slash-menu-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	cursor: pointer;
	font-size: 14px;
}

.slash-menu-item:hover,
.slash-menu-item.active {
	background: var(--color-background-hover);
}

.slash-menu-item .icon {
	flex-shrink: 0;
	font-size: 16px;
	width: 24px;
	text-align: center;
}

.slash-menu-item .label {
	font-weight: 600;
	white-space: nowrap;
}

.slash-menu-item .description {
	color: var(--color-text-lighter);
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
