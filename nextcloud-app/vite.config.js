import { defineConfig } from 'vite'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
	build: {
		outDir: 'js',
		emptyOutDir: false, // Don't delete existing files in js/
		rollupOptions: {
			input: {
				'aiquila-main': path.resolve(__dirname, 'src/main.js'),
			},
			output: {
				format: 'es',
				entryFileNames: '[name].js', // Use .js instead of .mjs
				chunkFileNames: '[name]-[hash].js',
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
