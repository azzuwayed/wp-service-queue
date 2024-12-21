import { createApp } from 'vue'
import App from './App.vue'
import './style.css'

document.addEventListener('DOMContentLoaded', () => {
    const appElement = document.getElementById('service-queue-app')
    if (appElement) {
        const app = createApp(App)
        // Add error handler
        app.config.errorHandler = (err) => {
            console.error('Service Queue Error:', err)
        }
        // Use unique prefix for components
        app.config.compilerOptions.isCustomElement = (tag) => tag.startsWith('sq-')
        app.mount('#service-queue-app')
    }
})
