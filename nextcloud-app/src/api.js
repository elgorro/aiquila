import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
