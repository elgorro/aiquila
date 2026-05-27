// SPDX-License-Identifier: AGPL-3.0-or-later

import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
	{ path: '/', redirect: '/chat' },
	{
		path: '/chat/:conversationId?',
		name: 'chat',
		components: {
			sidebar: () => import('./components/ChatSidebar.vue'),
			default: () => import('./components/ChatView.vue'),
		},
	},
	{
		path: '/projects/:projectId?',
		name: 'projects',
		components: {
			sidebar: () => import('./components/ProjectSidebar.vue'),
			default: () => import('./components/ProjectEditor.vue'),
		},
	},
	{
		path: '/cowork',
		name: 'cowork',
		components: {
			sidebar: () => import('./components/CoworkSidebar.vue'),
			default: () => import('./components/CoworkView.vue'),
		},
	},
	{ path: '/:pathMatch(.*)*', redirect: '/chat' },
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
})
