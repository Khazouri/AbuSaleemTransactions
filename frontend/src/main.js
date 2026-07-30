import { createApp } from 'vue'
import { createPinia } from 'pinia'

// Self-hosted Cairo (Arabic + Latin). Bundled by Vite rather than fetched from
// a CDN, so the app renders correctly with no external request at runtime.
// 400 = body text, 600/700 = headings and emphasis.
import '@fontsource/cairo/400.css'
import '@fontsource/cairo/600.css'
import '@fontsource/cairo/700.css'

import './style.css'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { setUnauthorizedHandler } from './lib/api'
import { useAuthStore } from './stores/auth'
import { useScreensStore } from './stores/screens'
import vCan from './directives/can'

/**
 * Application entry point: builds the Vue app, installs Pinia (state),
 * Vue Router (navigation) and vue-i18n (language + direction), then mounts it
 * into #app in index.html.
 */

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
// Importing i18n has already set <html lang/dir> for the saved locale, so the
// first paint is in the right language and direction.
app.use(i18n)
// Stage 9 — hides elements the signed-in user's role can't act on.
app.directive('can', vCan)

/*
 * Connect the API client to the stores.
 *
 * api.js can't import them directly — they import it, so that would be a
 * circular dependency. Instead we hand it a callback here, once everything
 * exists. Any 401 from the server now clears the session and returns the user
 * to login, from wherever in the app it happened.
 *
 * useStore(pinia) passes the instance explicitly because this runs outside a
 * component, where Pinia's active instance isn't set yet.
 */
const auth = useAuthStore(pinia)
const screens = useScreensStore(pinia)

setUnauthorizedHandler(() => {
  auth.clear()
  // Clear the cached menu as well, so the next user to sign in doesn't
  // momentarily see the previous user's screens.
  screens.reset()
  router.push({ name: 'login' })
})

app.mount('#app')
