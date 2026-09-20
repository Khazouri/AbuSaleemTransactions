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

  if (notification.request_id) {
    router.push({ name: 'request_details', params: { id: notification.request_id } })
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
  <section class="page notifications">
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

    <div class="card card-flat card-pad list">
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
              <span class="pill event">{{ eventLabel(item.event_type) }}</span>
              <span class="when">{{ timestamp(item.created_at) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </div>

    <nav v-if="!store.loading && store.page.last_page > 1" class="pagination" :aria-label="t('notifications.title')">
      <button class="ghost" :disabled="store.page.current_page <= 1" @click="load(store.page.current_page - 1)">
        {{ t('requests.previous') }}
      </button>
      <span>{{ t('requests.page', { current: store.page.current_page, last: store.page.last_page }) }}</span>
      <button class="ghost" :disabled="store.page.current_page >= store.page.last_page" @click="load(store.page.current_page + 1)">
        {{ t('requests.next') }}
      </button>
    </nav>

    <!-- Preferences: one row per event, one checkbox per channel. -->
    <form class="card card-flat card-pad prefs" @submit.prevent="saveSettings">
      <h3>{{ t('notifications.preferences') }}</h3>
      <p class="subtitle">{{ t('notifications.preferencesHint') }}</p>

      <div class="table-wrap">
        <table class="data-table">
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
h3 { margin: 0 0 .25rem; }
.head-actions { display: flex; gap: var(--space-2); }

/* -- List ----------------------------------------------------------------- */
.rows { list-style: none; margin: 0; padding: 0; }
.rows li { border-bottom: 1px solid var(--color-border); }
.rows li:last-child { border-bottom: 0; }
/* Unread is marked on the start edge so it mirrors with the page direction. */
.rows li.unread { border-inline-start: 3px solid var(--color-brand-text); }
.row { display: flex; align-items: start; justify-content: space-between; gap: var(--space-4); width: 100%; padding: .8rem .7rem; border: 0; background: transparent; text-align: start; flex-wrap: wrap; cursor: pointer; }
.row:hover { background: var(--color-surface-hover); }
.row-main { display: flex; flex-direction: column; gap: .2rem; min-width: 0; flex: 1 1 18rem; }
.row-title { font-size: var(--text-lg); font-weight: 600; color: var(--color-foreground); }
.rows li.unread .row-title { font-weight: 700; }
.row-body { font-size: var(--text-sm); color: var(--color-black-700); }
.row-meta { display: flex; flex-direction: column; align-items: end; gap: .2rem; }
.when { color: var(--color-muted); font-size: var(--text-xs); white-space: nowrap; font-variant-numeric: tabular-nums; }

/* -- Preferences ---------------------------------------------------------- */
.table-wrap { overflow-x: auto; margin-top: var(--space-4); }
.data-table { min-width: 380px; }
.event-name { font-size: var(--text-sm); }
.check { text-align: center; }
.check input { width: 1rem; height: 1rem; cursor: pointer; }
.phone { display: flex; flex-direction: column; gap: .3rem; max-width: 20rem; margin-top: var(--space-4); color: var(--color-black-700); font-size: var(--text-base); }
.phone input { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.phone input:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.phone small { color: var(--color-muted); font-size: var(--text-xs); }
.actions { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--space-4); }
.saved { color: var(--color-success-fg); font-size: var(--text-sm); }
.failed { color: var(--color-danger-fg); font-size: var(--text-sm); }
</style>
