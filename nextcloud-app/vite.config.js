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
		// `vendor-nextcloud-vue` alone is ~800 KB and is the floor we can't shrink
		// without dropping @nextcloud/vue; raise the warning so other regressions
		// stay visible.
		chunkSizeWarningLimit: 1000,
		rollupOptions: {
			input: {
				'aiquila-main': path.resolve(__dirname, 'src/main.js'),
			},
			output: {
				format: 'es',
				entryFileNames: '[name].js',
				chunkFileNames: '[name]-[hash].js',
				manualChunks(id) {
					if (!id.includes('node_modules')) return
					if (id.includes('@nextcloud/vue')) return 'vendor-nextcloud-vue'
					if (id.includes('vue-material-design-icons') || id.includes('@mdi/')) {
						return 'vendor-icons'
					}
					if (id.includes('highlight.js') || id.includes('marked')) {
						return 'vendor-markdown'
					}
				},
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
