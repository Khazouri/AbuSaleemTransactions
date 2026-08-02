<script setup>
/**
 * Notifications screen — Stage 23.
 *
 * Two halves: the user's own notification history, and the preference matrix
 * that decides which channels future ones arrive on. They share a screen
 * because the answer to "why am I getting these?" is right next to the ones
 * being complained about.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '../lib/api'
import { useNotificationsStore } from '../stores/notifications'

const { t, te, locale } = useI18n()
const router = useRouter()
const store = useNotificationsStore()

const unreadOnly = ref(false)
const loadError = ref(null)

/** The preference matrix: { event_type: { in_app, email, sms } }. */
const settings = ref({})
const channels = ref([])
const eventTypes = ref([])
const phone = ref('')
const savingSettings = ref(false)
const settingsSaved = ref(false)
const settingsError = ref(null)

const isEmpty = computed(() => !store.loading && store.items.length === 0)

/** Falls back to the raw key, so an event type added server-side still reads. */
function eventLabel(key) {
  const path = `notifications.events.${key}`
  return te(path) ? t(path) : key
}

function channelLabel(key) {
  const path = `notifications.channels.${key}`
  return te(path) ? t(path) : key
}

/** Title/body in the reader's locale, falling back to whichever half exists. */
function localised(notification, field) {
  const [preferred, fallback] = locale.value === 'ar'
    ? [`${field}_ar`, `${field}_en`]
    : [`${field}_en`, `${field}_ar`]
  return notification[preferred] || notification[fallback] || ''
}

function timestamp(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date(value))
}

async function load(page = 1) {
  loadError.value = null
  try {
    await store.fetchPage(page, unreadOnly.value)
  } catch (error) {
    loadError.value = error
  }
}

function toggleUnreadOnly() {
  unreadOnly.value = !unreadOnly.value
  load(1)
}

/** Opening one marks it read and jumps to whatever it is about. */
async function open(notification) {
  if (!notification.read_at) {
    try {
      await store.markRead(notification.id)
    } catch {
      // The row stays unread and the next poll reconciles the badge; not a
      // reason to block the navigation the user asked for.
    }
  }

  if (notification.transaction_id) {
    router.push({ name: 'transaction_details', params: { id: notification.transaction_id } })
  } else if (notification.meeting_id) {
    router.push({ name: 'meeting_details', params: { id: notification.meeting_id } })
  }
}

async function markAllRead() {
  await store.markAllRead()
  // The unread-only view empties out once everything is read, so refetch
  // rather than leave rows on screen that no longer match the filter.
  if (unreadOnly.value) load(1)
}

async function loadSettings() {
  settingsError.value = null
  try {
    const { data } = await api.get('/notifications/settings')
    settings.value = data.data?.settings ?? {}
    channels.value = data.data?.channels ?? []
    eventTypes.value = data.data?.event_types ?? []
    phone.value = data.data?.phone ?? ''
  } catch (error) {
    settingsError.value = error
  }
}

async function saveSettings() {
  savingSettings.value = true
  settingsSaved.value = false
  settingsError.value = null
  try {
    const payload = {
      // An empty field means "remove my number", which the API reads as null;
      // sending '' would store an unusable empty string instead.
      phone: phone.value.trim() === '' ? null : phone.value.trim(),
      settings: eventTypes.value.map((eventType) => ({
        event_type: eventType,
        ...settings.value[eventType],
      })),
    }
    const { data } = await api.put('/notifications/settings', payload)
    settings.value = data.data?.settings ?? settings.value
    phone.value = data.data?.phone ?? ''
    settingsSaved.value = true
  } catch (error) {
    settingsError.value = error
  } finally {
    savingSettings.value = false
  }
}

onMounted(async () => {
  await Promise.all([load(), loadSettings()])
})
</script>

