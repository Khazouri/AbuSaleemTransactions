import axios from 'axios'

export const TOKEN_KEY = 'abs_token'

/**
 * Shared axios instance for the Abu Saleem API.
 * Base URL comes from VITE_API_BASE_URL (see frontend/.env).
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
})

// Attach the bearer token to every request, if we have one.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * Called when the API rejects our token, so the auth store and router can
 * react without this module importing them (avoids a circular import).
 */
let onUnauthorized = null
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    // 401 = token missing/expired/revoked. Don't trigger on the login request
    // itself, where a 401/422 just means bad credentials.
    const isLoginCall = error?.config?.url?.includes('/auth/login')
    if (status === 401 && !isLoginCall && onUnauthorized) {
      onUnauthorized()
    }
    return Promise.reject(error)
  },
)

export default api
