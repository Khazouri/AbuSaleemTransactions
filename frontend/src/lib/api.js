import axios from 'axios'

/**
 * Shared axios instance for the Abu Saleem API.
 * Base URL comes from VITE_API_BASE_URL (see frontend/.env).
 * A bearer-token interceptor is added in Stage 4 (authentication).
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
})

export default api
