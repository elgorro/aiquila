/**
 * AIquila File Actions - Simple Implementation
 * Registers "Ask Claude" action in Files app without Vue dependencies
 */

import { registerFileAction, FileAction, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

console.log('[AIquila] Registering file action (simple mode)')

// Register the "Ask Claude" file action
registerFileAction(new FileAction({
	id: 'aiquila-ask-claude',

	displayName: () => t('aiquila', 'Ask Claude'),

	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>
	</svg>`,

	// Only show for readable files
	enabled: (nodes) => {
		if (nodes.length !== 1) return false
		const node = nodes[0]
		if (node.type !== 'file') return false
		if ((node.permissions & Permission.READ) === 0) return false
		return true
	},

	// Execute when clicked
	exec: async (node) => {
		try {
			console.log('[AIquila] File action triggered for:', node.basename)

			// Show a native prompt dialog
			const question = prompt(t('aiquila', 'Ask Claude about {filename}', { filename: node.basename }))

			if (!question) return null

			// Fetch file content
			const fileResponse = await axios.get(node.source)
			const fileContent = fileResponse.data

			// Call Claude API
			const response = await axios.post(
				generateUrl('/apps/aiquila/api/ask'),
				{
					prompt: question,
					context: fileContent
				}
			)

			// Show response
			if (response.data.error) {
				alert(t('aiquila', 'Error: {error}', { error: response.data.error }))
			} else {
				alert(t('aiquila', 'Claude says:\n\n{response}', { response: response.data.response }))
			}

			return null
		} catch (error) {
			console.error('[AIquila] Error:', error)
			alert(t('aiquila', 'Failed to communicate with Claude'))
			return null
		}
	},

	order: 10,
}))

console.log('[AIquila] File action registered successfully')
