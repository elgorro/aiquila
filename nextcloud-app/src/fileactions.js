// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * "Ask AIquila" and "Process with AIquila…" actions in the Files app. Loaded
 * on the Files page by LoadFilesScriptsListener; the modals and Vue are
 * imported on first use so the Files page only pays for the registrations.
 */

import { registerFileAction, FileType, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

const ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>
</svg>`

registerFileAction({
	id: 'aiquila-ask',
	displayName: () => t('aiquila', 'Ask AIquila'),
	iconSvgInline: () => ICON,
	order: 10,

	// One readable file at a time.
	enabled: ({ nodes }) => nodes.length === 1
		&& nodes[0].type === FileType.File
		&& (nodes[0].permissions & Permission.READ) !== 0,

	async exec({ nodes }) {
		const [{ createApp }, { default: AskAIquilaModal }] = await Promise.all([
			import('vue'),
			import('./components/AskAIquilaModal.vue'),
		])

		const container = document.createElement('div')
		document.body.appendChild(container)
		const app = createApp(AskAIquilaModal, {
			file: nodes[0],
			onClose: () => {
				app.unmount()
				container.remove()
			},
		})
		app.mount(container)

		// null: the action neither succeeded nor failed yet, so Files shows no toast.
		return null
	},
})

const PROCESS_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path d="M7.5 5.6 10 7 8.6 4.5 10 2 7.5 3.4 5 2l1.4 2.5L5 7zm12 9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14zM22 2l-2.5 1.4L17 2l1.4 2.5L17 7l2.5-1.4L22 7l-1.4-2.5zm-7.63 5.29a.996.996 0 0 0-1.41 0L1.29 18.96a.996.996 0 0 0 0 1.41l2.34 2.34c.39.39 1.02.39 1.41 0L16.7 11.05a.996.996 0 0 0 0-1.41zm-1.03 5.49-2.12-2.12 2.44-2.44 2.12 2.12z"/>
</svg>`

/**
 * Open the "Process with AIquila…" dialog for a selection. Lazy, like the
 * Ask dialog: Vue and the modal load on first use.
 *
 * @param {import('@nextcloud/files').INode[]} nodes the selected files and folders
 */
async function openProcessDialog(nodes) {
	const [{ createApp }, { default: ProcessWithAIquilaModal }] = await Promise.all([
		import('vue'),
		import('./components/ProcessWithAIquilaModal.vue'),
	])

	const container = document.createElement('div')
	document.body.appendChild(container)
	const app = createApp(ProcessWithAIquilaModal, {
		nodes,
		onClose: () => {
			app.unmount()
			container.remove()
		},
	})
	app.mount(container)
}

registerFileAction({
	id: 'aiquila-process',
	displayName: () => t('aiquila', 'Process with AIquila…'),
	iconSvgInline: () => PROCESS_ICON,
	order: 11,

	// Any selection of readable files and folders, outside the trash, where
	// nodes are no longer the user's to process.
	enabled: ({ nodes, view }) => nodes.length > 0
		&& view?.id !== 'trashbin'
		&& nodes.every(node => node.fileid !== undefined && (node.permissions & Permission.READ) !== 0),

	async exec({ nodes }) {
		await openProcessDialog(nodes)
		// null: the dialog reports the outcome itself.
		return null
	},

	// One dialog for the whole selection, not one per node.
	async execBatch({ nodes }) {
		await openProcessDialog(nodes)
		return nodes.map(() => null)
	},
})
