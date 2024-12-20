import { createApp } from 'vue'
import App from './App.vue'
import './style.css'

document.addEventListener('DOMContentLoaded', () => {
    const appElement = document.getElementById('service-queue-app')
    if (appElement) {
        createApp(App).mount('#service-queue-app')
    }
})
