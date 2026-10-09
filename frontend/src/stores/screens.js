import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../lib/api'

/**
 * Navigation store — the sidebar's list of screens, fetched from the API.
 *
 * The menu is server-driven: Laravel decides which screens this user may see
 * (see ScreenController), so the SPA never hard-codes the list.
 */
export const useScreensStore = defineStore('screens', () => {
  /** Raw screen rows as returned by the API. */
  const screens = ref([])
  const loading = ref(false)
  const error = ref(null)
  /** Guards against re-fetching the menu on every navigation. */
  const loaded = ref(false)

  /**
   * Screens that can actually be used as menu links.
   *
   * Some rows describe screens reached only in context — request_details
   * is "/requests/:id", which needs a specific request. A link to a
   * path containing ":" would navigate to a literal ":id" URL, so those are
   * filtered out of the menu while remaining in the table for permissions.
   */
  const navItems = computed(() =>
    screens.value.filter((s) => s.route && !s.route.includes(':')),
  )

  /**
   * navItems clustered by `group`, in the order they already arrive in
   * (sort_order) — Stage 28's collapsible sidebar sections read from this.
   * A screen with no `group` never appears here; it's still in `navItems`
   * as a flat, top-level entry, same as every screen before Stage 28.
   */
  const navGroups = computed(() => {
    const groups = new Map()
    for (const screen of navItems.value) {
      if (!screen.group) continue
      if (!groups.has(screen.group)) groups.set(screen.group, [])
      groups.get(screen.group).push(screen)
    }
    return Array.from(groups, ([key, items]) => ({ key, items }))
  })

  /**
   * Fetch the menu once per session.
   * @param {boolean} force re-fetch even if already loaded
   */
  async function fetchScreens(force = false) {
    if (loaded.value && !force) return screens.value

    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/screens')
      // Laravel resource collections arrive wrapped in `data`.
      screens.value = data.data ?? data
      loaded.value = true
      return screens.value
    } catch (e) {
      error.value = e
      // Leave `loaded` false so a later attempt retries rather than showing an
      // empty menu forever.
      throw e
    } finally {
      loading.value = false
    }
  }

  /** Clear on logout, so the next user doesn't inherit the previous menu. */
  function reset() {
    screens.value = []
    loaded.value = false
    error.value = null
  }

  return { screens, navItems, navGroups, loading, error, loaded, fetchScreens, reset }
})
