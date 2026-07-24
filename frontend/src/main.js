import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './lib/api'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// When the API rejects our token, drop the session and bounce to login.
const auth = useAuthStore(pinia)
setUnauthorizedHandler(() => {
  auth.clear()
  router.push({ name: 'login' })
})

app.mount('#app')
