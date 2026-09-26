// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * "Ask Claude" action in the Files app. Loaded on the Files page by
 * LoadFilesScriptsListener; the modal and Vue are imported on first use so
 * the Files page only pays for the action registration.
 */

import { registerFileAction, FileType, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

const ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
	<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>
</svg>`

registerFileAction({
	id: 'aiquila-ask-claude',
	displayName: () => t('aiquila', 'Ask Claude'),
	iconSvgInline: () => ICON,
	order: 10,

	// One readable file at a time.
	enabled: ({ nodes }) => nodes.length === 1
		&& nodes[0].type === FileType.File
		&& (nodes[0].permissions & Permission.READ) !== 0,

	async exec({ nodes }) {
		const [{ createApp }, { default: AskClaudeModal }] = await Promise.all([
			import('vue'),
			import('./components/AskClaudeModal.vue'),
		])

		const container = document.createElement('div')
		document.body.appendChild(container)
		const app = createApp(AskClaudeModal, {
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
