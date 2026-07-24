import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { TOKEN_KEY } from '../lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem(TOKEN_KEY) || null)
  const user = ref(null)
  const loading = ref(false)
  // True until the initial /auth/me check finishes, so route guards don't
  // bounce a logged-in user to /login on a hard refresh.
  const ready = ref(false)

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const roleCodes = computed(() => (user.value?.roles ?? []).map((r) => r.code))

  function hasRole(code) {
    return roleCodes.value.includes(code)
  }

  function setToken(value) {
    token.value = value
    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  async function login(email, password) {
    loading.value = true
    try {
      const { data } = await api.post('/auth/login', { email, password })
      setToken(data.token)
      user.value = data.user
      return data.user
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    if (!token.value) {
      user.value = null
      return null
    }
    const { data } = await api.get('/auth/me')
    // UserResource is returned wrapped in `data` by Laravel resources.
    user.value = data.data ?? data
    return user.value
  }

  /** Clear local session state without calling the API. */
  function clear() {
    setToken(null)
    user.value = null
  }

  async function logout() {
    try {
      if (token.value) await api.post('/auth/logout')
    } catch {
      // Token may already be invalid server-side; clearing locally is enough.
    } finally {
      clear()
    }
  }

  /** Restore the session on app boot. */
  async function init() {
    if (token.value) {
      try {
        await fetchMe()
      } catch {
        clear()
      }
    }
    ready.value = true
  }

  return {
    token, user, loading, ready,
    isAuthenticated, roleCodes,
    hasRole, login, logout, fetchMe, clear, init,
  }
})
