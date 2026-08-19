// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * API surface shared by the admin and personal settings pages.
 *
 * Both pages render whatever the backend's provider schema describes, so the
 * calls here are deliberately generic: there is no per-provider endpoint and
 * no field names in this file.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/aiquila'

function url(path) {
	return generateUrl(base + path)
}

// ── Providers ───────────────────────────────────────────────────────────

/** User-scope provider list: schema, capabilities, models and current values. */
export function getUserProviders(refresh = false) {
	return axios.get(url('/api/providers'), { params: refresh ? { refresh: '1' } : {} })
}

/**
 * Save user-scope field values for one provider.
 *
 * @param {string} providerId - provider to configure
 * @param {object} values - `{fieldId: value}` pairs; '' clears the override
 * @param {boolean|null} makeDefault - true pins this as the user's default,
 *   false clears the override, null leaves it alone
 */
export function saveUserProvider(providerId, values, makeDefault = null) {
	return axios.post(url(`/api/providers/${providerId}`), {
		values,
		...(makeDefault === null ? {} : { makeDefault: makeDefault ? '1' : '' }),
	})
}

/** Instance-scope provider list: full schema and instance values. */
export function getAdminProviders(refresh = false) {
	return axios.get(url('/api/admin/providers'), { params: refresh ? { refresh: '1' } : {} })
}

export function saveAdminProvider(providerId, values, makeDefault = false) {
	return axios.post(url(`/api/admin/providers/${providerId}`), {
		values,
		...(makeDefault ? { makeDefault: '1' } : {}),
	})
}

/** Live round-trip to a provider; `apiKey` tests a key before saving it. */
export function testProvider(providerId, apiKey = '') {
	return axios.post(url(`/api/admin/providers/${providerId}/test`), { api_key: apiKey })
}

/**
 * Run a schema-declared provider action (a button rather than a stored value).
 *
 * @param {string} providerId - provider owning the action
 * @param {string} actionId - id of the action field
 * @return {Promise} resolving to `{success, message, value}`
 */
export function runProviderAction(providerId, actionId) {
	return axios.post(url(`/api/admin/providers/${providerId}/action/${actionId}`), {})
}

/**
 * Live health of one provider, behind the status light on its card.
 *
 * The user-scope route reports what this user actually gets (their own key, or
 * the inherited instance one); the admin route reports the instance config.
 *
 * @param {string} providerId - provider to check
 * @param {string} scope - 'admin' or 'user'
 * @return {Promise} resolving to `{providerId, state, reason, message, model}`
 */
export function getProviderStatus(providerId, scope) {
	const path = scope === 'admin'
		? `/api/admin/providers/${providerId}/status`
		: `/api/providers/${providerId}/status`
	return axios.get(url(path))
}

/**
 * Search users and groups for the per-provider access lists (admin only).
 *
 * @param {string} search - substring to match against user and group names
 * @return {Promise} resolving to `{users: [{id, label}], groups: [{id, label}]}`
 */
export function searchPrincipals(search = '') {
	return axios.get(url('/api/admin/principals'), { params: { search } })
}

// ── Non-provider settings ───────────────────────────────────────────────

export function getSettings() {
	return axios.get(url('/api/settings'))
}

export function saveSettings(data) {
	return axios.post(url('/api/settings'), data)
}

export function saveAdminSettings(data) {
	return axios.post(url('/api/admin/settings'), data)
}

export function getNativeMcpStatus() {
	return axios.get(url('/api/admin/native-mcp/status'))
}

// ── MCP servers ─────────────────────────────────────────────────────────

export function listMcpServers() {
	return axios.get(url('/api/admin/mcp-servers'))
}

export function createMcpServer(data) {
	return axios.post(url('/api/admin/mcp-servers'), data)
}

export function updateMcpServer(id, data) {
	return axios.put(url(`/api/admin/mcp-servers/${id}`), data)
}

export function deleteMcpServer(id) {
	return axios.delete(url(`/api/admin/mcp-servers/${id}`))
}

export function testMcpServer(id) {
	return axios.post(url(`/api/admin/mcp-servers/${id}/test`))
}

export function authorizeMcpServer(id) {
	return axios.post(url(`/api/admin/mcp-servers/${id}/oauth/authorize`))
}
