import axios from 'axios'

/**
 * The single HTTP client for the whole SPA.
 *
 * Every component and store talks to Laravel through this instance, which is
 * what lets us attach the auth token and handle expired sessions in ONE place
 * instead of in every request.
 */

/** localStorage key holding the Sanctum bearer token. */
export const TOKEN_KEY = 'abs_token'

const api = axios.create({
  // Set in frontend/.env — points at Homestead's nginx (http://abusaleem.test/api).
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    // Tells Laravel to return JSON errors rather than an HTML error page,
    // which is what makes validation messages readable in the SPA.
    Accept: 'application/json',
  },
})

/*
 * REQUEST interceptor — runs before every outgoing call.
 * Attaches the bearer token if we have one, so individual API calls never have
 * to think about authentication.
 *
 * Read from localStorage each time (rather than caching at module load) so a
 * fresh login takes effect immediately without a page reload.
 */
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * Callback invoked when the API rejects our token.
 *
 * Registered from main.js rather than imported here, because this module is
 * imported BY the auth store — importing the store back would create a
 * circular dependency. Inverting it this way keeps the client dependency-free.
 */
let onUnauthorized = null
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler
}

/*
 * RESPONSE interceptor — runs on every reply.
 * Successful responses pass straight through; a 401 means our token is no
 * longer good (expired, revoked, or the user was deleted), so we tear the
 * session down and send them back to the login page.
 */
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status

    // The login request is the one place a 401/422 is NORMAL — it just means
    // wrong credentials. Without this guard, a failed login attempt would
    // trigger the session-expired handler and redirect mid-typing.
    const isLoginCall = error?.config?.url?.includes('/auth/login')

    if (status === 401 && !isLoginCall && onUnauthorized) {
      onUnauthorized()
    }

    // Re-reject so the calling code can still show its own error message.
    return Promise.reject(error)
  },
)

export default api
