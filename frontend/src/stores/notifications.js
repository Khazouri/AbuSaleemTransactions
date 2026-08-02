import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../lib/api'

/**
 * Notifications store — Stage 23.
 *
 * Two consumers with different appetites: the topbar bell wants the unread
 * count everywhere and only the newest few payloads, while the notifications
 * screen wants the paginated list. Both live here so a notification dismissed
 * on one immediately updates the other — the badge and the list are the same
 * state, not two copies of it.
 *
 * Setup style, matching stores/auth.js: refs are state, computeds are getters,
 * returned functions are actions.
 */

/** How many the bell dropdown shows without going to the full screen. */
const PREVIEW_SIZE = 5

/**
 * Bell refresh interval. Long enough that an idle tab isn't hammering the API
 * all day, short enough that a notification isn't stale news by the time it
 * appears. Polling rather than websockets: there is no broadcast
 * infrastructure in this deployment, and a count query is cheap.
 */
const POLL_MS = 60_000

export const useNotificationsStore = defineStore('notifications', () => {
  // ---- state ----------------------------------------------------------------

  /** The most recent notifications, for the bell dropdown. */
  const preview = ref([])

  /** The current page of the full list, for the notifications screen. */
  const items = ref([])

  const unread = ref(0)
  const loading = ref(false)
  const page = ref({ current_page: 1, last_page: 1, total: 0 })

  /** Timer handle, so the poll can be started and stopped with the layout. */
  let pollTimer = null

  // ---- getters --------------------------------------------------------------

  const hasUnread = computed(() => unread.value > 0)

  /** Over 9 stops being a number anyone reads and becomes "a lot". */
  const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)))

  // ---- actions --------------------------------------------------------------

  /** Just the count — what the bell polls on every screen. */
  async function fetchUnreadCount() {
    try {
      const { data } = await api.get('/notifications/unread-count')
      unread.value = data.data?.unread ?? 0
    } catch {
      // A failed poll is not worth interrupting the user over; the next tick
      // will pick the count back up.
    }
  }

  /**
   * The newest few, for the dropdown. Opening the bell is what triggers it,
   * and it refreshes the count at the same time so what the user sees and
   * what the badge claims were read in the same breath.
   */
  async function fetchPreview() {
    const { data } = await api.get('/notifications', { params: { per_page: PREVIEW_SIZE } })
    preview.value = data.data ?? []
    await fetchUnreadCount()
  }

  /** One page of the full list, for the notifications screen. */
  async function fetchPage(requestedPage = 1, unreadOnly = false) {
    loading.value = true
    try {
      const params = { page: requestedPage }
      if (unreadOnly) params.unread = 1
      const { data } = await api.get('/notifications', { params })
      items.value = data.data ?? []
      page.value = data.meta ?? page.value
    } finally {
      loading.value = false
    }
  }

  /** Mark one read, updating both views and the badge without a refetch. */
  async function markRead(id) {
    const { data } = await api.post(`/notifications/${id}/read`)
    const updated = data.data
    applyToLists(id, updated)
    if (unread.value > 0) unread.value -= 1
  }

  async function markAllRead() {
    await api.post('/notifications/read-all')
    const readAt = new Date().toISOString()
    for (const list of [preview, items]) {
      list.value = list.value.map((n) => (n.read_at ? n : { ...n, read_at: readAt }))
    }
    unread.value = 0
  }

  /** Replace one row wherever it appears, keeping both lists in step. */
  function applyToLists(id, updated) {
    for (const list of [preview, items]) {
      list.value = list.value.map((n) => (n.id === id ? updated : n))
    }
  }

  /**
   * Start polling the badge. Called by the layout once the user is signed in,
   * and stopped on sign-out — polling from the store rather than a component
   * keeps a single timer no matter how many screens mount the bell.
   */
  function startPolling() {
    if (pollTimer) return
    fetchUnreadCount()
    pollTimer = setInterval(fetchUnreadCount, POLL_MS)
  }

  function stopPolling() {
    if (!pollTimer) return
    clearInterval(pollTimer)
    pollTimer = null
  }

  /** Drop everything on sign-out, or the next user sees the previous one's bell. */
  function reset() {
    stopPolling()
    preview.value = []
    items.value = []
    unread.value = 0
    page.value = { current_page: 1, last_page: 1, total: 0 }
  }

  return {
    preview, items, unread, loading, page,
    hasUnread, badge,
    fetchUnreadCount, fetchPreview, fetchPage, markRead, markAllRead,
    startPolling, stopPolling, reset,
  }
})
