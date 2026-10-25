import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { fileURLToPath } from 'url'
import pkg from './package.json' with { type: 'json' }

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/**
 * The "Ask Claude" file action, loaded on every Files page view, and the
 * Text editor's Assistant fallback, loaded wherever Text can open.
 *
 * Built separately from vite.config.js so it shares no chunks with the app
 * bundle: there the bundler would link it to vendor-nextcloud-vue, and every
 * Files page would load the whole component library and its stylesheet just to
 * register one menu entry. Here the entry pulls in only @nextcloud/files and
 * l10n; the modal and its dependencies load on first click.
 *
 * Runs after the main build and writes next to it without emptying js/dist.
 * The entries are not in the main manifest, so ViteAssets falls back to
 * js/dist/<entry>.js, and they have no static stylesheet to link.
 */
export default defineConfig({
	plugins: [vue()],
	define: {
		appName: JSON.stringify(pkg.name),
		appVersion: JSON.stringify(pkg.version),
	},
	base: '',
	build: {
		outDir: 'js/dist',
		emptyOutDir: false,
		chunkSizeWarningLimit: 1000,
		rollupOptions: {
			input: {
				'aiquila-files': path.resolve(__dirname, 'src/fileactions.js'),
				'aiquila-text': path.resolve(__dirname, 'src/textassistant.js'),
			},
			output: {
				format: 'es',
				entryFileNames: '[name].js',
				chunkFileNames: 'files/[name]-[hash].js',
				assetFileNames: 'files/assets/[name]-[hash][extname]',
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
