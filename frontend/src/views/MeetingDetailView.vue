<script setup>
/**
 * Single meeting workspace (اجتماع اللجنة) — Stage 20.
 *
 * Agenda reordering uses up/down buttons rather than drag-and-drop: it needs
 * no extra library and is just as usable for the handful of items a meeting
 * agenda realistically holds.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()

const meeting = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(new Date(value))
}
function statusLabel(status) {
  return t(`meetings.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/meetings/${route.params.id}`)
    meeting.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('transactionDetail.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- Meeting fields (status / minutes) --------------------------------------

const statusValue = ref('scheduled')
const minutesValue = ref('')
const savingMeeting = ref(false)

watch(meeting, (value) => {
  if (!value) return
  statusValue.value = value.status
  minutesValue.value = value.minutes ?? ''
})

async function saveMeetingFields() {
  savingMeeting.value = true
  actionError.value = ''
  try {
    const { data } = await api.put(`/meetings/${meeting.value.id}`, {
      status: statusValue.value,
      minutes: minutesValue.value || null,
    })
    meeting.value = data.data
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    savingMeeting.value = false
  }
}

async function removeMeeting() {
  if (!window.confirm(t('common.confirmDelete'))) return
  try {
    await api.delete(`/meetings/${meeting.value.id}`)
    window.history.back()
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

// --- Agenda ------------------------------------------------------------------

const agendaSearch = ref('')
const agendaResults = ref([])
const agendaSearching = ref(false)
const agendaError = ref('')
let searchTimer = null

const agendaTransactionIds = computed(() => new Set((meeting.value?.agenda_items ?? []).map((i) => i.transaction.id)))

watch(agendaSearch, (value) => {
  clearTimeout(searchTimer)
  if (!value.trim()) {
    agendaResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    agendaSearching.value = true
    try {
      const { data } = await api.get('/transactions', { params: { search: value.trim(), per_page: 5 } })
      agendaResults.value = data.data ?? []
    } catch {
      agendaResults.value = []
    } finally {
      agendaSearching.value = false
    }
  }, 300)
})

async function addToAgenda(transaction) {
  agendaError.value = ''
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda`, { transaction_id: transaction.id })
    agendaSearch.value = ''
    agendaResults.value = []
    await load()
  } catch (requestError) {
    agendaError.value = requestError.response?.data?.message
      ?? requestError.response?.data?.errors?.transaction_id?.[0]
      ?? t('common.none')
  }
}

async function removeFromAgenda(item) {
  agendaError.value = ''
  try {
    await api.delete(`/meetings/${meeting.value.id}/agenda/${item.id}`)
    await load()
  } catch (requestError) {
    agendaError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

async function moveAgendaItem(index, direction) {
  const items = meeting.value.agenda_items
  const target = index + direction
  if (target < 0 || target >= items.length) return

  const order = items.map((i) => i.id)
  ;[order[index], order[target]] = [order[target], order[index]]

  agendaError.value = ''
  try {
    await api.put(`/meetings/${meeting.value.id}/agenda/reorder`, { order })
    await load()
  } catch (requestError) {
    agendaError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

// --- Attendance --------------------------------------------------------------

const userOptions = ref([])
const newAttendeeId = ref('')
const attendanceError = ref('')

const availableAttendeeOptions = computed(() => {
  const existingIds = new Set((meeting.value?.attendees ?? []).map((a) => a.user.id))
  return userOptions.value.filter((u) => !existingIds.has(u.id))
})

async function loadUserOptions() {
  try {
    const { data } = await api.get('/committees/user-options')
    userOptions.value = data.data ?? []
  } catch {
    userOptions.value = []
  }
}

async function markAttendance(attendee, attended) {
  attendanceError.value = ''
  try {
    await api.patch(`/meetings/${meeting.value.id}/attendees/${attendee.id}`, { attended })
    attendee.attended = attended
  } catch (requestError) {
    attendanceError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

async function addAttendee() {
  if (!newAttendeeId.value) return
  attendanceError.value = ''
  try {
    await api.post(`/meetings/${meeting.value.id}/attendees`, { user_id: newAttendeeId.value })
    newAttendeeId.value = ''
    await load()
  } catch (requestError) {
    attendanceError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

async function removeAttendee(attendee) {
  attendanceError.value = ''
  try {
    await api.delete(`/meetings/${meeting.value.id}/attendees/${attendee.id}`)
    await load()
  } catch (requestError) {
    attendanceError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

onMounted(async () => {
  await Promise.all([load(), loadUserOptions()])
})
</script>

<template>
  <section class="detail">
    <RouterLink class="back" :to="{ name: 'meetings' }">{{ t('meetings.back') }}</RouterLink>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="meeting">
      <header class="heading">
        <div>
          <p class="committee ltr-none">{{ name(meeting.committee) }}</p>
          <h2>{{ meeting.title }}</h2>
        </div>
        <button v-can="'meetings.delete'" class="ghost danger" type="button" @click="removeMeeting">
          {{ t('meetings.delete') }}
        </button>
      </header>

      <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>

      <section class="card summary">
        <div>
          <span>{{ t('meetings.scheduledAt') }}</span>
          <strong>{{ dateTime(meeting.scheduled_at) }}</strong>
        </div>
        <div v-if="meeting.location">
          <span>{{ t('meetings.location') }}</span>
          <strong>{{ meeting.location }}</strong>
        </div>
        <div>
          <span>{{ t('meetings.status') }}</span>
          <select v-model="statusValue" v-can="'meetings.edit'" :aria-label="t('meetings.status')" @change="saveMeetingFields">
            <option value="scheduled">{{ t('meetings.statusScheduled') }}</option>
            <option value="completed">{{ t('meetings.statusCompleted') }}</option>
            <option value="cancelled">{{ t('meetings.statusCancelled') }}</option>
          </select>
        </div>
      </section>

      <section v-can="'meetings.edit'" class="card">
        <h3>{{ t('meetings.minutes') }}</h3>
        <textarea v-model="minutesValue" :aria-label="t('meetings.minutes')" rows="4" />
        <div class="actions">
          <button class="primary" type="button" :disabled="savingMeeting" @click="saveMeetingFields">
            {{ savingMeeting ? t('common.saving') : t('meetings.saveMinutes') }}
          </button>
        </div>
      </section>

      <div class="columns">
        <section class="card agenda">
          <h3>{{ t('meetings.agenda.title') }}</h3>
          <p v-if="agendaError" class="alert">{{ agendaError }}</p>
          <p v-if="!meeting.agenda_items?.length" class="state">{{ t('meetings.agenda.empty') }}</p>
          <ol v-else>
            <li v-for="(item, index) in meeting.agenda_items" :key="item.id">
              <div>
                <span class="ref ltr">{{ item.transaction.reference_number || `#${item.transaction.id}` }}</span>
                <strong>{{ item.transaction.title }}</strong>
                <span v-if="item.transaction.status" class="pill">{{ name(item.transaction.status) }}</span>
              </div>
              <div v-can="'meetings.edit'" class="item-actions">
                <button class="ghost" type="button" :disabled="index === 0" @click="moveAgendaItem(index, -1)">↑</button>
                <button class="ghost" type="button" :disabled="index === meeting.agenda_items.length - 1" @click="moveAgendaItem(index, 1)">↓</button>
                <button class="ghost danger" type="button" @click="removeFromAgenda(item)">{{ t('meetings.agenda.remove') }}</button>
              </div>
            </li>
          </ol>

          <div v-can="'meetings.edit'" class="agenda-search">
            <input
              v-model="agendaSearch"
              type="text"
              :placeholder="t('meetings.agenda.searchPlaceholder')"
              :aria-label="t('meetings.agenda.searchPlaceholder')"
            />
            <ul v-if="agendaSearch.trim()" class="results">
              <li v-if="agendaSearching" class="state">{{ t('common.loading') }}</li>
              <template v-else>
                <li v-if="!agendaResults.length" class="state">{{ t('meetings.agenda.noResults') }}</li>
                <li v-for="result in agendaResults" :key="result.id" class="result">
                  <span class="ref ltr">{{ result.reference_number || `#${result.id}` }}</span>
                  <span>{{ result.title }}</span>
                  <button
                    class="ghost"
                    type="button"
                    :disabled="agendaTransactionIds.has(result.id)"
                    @click="addToAgenda(result)"
                  >
                    {{ t('meetings.agenda.add') }}
                  </button>
                </li>
              </template>
            </ul>
          </div>
        </section>

        <aside class="card attendance">
          <h3>{{ t('meetings.attendance.title') }}</h3>
          <p v-if="attendanceError" class="alert">{{ attendanceError }}</p>
          <p v-if="!meeting.attendees?.length" class="state">{{ t('meetings.attendance.empty') }}</p>
          <ul v-else>
            <li v-for="attendee in meeting.attendees" :key="attendee.id">
              <label class="checkbox">
                <input
                  type="checkbox"
                  :checked="attendee.attended"
                  @change="markAttendance(attendee, $event.target.checked)"
                />
                {{ attendee.user.name }}
              </label>
              <button v-can="'meetings.edit'" class="ghost danger" type="button" @click="removeAttendee(attendee)">
                {{ t('meetings.attendance.remove') }}
              </button>
            </li>
          </ul>

          <form v-can="'meetings.edit'" class="add-attendee" @submit.prevent="addAttendee">
            <select v-model="newAttendeeId" :aria-label="t('meetings.attendance.chooseUser')">
              <option value="">{{ t('meetings.attendance.chooseUser') }}</option>
              <option v-for="user in availableAttendeeOptions" :key="user.id" :value="user.id">
                {{ user.name }}
              </option>
            </select>
            <button class="ghost" type="submit" :disabled="!newAttendeeId">{{ t('meetings.attendance.add') }}</button>
          </form>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.detail { max-inline-size: 82rem; }
.back { display: inline-block; margin-bottom: .85rem; color: var(--color-nav, #0f5132); font-size: .85rem; text-decoration: none; }
.back:hover { text-decoration: underline; }
.heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.heading h2 { margin: .15rem 0 0; color: var(--color-nav, #0f5132); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.committee { margin: 0; color: #6b7280; font-size: .8rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.card h3 { margin: 0 0 .6rem; color: var(--color-nav, #0f5132); font-size: 1rem; }
.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; }
.summary div { display: grid; gap: .25rem; }
.summary span { color: #9ca3af; font-size: .76rem; }
.summary strong { color: #374151; font-size: .88rem; }
textarea { width: 100%; padding: .5rem .6rem; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical; font: inherit; box-sizing: border-box; }
select { padding: .35rem .5rem; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; }
.actions { margin-top: .75rem; }
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .55; }
.primary { padding: .5rem .9rem; border: 0; background: #0f5132; color: #fff; }
.ghost { padding: .35rem .6rem; border: 1px solid #d1d5db; background: #fff; margin-inline-start: .3rem; }
.ghost:hover { background: #f9fafb; }
.ghost.danger { color: #b91c1c; border-color: #fecaca; }
.alert { padding: .65rem .8rem; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; font-size: .875rem; margin: 0 0 .75rem; }
.state { color: #6b7280; font-size: .85rem; margin: 0; }

.columns { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(16rem, .8fr); gap: 1rem; align-items: start; }
.agenda ol { display: grid; gap: .6rem; padding: 0; margin: 0 0 1rem; list-style: none; }
.agenda li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding-bottom: .6rem; border-bottom: 1px solid #f3f4f6; font-size: .86rem; }
.ref { color: #9ca3af; font-size: .78rem; margin-inline-end: .5rem; }
.pill { margin-inline-start: .5rem; padding: .1rem .5rem; background: #f3f4f6; color: #4b5563; border-radius: 999px; font-size: .72rem; }
.item-actions { white-space: nowrap; }
.agenda-search input { width: 100%; padding: .5rem .6rem; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
.results { display: grid; gap: .4rem; padding: 0; margin: .5rem 0 0; list-style: none; }
.result { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .84rem; }

.attendance ul { display: grid; gap: .5rem; padding: 0; margin: 0 0 .85rem; list-style: none; }
.attendance li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .85rem; }
label.checkbox { display: flex; align-items: center; gap: .4rem; }
.add-attendee { display: flex; gap: .5rem; }

@media (max-width: 720px) { .columns { grid-template-columns: 1fr; } }
</style>
