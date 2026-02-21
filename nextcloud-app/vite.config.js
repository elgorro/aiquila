import { defineConfig } from 'vite'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
	build: {
		outDir: 'js',
		emptyOutDir: false,
		rollupOptions: {
			input: {
				'aiquila-main': path.resolve(__dirname, 'src/main.js'),
			},
			output: {
				format: 'iife',
				entryFileNames: '[name].js',
				chunkFileNames: '[name]-[hash].js',
				// Externalize globals provided by Nextcloud so they are
				// not bundled and don't conflict with the host page.
				globals: {
					jquery: 'jQuery',
				},
			},
			external: ['jquery'],
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
