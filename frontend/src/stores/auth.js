import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { TOKEN_KEY } from '../lib/api'

/**
 * Authentication store — who is signed in, and the actions to change that.
 *
 * Written in Pinia's "setup" style: refs are state, computeds are getters, and
 * returned functions are actions. Any component can call useAuthStore() and
 * share the same instance.
 *
 * The token lives in localStorage so a page refresh doesn't sign the user out;
 * the user object is rebuilt from the API on boot (see init()).
 */
export const useAuthStore = defineStore('auth', () => {
  // ---- state ----------------------------------------------------------------

  /** Sanctum bearer token; seeded from localStorage so refreshes survive. */
  const token = ref(localStorage.getItem(TOKEN_KEY) || null)

  /** The signed-in user (id, name, email, department, roles) or null. */
  const user = ref(null)

  /** True while a login request is in flight — drives the button spinner. */
  const loading = ref(false)

  /**
   * False until the boot-time /auth/me check has finished.
   *
   * This matters: on a hard refresh we have a token but no user yet, so
   * isAuthenticated is briefly false. Without this flag the router guard would
   * see "not logged in" and bounce a perfectly valid session to /login.
   */
  const ready = ref(false)

  // ---- getters --------------------------------------------------------------

  /** Both halves required: a token we've actually verified against the API. */
  const isAuthenticated = computed(() => !!token.value && !!user.value)

  /** Just the role codes, e.g. ['R08'] — handy for quick checks. */
  const roleCodes = computed(() => (user.value?.roles ?? []).map((r) => r.code))

  /**
   * Resolved screen x action permission map from /auth/login or /auth/me,
   * e.g. { users: { can_view: true, can_add: false, ... }, ... }. Empty until
   * a user is loaded, which makes `can()` fail closed rather than open.
   */
  const permissions = computed(() => user.value?.permissions ?? {})

  // ---- actions --------------------------------------------------------------

  /** Does the current user hold this role? e.g. hasRole('R08'). */
  function hasRole(code) {
    return roleCodes.value.includes(code)
  }

  /**
   * Does the current user's role grant this screen/action? e.g.
   * can('users', 'edit'). Mirrors the API's screen.permission middleware
   * (CheckScreenPermission) and drives both the router guard and the v-can
   * directive, so a hidden button and a blocked route always agree with what
   * the server actually allows.
   */
  function can(screenCode, action) {
    return !!permissions.value[screenCode]?.[`can_${action}`]
  }

  /** Keep the ref and localStorage in step; pass null to clear both. */
  function setToken(value) {
    token.value = value
    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  /**
   * Exchange credentials for a token.
   * Throws on failure so the login view can show the server's message —
   * the `finally` still clears the loading flag either way.
   */
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

  /** Fetch the current user using the stored token. */
  async function fetchMe() {
    if (!token.value) {
      user.value = null
      return null
    }
    const { data } = await api.get('/auth/me')
    // Laravel API Resources wrap the payload in `data`; the `?? data` fallback
    // keeps this working if that wrapper is ever turned off.
    user.value = data.data ?? data
    return user.value
  }

  /**
   * Drop the local session without calling the API.
   * Used by the 401 interceptor, where the token is already dead server-side.
   */
  function clear() {
    setToken(null)
    user.value = null
  }

  /** Sign out: revoke the token server-side, then clear locally. */
  async function logout() {
    try {
      if (token.value) await api.post('/auth/logout')
    } catch {
      // The token may already be invalid, or the server unreachable. Either
      // way the user asked to leave, so clearing locally is the right outcome.
    } finally {
      clear()
    }
  }

  /**
   * Restore the session on app boot / page refresh.
   *
   * If the stored token turns out to be stale, fetchMe() throws and we clear
   * it. Either way `ready` ends up true, which is what unblocks the router.
   */
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
    isAuthenticated, roleCodes, permissions,
    hasRole, can, login, logout, fetchMe, clear, init,
  }
})
