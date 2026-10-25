// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * AI actions in the Text editor without the Assistant app.
 *
 * Text shows its "Nextcloud Assistant" menu whenever a matching task type is
 * available, but hands every action to window.OCA.Assistant, which only the
 * Assistant app defines. When that app is absent, this fills in the two calls
 * Text makes — openAssistantForm and openAssistantTask — with a small dialog
 * that runs the task through TaskProcessing and hands the result back to
 * Text's own buttons (Insert, Insert result).
 *
 * The server only loads this when Assistant is not enabled for the user, and
 * it steps aside if OCA.Assistant exists anyway. Vue and the dialog load on
 * first use.
 */

window.OCA = window.OCA || {}

if (!window.OCA.Assistant) {
	/**
	 * Mount the dialog and resolve once it closes, as the Assistant app does.
	 *
	 * @param {object} props TextAssistantModal props
	 * @return {Promise<void>}
	 */
	const openDialog = async (props) => {
		const [{ createApp }, { default: TextAssistantModal }] = await Promise.all([
			import('vue'),
			import('./components/TextAssistantModal.vue'),
		])

		return new Promise((resolve) => {
			const container = document.createElement('div')
			document.body.appendChild(container)
			const app = createApp(TextAssistantModal, {
				...props,
				onClose: () => {
					app.unmount()
					container.remove()
					resolve()
				},
			})
			app.mount(container)
		})
	}

	window.OCA.Assistant = {
		aiquilaFallback: true,

		openAssistantForm({ appId, customId = '', taskType = null, inputs = {}, actionButtons = [] } = {}) {
			return openDialog({ appId, customId, taskType, inputs, actionButtons })
		},

		openAssistantTask(task, { actionButtons = [] } = {}) {
			return openDialog({ appId: task.appId, task, actionButtons })
		},
	}
}
