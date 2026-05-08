// SPDX-License-Identifier: AGPL-3.0-or-later

import { generateUrl } from '@nextcloud/router'

/**
 * Format a list of page numbers as a human-friendly range string.
 * [3, 4, 5, 7, 9, 10] → "3–5, 7, 9–10"
 *
 * @param {Array<number>} pages
 * @return {string}
 */
export function formatPageRanges(pages) {
	const sorted = [...new Set(pages.filter(p => Number.isFinite(p)))].sort((a, b) => a - b)
	if (sorted.length === 0) return '?'
	const out = []
	let start = sorted[0]
	let prev = sorted[0]
	for (let i = 1; i <= sorted.length; i++) {
		const cur = sorted[i]
		if (cur === prev + 1) {
			prev = cur
			continue
		}
		out.push(start === prev ? `${start}` : `${start}–${prev}`)
		start = cur
		prev = cur
	}
	return out.join(', ')
}

/**
 * Group citations for chip rendering. Page-location citations from the same
 * document collapse to one group; char-location citations stay one-per-chip
 * because their spans don't merge meaningfully.
 *
 * @param {Array<object>} citations  raw citation entries from the backend
 * @param {object} documentsByIndex  document_index → { path, title, mimeType, fileId? }
 * @return {Array<object>}  groups ready for v-for
 */
export function groupCitations(citations, documentsByIndex) {
	const groups = []
	const pageBuckets = new Map()
	for (const c of citations) {
		if (c.type === 'page_location') {
			const key = c.document_index ?? `__t_${c.document_title || ''}`
			if (!pageBuckets.has(key)) {
				const bucket = {
					kind: 'page_location',
					document_index: c.document_index,
					document_title: c.document_title,
					document: documentsByIndex?.[c.document_index] ?? null,
					pages: [],
					spans: [],
				}
				pageBuckets.set(key, bucket)
				groups.push(bucket)
			}
			const bucket = pageBuckets.get(key)
			const start = c.start_page_number
			const end = c.end_page_number ?? start
			if (Number.isFinite(start)) {
				for (let p = start; p <= end; p++) bucket.pages.push(p)
			}
			if (c.cited_text) bucket.spans.push(c.cited_text)
		} else {
			groups.push({
				kind: c.type || 'unknown',
				document_index: c.document_index,
				document_title: c.document_title,
				document: documentsByIndex?.[c.document_index] ?? null,
				start_char_index: c.start_char_index,
				end_char_index: c.end_char_index,
				spans: c.cited_text ? [c.cited_text] : [],
			})
		}
	}
	return groups
}

/**
 * Label a citation group for chip rendering.
 *
 * @param {object} group
 * @param {Function} t  i18n translator
 * @return {string}
 */
export function groupLabel(group, t) {
	const title = group.document_title || group.document?.title || t('aiquila', 'Document')
	if (group.kind === 'page_location') {
		return `${title} p.${formatPageRanges(group.pages)}`
	}
	return title
}

/**
 * Tooltip body for a citation group — newline-joined cited spans.
 *
 * @param {object} group
 * @return {string}
 */
export function groupTooltip(group) {
	return (group.spans || []).join('\n\n')
}

/**
 * Try to open the source for a citation group via Nextcloud's Viewer overlay.
 * Returns true on success. If the Viewer is unavailable or the document is
 * unknown, returns false so the caller can fall back to a Files-app deep link.
 *
 * @param {object} group
 * @return {boolean}
 */
function tryOpenInViewer(group) {
	const doc = group.document
	if (!doc?.path) return false
	const viewer = typeof OCA !== 'undefined' ? OCA.Viewer : null
	if (!viewer || typeof viewer.open !== 'function') return false
	try {
		viewer.open({ path: doc.path })
		return true
	} catch {
		return false
	}
}

/**
 * Build a Files-app deep link for the citation source. PDFs include a
 * `#page=N` fragment; PDF.js (and Nextcloud's PDF preview) honour it when
 * the renderer mounts.
 *
 * @param {object} group
 * @return {string|null}
 */
export function buildFilesAppUrl(group) {
	const doc = group.document
	if (!doc?.path) return null
	const slash = doc.path.lastIndexOf('/')
	const dir = slash <= 0 ? '/' : doc.path.slice(0, slash)
	const name = slash < 0 ? doc.path : doc.path.slice(slash + 1)
	const params = new URLSearchParams({ dir, openfile: name })
	let url = generateUrl(`/apps/files/?${params.toString()}`)
	if (group.kind === 'page_location' && group.pages?.length) {
		url += `#page=${Math.min(...group.pages)}`
	}
	return url
}

/**
 * Open the citation source — Viewer first, Files-app new tab as fallback.
 *
 * @param {object} group
 * @return {boolean}  true if some action was taken
 */
export function openCitationSource(group) {
	if (!group.document?.path) return false
	if (tryOpenInViewer(group)) return true
	const url = buildFilesAppUrl(group)
	if (url) {
		window.open(url, '_blank', 'noopener')
		return true
	}
	return false
}
