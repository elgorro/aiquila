import { marked } from 'marked'
import hljs from 'highlight.js/lib/core'

import javascript from 'highlight.js/lib/languages/javascript'
import python from 'highlight.js/lib/languages/python'
import php from 'highlight.js/lib/languages/php'
import bash from 'highlight.js/lib/languages/bash'
import json from 'highlight.js/lib/languages/json'
import xml from 'highlight.js/lib/languages/xml'
import css from 'highlight.js/lib/languages/css'
import sql from 'highlight.js/lib/languages/sql'
import yaml from 'highlight.js/lib/languages/yaml'
import markdownLang from 'highlight.js/lib/languages/markdown'
import typescript from 'highlight.js/lib/languages/typescript'

hljs.registerLanguage('javascript', javascript)
hljs.registerLanguage('js', javascript)
hljs.registerLanguage('python', python)
hljs.registerLanguage('py', python)
hljs.registerLanguage('php', php)
hljs.registerLanguage('bash', bash)
hljs.registerLanguage('sh', bash)
hljs.registerLanguage('shell', bash)
hljs.registerLanguage('json', json)
hljs.registerLanguage('xml', xml)
hljs.registerLanguage('html', xml)
hljs.registerLanguage('css', css)
hljs.registerLanguage('sql', sql)
hljs.registerLanguage('yaml', yaml)
hljs.registerLanguage('yml', yaml)
hljs.registerLanguage('markdown', markdownLang)
hljs.registerLanguage('md', markdownLang)
hljs.registerLanguage('typescript', typescript)
hljs.registerLanguage('ts', typescript)

function escapeAttr(str) {
	return str
		.replace(/&/g, '&amp;')
		.replace(/"/g, '&quot;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
}

const renderer = new marked.Renderer()

renderer.code = function({ text, lang }) {
	const language = lang && hljs.getLanguage(lang) ? lang : null
	const highlighted = language
		? hljs.highlight(text, { language }).value
		: hljs.highlightAuto(text).value
	const langLabel = language || 'code'
	return `<div class="code-block"><div class="code-header"><span class="code-lang">${langLabel}</span><button class="copy-code-btn" data-code="${escapeAttr(text)}">Copy</button></div><pre><code class="hljs">${highlighted}</code></pre></div>`
}

marked.setOptions({
	renderer,
	breaks: true,
	gfm: true,
})

export function renderMarkdown(text) {
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
