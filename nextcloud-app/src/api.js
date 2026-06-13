// SPDX-License-Identifier: AGPL-3.0-or-later

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

const base = '/apps/aiquila'

function url(path) {
	return generateUrl(base + path)
}

export function listConversations() {
	return axios.get(url('/api/conversations'))
}

export function createConversation() {
	return axios.post(url('/api/conversations'))
}

export function getConversation(id) {
	return axios.get(url(`/api/conversations/${id}`))
}

export function updateConversation(id, data) {
	return axios.put(url(`/api/conversations/${id}`), data)
}

export function deleteConversation(id) {
	return axios.delete(url(`/api/conversations/${id}`))
}

export function sendMessage(conversationId, prompt, files = []) {
	return axios.post(url(`/api/conversations/${conversationId}/messages`), {
		prompt,
		files,
	})
}

/**
 * Streaming variant of sendMessage. POSTs to the SSE endpoint and invokes
 * onEvent for each parsed event ({ type, ...payload }). Returns a promise
 * that resolves when the stream completes (after the `persisted` event).
 *
 * Caller is responsible for state — typical pattern:
 *   - on `user_message`: replace optimistic user msg with persisted one
 *   - on `text_delta`: append to a draft assistant bubble
 *   - on `tool_use` / `tool_result`: render an inline tool indicator
 *   - on `done`: capture usage / citations
 *   - on `persisted`: replace draft bubble with the persisted message
 *   - on `error`: surface to user; persisted will still arrive
 */
export async function sendMessageStream(conversationId, prompt, files = [], onEvent) {
	const res = await fetch(url(`/api/conversations/${conversationId}/messages/stream`), {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'text/event-stream',
			requesttoken: getRequestToken(),
		},
		credentials: 'include',
		body: JSON.stringify({ prompt, files }),
	})

	if (!res.ok || !res.body) {
		throw new Error(`stream failed: HTTP ${res.status}`)
	}

	const reader = res.body.getReader()
	const decoder = new TextDecoder('utf-8')
	let buffer = ''

	// eslint-disable-next-line no-constant-condition
	while (true) {
		const { done, value } = await reader.read()
		if (done) break
		buffer += decoder.decode(value, { stream: true })

		let blockEnd
		while ((blockEnd = buffer.indexOf('\n\n')) !== -1) {
			const block = buffer.slice(0, blockEnd)
			buffer = buffer.slice(blockEnd + 2)
			let data = ''
			for (const line of block.split('\n')) {
				if (line.startsWith('data: ')) data += line.slice(6)
			}
			if (data === '') continue
			try {
				onEvent(JSON.parse(data))
			} catch (e) {
				// malformed event — log and continue
				console.warn('aiquila: malformed SSE event', e, data)
			}
		}
	}
}

export function getSettings() {
	return axios.get(url('/api/settings'))
}

export function saveSettings(data) {
	return axios.post(url('/api/settings'), data)
}

export function getFileInfo(path) {
	return axios.get(url('/api/files/info'), { params: { path } })
}

export const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

export function isImageMime(mime) {
	return IMAGE_MIMES.some(m => mime?.startsWith(m))
}

export function getFilePreview(path, width = 256, height = 256) {
	return axios.get(url('/api/files/preview'), { params: { path, width, height } })
}

export function analyzeFile(filePath, prompt) {
	return axios.post(url('/api/analyze-file'), { filePath, prompt })
}

// Conversation extras
export function duplicateConversation(id) {
	return axios.post(url(`/api/conversations/${id}/duplicate`))
}

export function searchConversations(query, limit = 20, cursor = 0) {
	return axios.get(url('/api/conversations/search'), { params: { query, limit, cursor } })
}

// Project API
export function listProjects() {
	return axios.get(url('/api/projects'))
}

export function createProject(data) {
	return axios.post(url('/api/projects'), data)
}

export function getProject(id) {
	return axios.get(url(`/api/projects/${id}`))
}

export function updateProject(id, data) {
	return axios.put(url(`/api/projects/${id}`), data)
}

export function deleteProject(id) {
	return axios.delete(url(`/api/projects/${id}`))
}

export function addProjectPath(id, path, pathType) {
	return axios.post(url(`/api/projects/${id}/paths`), { path, pathType })
}

export function removeProjectPath(id, pathId) {
	return axios.delete(url(`/api/projects/${id}/paths/${pathId}`))
}

// File listing
export function listDirectory(path) {
	return axios.get(url('/api/files/list'), { params: { path } })
}

// Cowork API (persistent scheduled AI tasks)
export function listCoworkers() {
	return axios.get(url('/api/coworkers'))
}

export function getCoworker(id) {
	return axios.get(url(`/api/coworkers/${id}`))
}

export function createCoworker(data) {
	return axios.post(url('/api/coworkers'), data)
}

export function updateCoworker(id, data) {
	return axios.put(url(`/api/coworkers/${id}`), data)
}

export function deleteCoworker(id) {
	return axios.delete(url(`/api/coworkers/${id}`))
}

export function pauseCoworker(id) {
	return axios.post(url(`/api/coworkers/${id}/pause`))
}

export function resumeCoworker(id) {
	return axios.post(url(`/api/coworkers/${id}/resume`))
}

export function enableCoworker(id) {
	return axios.post(url(`/api/coworkers/${id}/enable`))
}

export function disableCoworker(id) {
	return axios.post(url(`/api/coworkers/${id}/disable`))
}

export function runCoworker(id) {
	return axios.post(url(`/api/coworkers/${id}/run`))
}

export function getCoworkerRuns(id, limit = 20) {
	return axios.get(url(`/api/coworkers/${id}/runs`), { params: { limit } })
}

export function listCoworkerTemplates() {
	return axios.get(url('/api/coworkers/templates'))
}

export function createCoworkerFromTemplate(templateId, overrides = {}) {
	return axios.post(url('/api/coworkers/templates'), { templateId, ...overrides })
}
