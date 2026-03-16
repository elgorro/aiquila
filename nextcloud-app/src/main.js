import { createApp } from 'vue'
import App from './App.vue'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(App)
	app.mount('#content')
})
