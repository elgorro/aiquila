<template>
	<div class="chat-input">
		<div v-if="attachedFiles.length > 0" class="file-chips">
			<span v-for="file in attachedFiles"
				:key="file.path"
				class="file-chip">
				<span class="name" :title="file.path">{{ file.name }}</span>
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
				@keydown="onKeydown" />
		</div>
		<div class="input-actions">
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
import { getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'

import { getFileInfo } from '../api.js'

const SLASH_COMMANDS = [
	{
		id: 'add-file',
		label: '/add-file',
		icon: '📎',
		description: 'Attach a file from Nextcloud',
	},
]

export default {
	name: 'ChatInput',
	components: {
		NcButton,
	},
	props: {
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['send'],
	data() {
		return {
			prompt: '',
			attachedFiles: [],
			menuVisible: false,
			menuActiveIndex: 0,
			filteredCommands: [],
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

			if (cmd.id === 'add-file') {
				this.handleAddFile(args)
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
		addFile(file) {
			if (this.attachedFiles.some(f => f.path === file.path)) return
			this.attachedFiles.push(file)
		},
		removeFile(path) {
			this.attachedFiles = this.attachedFiles.filter(f => f.path !== path)
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
		},
	},
}
</script>

<style scoped>
.chat-input {
	padding: 12px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
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
	max-height: 200px;
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
