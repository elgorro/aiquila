// SPDX-License-Identifier: AGPL-3.0-or-later

import { marked } from 'marked'
import { ref } from 'vue'

// highlight.js is loaded on demand: the core on the first fenced code block,
// each grammar on the first fence that names it. Until a grammar has arrived
// the block renders as escaped plain text; `loadTick` then bumps, and any
// computed property that called renderMarkdown() re-renders highlighted.
//
// The loaders are written out because Vite only rewrites template-literal
// dynamic imports for relative paths, not bare module specifiers.
const LOADERS = {
	bash: () => import('highlight.js/lib/languages/bash'),
	c: () => import('highlight.js/lib/languages/c'),
	cpp: () => import('highlight.js/lib/languages/cpp'),
	css: () => import('highlight.js/lib/languages/css'),
	diff: () => import('highlight.js/lib/languages/diff'),
	dockerfile: () => import('highlight.js/lib/languages/dockerfile'),
	go: () => import('highlight.js/lib/languages/go'),
	ini: () => import('highlight.js/lib/languages/ini'),
	java: () => import('highlight.js/lib/languages/java'),
	javascript: () => import('highlight.js/lib/languages/javascript'),
	json: () => import('highlight.js/lib/languages/json'),
	markdown: () => import('highlight.js/lib/languages/markdown'),
	php: () => import('highlight.js/lib/languages/php'),
	python: () => import('highlight.js/lib/languages/python'),
	rust: () => import('highlight.js/lib/languages/rust'),
	sql: () => import('highlight.js/lib/languages/sql'),
	typescript: () => import('highlight.js/lib/languages/typescript'),
	xml: () => import('highlight.js/lib/languages/xml'),
	yaml: () => import('highlight.js/lib/languages/yaml'),
}

const ALIASES = {
	js: 'javascript',
	jsx: 'javascript',
	mjs: 'javascript',
	ts: 'typescript',
	tsx: 'typescript',
	py: 'python',
	sh: 'bash',
	shell: 'bash',
	zsh: 'bash',
	html: 'xml',
	svg: 'xml',
	yml: 'yaml',
	md: 'markdown',
	'c++': 'cpp',
	h: 'c',
	hpp: 'cpp',
	golang: 'go',
	rs: 'rust',
	toml: 'ini',
	docker: 'dockerfile',
	patch: 'diff',
}

// Fences without an info string are auto-detected among this set only, so the
// result does not depend on which grammars other blocks happened to load.
const AUTO_DETECT = [
	'javascript', 'typescript', 'python', 'php', 'bash', 'json',
	'xml', 'css', 'sql', 'yaml', 'markdown',
]

const loadTick = ref(0)
let hljs = null
let corePromise = null
const loading = new Map()

function loadCore() {
	if (!corePromise) {
		corePromise = import('highlight.js/lib/core').then(m => {
			hljs = m.default
		})
	}
	return corePromise
}

function loadLanguage(name) {
	if (!loading.has(name)) {
		loading.set(name, Promise.all([loadCore(), LOADERS[name]()]).then(([, m]) => {
			hljs.registerLanguage(name, m.default)
		}))
	}
	return loading.get(name)
}

function request(names) {
	const missing = names.filter(n => !hljs || !hljs.getLanguage(n))
	if (missing.length === 0) return true
	Promise.all(missing.map(loadLanguage)).then(
		() => { loadTick.value++ },
		() => { /* chunk failed to load: the block stays plain text */ },
	)
	return false
}

export function resolveLanguage(lang) {
	if (!lang) return null
	const name = lang.trim().split(/\s+/)[0].toLowerCase()
	const canonical = ALIASES[name] || name
	return Object.hasOwn(LOADERS, canonical) ? canonical : null
}

function escapeHtml(str) {
	return str
		.replace(/&/g, '&amp;')
		.replace(/"/g, '&quot;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
}

function highlight(text, language) {
	if (language) {
		return request([language]) ? hljs.highlight(text, { language }).value : null
	}
	if (!request(AUTO_DETECT)) return null
	return hljs.highlightAuto(text, AUTO_DETECT).value
}

const renderer = new marked.Renderer()

renderer.code = function({ text, lang }) {
	const language = resolveLanguage(lang)
	const highlighted = highlight(text, language) ?? escapeHtml(text)
	const langLabel = language || 'code'
	return `<div class="code-block"><div class="code-header"><span class="code-lang">${langLabel}</span><button class="copy-code-btn" data-code="${escapeHtml(text)}">Copy</button></div><pre><code class="hljs">${highlighted}</code></pre></div>`
}

marked.setOptions({
	renderer,
	breaks: true,
	gfm: true,
})

export function renderMarkdown(text) {
	// Read so that a computed property calling this re-runs once a grammar loads.
	void loadTick.value
	return marked.parse(text)
}

export function attachCopyHandlers(el) {
	if (!el) return
	el.querySelectorAll('.copy-code-btn').forEach(btn => {
		if (btn._copyHandlerAttached) return
		btn._copyHandlerAttached = true
		btn.addEventListener('click', () => {
			const code = btn.getAttribute('data-code')
				.replace(/&amp;/g, '&')
				.replace(/&quot;/g, '"')
				.replace(/&lt;/g, '<')
				.replace(/&gt;/g, '>')
			navigator.clipboard.writeText(code).then(() => {
				const original = btn.textContent
				btn.textContent = 'Copied!'
				setTimeout(() => {
					btn.textContent = original
				}, 2000)
			})
		})
	})
}
