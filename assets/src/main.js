import { createApp } from 'vue'
import App from './App.vue'
import './style.css'
import { library } from '@fortawesome/fontawesome-svg-core'
import { faGlobe, faCrown, faUser } from '@fortawesome/free-solid-svg-icons'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'

library.add(faGlobe, faCrown, faUser)

document.addEventListener('DOMContentLoaded', () => {
    const appElement = document.getElementById('service-queue-app')
    if (appElement) {
        const app = createApp(App)
        app.component('font-awesome-icon', FontAwesomeIcon)
        app.mount('#service-queue-app')
    }
})
