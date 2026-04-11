// SPDX-License-Identifier: AGPL-3.0-or-later

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
