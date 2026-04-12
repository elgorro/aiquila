import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { fileURLToPath } from 'url'
import pkg from './package.json' with { type: 'json' }

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
	plugins: [vue()],
	define: {
		appName: JSON.stringify(pkg.name),
		appVersion: JSON.stringify(pkg.version),
	},
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
