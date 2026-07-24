import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './lib/api'
import { useAuthStore } from './stores/auth'

/**
 * Application entry point: builds the Vue app, installs Pinia (state) and
 * Vue Router (navigation), then mounts it into #app in index.html.
 */

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

/*
 * Connect the API client to the auth store.
 *
 * api.js can't import the store directly — the store imports api.js, so that
 * would be a circular dependency. Instead we hand it a callback here, once
 * both exist. Now any 401 from the server clears the session and returns the
 * user to the login page, from wherever in the app it happened.
 *
 * useAuthStore(pinia) passes the instance explicitly because this runs outside
 * a component, where Pinia's active instance isn't set up yet.
 */
const auth = useAuthStore(pinia)
setUnauthorizedHandler(() => {
  auth.clear()
  router.push({ name: 'login' })
})

app.mount('#app')