<template>
  <section class="notifications">
    <div class="heading">
      <div>
        <h2>{{ t('notifications.title') }}</h2>
        <p class="subtitle">{{ t('notifications.subtitle') }}</p>
      </div>
      <div class="head-actions">
        <button class="ghost" type="button" @click="toggleUnreadOnly">
          {{ unreadOnly ? t('notifications.showAll') : t('notifications.showUnread') }}
        </button>
        <button class="ghost" type="button" :disabled="!store.hasUnread" @click="markAllRead">
          {{ t('notifications.markAllRead') }}
        </button>
      </div>
    </div>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(store.page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card list">
      <p v-if="store.loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="isEmpty" class="state">{{ t('notifications.empty') }}</p>
      <ul v-else class="rows">
        <li v-for="item in store.items" :key="item.id" :class="{ unread: !item.read_at }">
          <button class="row" type="button" @click="open(item)">
            <span class="row-main">
              <span class="row-title">{{ localised(item, 'title') }}</span>
              <span class="row-body">{{ localised(item, 'body') }}</span>
            </span>
            <span class="row-meta">
              <span class="event">{{ eventLabel(item.event_type) }}</span>
              <span class="when">{{ timestamp(item.created_at) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>

    <nav v-if="!store.loading && store.page.last_page > 1" class="pagination" :aria-label="t('notifications.title')">
      <button class="ghost" :disabled="store.page.current_page <= 1" @click="load(store.page.current_page - 1)">
        {{ t('transactions.previous') }}
      </button>
      <span>{{ t('transactions.page', { current: store.page.current_page, last: store.page.last_page }) }}</span>
      <button class="ghost" :disabled="store.page.current_page >= store.page.last_page" @click="load(store.page.current_page + 1)">
        {{ t('transactions.next') }}
      </button>
    </nav>

    <!-- Preferences: one row per event, one checkbox per channel. -->
    <form class="card prefs" @submit.prevent="saveSettings">
      <h3>{{ t('notifications.preferences') }}</h3>
      <p class="subtitle">{{ t('notifications.preferencesHint') }}</p>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('notifications.event') }}</th>
              <th v-for="channel in channels" :key="channel">{{ channelLabel(channel) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="eventType in eventTypes" :key="eventType">
              <td class="event-name">{{ eventLabel(eventType) }}</td>
              <td v-for="channel in channels" :key="channel" class="check">
                <input
                  v-if="settings[eventType]"
                  v-model="settings[eventType][channel]"
                  type="checkbox"
                  :aria-label="`${eventLabel(eventType)} — ${channelLabel(channel)}`"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <label class="phone">
        {{ t('notifications.phone') }}
        <input v-model="phone" type="tel" class="ltr" :placeholder="t('notifications.phoneHint')" />
        <small>{{ t('notifications.phoneNote') }}</small>
      </label>

      <div class="actions">
        <button class="primary" type="submit" :disabled="savingSettings">
          {{ savingSettings ? t('common.saving') : t('common.save') }}
        </button>
        <span v-if="settingsSaved" class="saved">{{ t('notifications.saved') }}</span>
        <span v-if="settingsError" class="failed">{{ t('nav.error') }}</span>
      </div>
    </form>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-nav); font-size: 1.2rem; }
h3 { margin: 0 0 .25rem; font-size: 1rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.head-actions { display: flex; gap: .5rem; }
.card { padding: 1.25rem; margin-bottom: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: #fff; background: var(--color-nav); }
.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); color: #b91c1c; background: #fef2f2; }
.alert .ghost { margin-inline-start: .5rem; }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }

/* -- List ----------------------------------------------------------------- */
.rows { list-style: none; margin: 0; padding: 0; }
.rows li { border-bottom: 1px solid var(--color-border); }
.rows li:last-child { border-bottom: 0; }
/* Unread is marked on the start edge so it mirrors with the page direction. */
.rows li.unread { border-inline-start: 3px solid var(--color-nav); }
.row { display: flex; align-items: start; justify-content: space-between; gap: 1rem; width: 100%; padding: .8rem .7rem; border: 0; background: transparent; text-align: start; flex-wrap: wrap; }
.row:hover { background: var(--color-surface-hover); }
.row-main { display: flex; flex-direction: column; gap: .2rem; min-width: 0; flex: 1 1 18rem; }
.row-title { font-size: .88rem; font-weight: 600; color: var(--color-foreground); }
.rows li.unread .row-title { font-weight: 700; }
.row-body { font-size: .8rem; color: var(--color-black-700); }
.row-meta { display: flex; flex-direction: column; align-items: end; gap: .2rem; }
.event { padding: .12rem .5rem; border-radius: 999px; background: var(--color-black-100); color: var(--color-black-700); font-size: .7rem; white-space: nowrap; }
.when { color: var(--color-muted); font-size: .72rem; white-space: nowrap; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; margin-bottom: 1rem; color: var(--color-muted); font-size: .84rem; }

/* -- Preferences ---------------------------------------------------------- */
.table-wrap { overflow-x: auto; margin-top: 1rem; }
table { width: 100%; min-width: 380px; border-collapse: collapse; }
th, td { padding: .6rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
.event-name { font-size: .84rem; }
.check { text-align: center; }
.check input { width: 1rem; height: 1rem; cursor: pointer; }
.phone { display: flex; flex-direction: column; gap: .3rem; max-width: 20rem; margin-top: 1rem; color: var(--color-black-700); font-size: .85rem; }
.phone input { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.phone input:focus { outline: 2px solid var(--color-nav); outline-offset: 1px; }
.phone small { color: var(--color-muted); font-size: .72rem; }
.actions { display: flex; align-items: center; gap: .75rem; margin-top: 1rem; }
.saved { color: #166534; font-size: .8rem; }
.failed { color: #b91c1c; font-size: .8rem; }
</style>
