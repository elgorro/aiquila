// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import CoworkerOutputWidget from './components/dashboard/CoworkerOutputWidget.vue'

document.addEventListener('DOMContentLoaded', () => {
	if (!window.OCA || !window.OCA.Dashboard) {
		return
	}
	window.OCA.Dashboard.register('aiquila_coworker_output', (el) => {
		const app = createApp(CoworkerOutputWidget)
		app.mount(el)
	})
})
