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
import AgendaItemDecisionPanel from '../components/AgendaItemDecisionPanel.vue'
import AppModal from '../components/AppModal.vue'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const { t, locale } = useI18n()
const auth = useAuthStore()

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
// pending_confirmation → meetings.statusPendingConfirmation
function statusLabel(status) {
  return t(`meetings.status${status.replace(/(^|_)(\w)/g, (_, __, c) => c.toUpperCase())}`)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/meetings/${route.params.id}`)
    meeting.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('requestDetail.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- Meeting fields (status, date) -------------------------------------------

// Stage 102 — `scheduled` is reached only by every member accepting the date,
// so the dropdown offers just the two statuses a person sets by hand.
const statusValue = ref('')
const savingMeeting = ref(false)

async function saveMeetingFields() {
  if (!statusValue.value) return
  savingMeeting.value = true
  actionError.value = ''
  try {
    const { data } = await api.put(`/meetings/${meeting.value.id}`, {
      status: statusValue.value,
    })
    meeting.value = data.data
  } catch (requestError) {
    // Stage 34 — closing a meeting can genuinely 422 (unresolved agenda items).
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    statusValue.value = ''
    savingMeeting.value = false
  }
}

// Stage 102 — a member who cannot make the date declines; the مقرر answers by
// proposing another, which resets every member's answer on the server.
const newDate = ref('')
const showProposeDate = ref(false)
const canProposeDate = computed(() => meeting.value
  && !meeting.value.convened_at
  && ['pending_confirmation', 'scheduled'].includes(meeting.value.status))

watch(meeting, (value) => {
  if (!value?.scheduled_at) return
  const at = new Date(value.scheduled_at)
  newDate.value = new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
})

async function proposeDate() {
  savingMeeting.value = true
  actionError.value = ''
  try {
    const { data } = await api.put(`/meetings/${meeting.value.id}`, { scheduled_at: newDate.value })
    meeting.value = data.data
    showProposeDate.value = false
  } catch (requestError) {
    actionError.value = requestError.response?.data?.errors?.scheduled_at?.[0]
      ?? requestError.response?.data?.message
      ?? t('common.none')
  } finally {
    savingMeeting.value = false
  }
}

// Stage 102 — each invited member accepts or declines the date themselves.
const myInvitation = computed(() => (meeting.value?.attendees ?? [])
  .find((attendee) => attendee.user.id === auth.user?.id))
const responding = ref(false)

async function respond(response) {
  responding.value = true
  actionError.value = ''
  try {
    const { data } = await api.post(`/meetings/${meeting.value.id}/respond`, { response })
    meeting.value = data.data
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    responding.value = false
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

// Stage 30 — resend the meeting invitation from the detail page (the wizard
// already sends it once when scheduling).
const sendingInvitations = ref(false)

async function sendInvitations() {
  sendingInvitations.value = true
  actionError.value = ''
  try {
    const { data } = await api.post(`/meetings/${meeting.value.id}/send-invitations`)
    meeting.value = data.data
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    sendingInvitations.value = false
  }
}

// --- Agenda ------------------------------------------------------------------

const agendaSearch = ref('')
const agendaResults = ref([])
const agendaSearching = ref(false)
const agendaError = ref('')
// Stays open after each add, so several requests can be put on in one go.
const showAgendaSearch = ref(false)

function closeAgendaSearch() {
  showAgendaSearch.value = false
  agendaSearch.value = ''
}
let searchTimer = null

const agendaRequestIds = computed(() => new Set(
  (meeting.value?.agenda_items ?? []).filter((i) => i.request).map((i) => i.request.id),
))

watch(agendaSearch, (value) => {
  clearTimeout(searchTimer)
  if (!value.trim()) {
    agendaResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    agendaSearching.value = true
    try {
      // Stage 102 — only the committee's pending list feeds an agenda.
      const { data } = await api.get('/committee-candidates', { params: { search: value.trim(), per_page: 5 } })
      agendaResults.value = data.data ?? []
    } catch {
      agendaResults.value = []
    } finally {
      agendaSearching.value = false
    }
  }, 300)
})

async function addToAgenda(request) {
  agendaError.value = ''
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda`, { request_id: request.id })
    agendaSearch.value = ''
    agendaResults.value = []
    await load()
  } catch (requestError) {
    agendaError.value = requestError.response?.data?.message
      ?? requestError.response?.data?.errors?.request_id?.[0]
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
// Stage 102 — the five seats are invited automatically and nobody else, so
// there is no add/remove here; only the roll call at the sitting.

const attendanceError = ref('')

async function markAttendance(attendee, attended) {
  attendanceError.value = ''
  try {
    await api.patch(`/meetings/${meeting.value.id}/attendees/${attendee.id}`, { attended })
    attendee.attended = attended
  } catch (requestError) {
    attendanceError.value = requestError.response?.data?.message ?? t('common.none')
  }
}


// --- Decision templates (Stage 35) -------------------------------------------
// Fed to AgendaItemDecisionPanel, which owns the vote/tally/record-decision
// block itself — see that component's docblock for why this used to be
// duplicated inline here and in MeetingLiveView.vue.

const decisionTemplates = ref([])

async function loadDecisionTemplates() {
  try {
    const { data } = await api.get('/decisions/filters')
    decisionTemplates.value = data.data?.templates ?? []
  } catch {
    decisionTemplates.value = []
  }
}

onMounted(async () => {
  await Promise.all([load(), loadDecisionTemplates()])
})
</script>

<template>
  <section class="page detail">
    <RouterLink class="back" :to="{ name: 'meetings' }">{{ t('meetings.back') }}</RouterLink>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="meeting">
      <header class="heading">
        <div>
          <p class="committee">{{ name(meeting.committee) }}</p>
          <h2>{{ meeting.title }}</h2>
        </div>
        <div class="header-actions">
          <button
            v-can="'meetings.edit'"
            class="ghost"
            type="button"
            :disabled="sendingInvitations"
            @click="sendInvitations"
          >
            {{ sendingInvitations ? t('meetings.sendingInvitations') : t('meetings.sendInvitations') }}
          </button>
          <button v-can="'meetings.delete'" class="ghost danger" type="button" @click="removeMeeting">
            {{ t('meetings.delete') }}
          </button>
        </div>
      </header>

      <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>

      <!-- Stage 102 — the signed-in member's own answer to the proposed date. -->
      <section
        v-if="meeting.status === 'pending_confirmation' && myInvitation"
        class="card card-flat card-pad rsvp"
      >
        <p>
          {{ t('meetings.rsvp.prompt', { date: dateTime(meeting.scheduled_at) }) }}
          <span class="pill">{{ t(`meetings.attendance.invitation.${myInvitation.invitation_status}`) }}</span>
        </p>
        <div class="rsvp-actions">
          <button class="primary" type="button" :disabled="responding" @click="respond('accept')">
            {{ t('meetings.rsvp.accept') }}
          </button>
          <button class="ghost danger" type="button" :disabled="responding" @click="respond('decline')">
            {{ t('meetings.rsvp.decline') }}
          </button>
        </div>
      </section>
      <p v-else-if="meeting.status === 'pending_confirmation'" class="state">{{ t('meetings.rsvp.awaiting') }}</p>

      <section class="card card-flat card-pad summary">
        <div v-if="meeting.meeting_number">
          <span>{{ t('meetings.meetingNumber') }}</span>
          <strong>{{ meeting.meeting_number }}</strong>
        </div>
        <div>
          <span>{{ t('meetings.scheduledAt') }}</span>
          <strong>{{ dateTime(meeting.scheduled_at) }}</strong>
        </div>
        <div v-if="meeting.location">
          <span>{{ t('meetings.location') }}</span>
          <strong>{{ meeting.location }}</strong>
        </div>
        <div v-if="meeting.chairman">
          <span>{{ t('meetings.chairman') }}</span>
          <strong>{{ meeting.chairman.name }}</strong>
        </div>
        <div v-if="meeting.rapporteur">
          <span>{{ t('meetings.rapporteur') }}</span>
          <strong>{{ meeting.rapporteur.name }}</strong>
        </div>
        <div v-if="meeting.expected_duration_minutes">
          <span>{{ t('meetings.expectedDuration') }}</span>
          <strong>{{ meeting.expected_duration_minutes }}</strong>
        </div>
        <div v-if="meeting.agenda_deadline">
          <span>{{ t('meetings.agendaDeadline') }}</span>
          <strong>{{ dateTime(meeting.agenda_deadline) }}</strong>
        </div>
        <div>
          <span>{{ t('meetings.status') }}</span>
          <strong>{{ statusLabel(meeting.status) }}</strong>
          <select
            v-model="statusValue"
            v-can="'meetings.edit'"
            :disabled="savingMeeting || ['completed', 'cancelled'].includes(meeting.status)"
            :aria-label="t('meetings.changeStatus')"
            @change="saveMeetingFields"
          >
            <option value="">{{ t('meetings.changeStatus') }}</option>
            <option value="completed">{{ t('meetings.statusCompleted') }}</option>
            <option value="cancelled">{{ t('meetings.statusCancelled') }}</option>
          </select>
        </div>
      </section>

      <div v-if="canProposeDate" v-can="'meetings.edit'" class="propose-date-open">
        <button class="ghost" type="button" @click="showProposeDate = true">{{ t('meetings.rsvp.proposeDate') }}</button>
      </div>

      <AppModal v-if="showProposeDate" :title="t('meetings.rsvp.proposeDate')" @close="showProposeDate = false">
        <form class="propose-date" @submit.prevent="proposeDate">
          <p class="hint">{{ t('meetings.rsvp.proposeHint') }}</p>
          <label>
            <span>{{ t('meetings.rsvp.newDate') }}</span>
            <input v-model="newDate" type="datetime-local" required />
          </label>
          <p v-if="actionError" class="alert">{{ actionError }}</p>
          <div class="modal-actions">
            <button class="ghost" type="button" @click="showProposeDate = false">{{ t('common.cancel') }}</button>
            <button class="primary" type="submit" :disabled="savingMeeting">{{ t('meetings.rsvp.proposeDate') }}</button>
          </div>
        </form>
      </AppModal>

      <section v-if="meeting.description" class="card card-flat card-pad">
        <h3>{{ t('meetings.description') }}</h3>
        <p class="description">{{ meeting.description }}</p>
      </section>

      <section v-can="'meeting_minutes.view'" class="card card-flat card-pad minutes-link">
        <h3>{{ t('meetings.minutes') }}</h3>
        <p v-if="meeting.minutes_status" class="minutes-status">
          {{ t(`meetingsUnit.minutes.status.${meeting.minutes_status}`) }}
        </p>
        <RouterLink class="ghost" :to="{ name: 'meeting_minutes', query: { meeting: meeting.id } }">
          {{ t('meetings.openMinutes') }}
        </RouterLink>
      </section>

      <!-- Stage 37 — meeting-scoped outputs follow-up. -->
      <section v-can="'meeting_outputs.view'" class="card card-flat card-pad minutes-link">
        <h3>{{ t('meetingsUnit.outputs.title') }}</h3>
        <p class="minutes-status">{{ t('meetingsUnit.outputs.detailHint') }}</p>
        <RouterLink class="ghost" :to="{ name: 'meeting_outputs', query: { meeting: meeting.id } }">
          {{ t('meetingsUnit.outputs.open') }}
        </RouterLink>
      </section>

      <div class="columns">
        <section class="card card-flat card-pad agenda">
          <div class="agenda-heading">
            <h3>{{ t('meetings.agenda.title') }}</h3>
            <div class="agenda-heading-links">
              <button v-can="'meeting_agenda.edit'" class="ghost" type="button" @click="showAgendaSearch = true">
                {{ t('meetingsUnit.agenda.addItem') }}
              </button>
              <RouterLink
                v-can="'meeting_agenda.edit'"
                class="ghost"
                :to="{ name: 'meeting_agenda', query: { meeting: meeting.id } }"
              >
                {{ t('meetings.agenda.openBuilder') }}
              </RouterLink>
              <!-- Stage 34 — live meeting runner. -->
              <RouterLink class="ghost" :to="{ name: 'meeting_live', query: { meeting: meeting.id } }">
                {{ t('meetingsUnit.live.title') }}
              </RouterLink>
            </div>
          </div>
          <p v-if="agendaError" class="alert">{{ agendaError }}</p>
          <p v-if="!meeting.agenda_items?.length" class="state">{{ t('meetings.agenda.empty') }}</p>
          <ol v-else>
            <li v-for="(item, index) in meeting.agenda_items" :key="item.id">
              <div class="row">
                <div>
                  <template v-if="item.request">
                    <span class="ref ltr">{{ item.request.reference_number || `#${item.request.id}` }}</span>
                    <strong>{{ item.request.title }}</strong>
                    <span v-if="item.request.status" class="pill">{{ name(item.request.status) }}</span>
                  </template>
                  <template v-else-if="item.appeal">
                    <span class="ref ltr">#{{ item.appeal.id }}</span>
                    <strong>{{ item.appeal.appellant?.name ?? t('common.none') }}</strong>
                    <span class="pill">{{ t('meetings.agenda.itemType.appeal') }}</span>
                    <span v-if="item.appeal.original_request" class="pill">
                      {{ item.appeal.original_request.reference_number || `#${item.appeal.original_request.id}` }}
                    </span>
                  </template>
                  <template v-else>
                    <strong>{{ item.subject }}</strong>
                    <span class="pill">{{ t(`meetings.agenda.itemType.${item.item_type}`) }}</span>
                    <span v-if="item.department" class="pill">{{ name(item.department) }}</span>
                  </template>
                  <span v-if="item.priority" class="pill">{{ t(`meetings.agenda.priority.${item.priority}`) }}</span>
                  <span v-if="item.estimated_minutes" class="pill">{{ item.estimated_minutes }} {{ t('meetings.agenda.minutesShort') }}</span>
                </div>
                <div v-can="'meeting_agenda.edit'" class="item-actions">
                  <button class="ghost" type="button" :disabled="index === 0" @click="moveAgendaItem(index, -1)">↑</button>
                  <button class="ghost" type="button" :disabled="index === meeting.agenda_items.length - 1" @click="moveAgendaItem(index, 1)">↓</button>
                  <button class="ghost danger" type="button" @click="removeFromAgenda(item)">{{ t('meetings.agenda.remove') }}</button>
                </div>
              </div>

              <AgendaItemDecisionPanel
                v-if="['employee_request', 'appeal'].includes(item.item_type)"
                :meeting-id="meeting.id"
                :item="item"
                :templates="decisionTemplates"
                :meeting="meeting"
                @refresh="load"
              />
            </li>
          </ol>

          <AppModal v-if="showAgendaSearch" :title="t('meetingsUnit.agenda.addItem')" wide @close="closeAgendaSearch">
            <div class="agenda-search">
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
                      :disabled="agendaRequestIds.has(result.id)"
                      @click="addToAgenda(result)"
                    >
                      {{ t('meetings.agenda.add') }}
                    </button>
                  </li>
                </template>
              </ul>
              <p v-if="agendaError" class="alert">{{ agendaError }}</p>
              <div class="modal-actions">
                <button class="ghost" type="button" @click="closeAgendaSearch">{{ t('common.close') }}</button>
              </div>
            </div>
          </AppModal>
        </section>

        <aside class="card card-flat card-pad attendance">
          <h3>{{ t('meetings.attendance.title') }}</h3>
          <p v-if="attendanceError" class="alert">{{ attendanceError }}</p>
          <p v-if="!meeting.attendees?.length" class="state">{{ t('meetings.attendance.empty') }}</p>
          <ul v-else>
            <li v-for="attendee in meeting.attendees" :key="attendee.id">
              <div class="attendee-row">
                <label class="checkbox">
                  <input
                    type="checkbox"
                    :checked="attendee.attended"
                    @change="markAttendance(attendee, $event.target.checked)"
                  />
                  {{ attendee.user.name }}
                </label>
                <span class="pill invitation-status">
                  {{ t(`meetings.attendance.invitation.${attendee.invitation_status}`) }}
                </span>
              </div>
            </li>
          </ul>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.back { display: inline-block; margin-bottom: var(--space-3); color: var(--color-brand-text); font-size: var(--text-sm); text-decoration: none; }
.back:hover { text-decoration: underline; }
.heading h2 { margin: .15rem 0 0; }
.committee { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.header-actions { display: flex; gap: var(--space-2); }
.description { margin: 0; white-space: pre-wrap; color: var(--color-black-700); font-size: var(--text-lg); }
select { padding: .35rem .5rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.card h3 { margin: 0 0 var(--space-3); color: var(--color-brand-text); font-size: var(--text-lg); }
.card, .summary, .minutes-link, .agenda, .attendance { margin-bottom: var(--space-4); }
.minutes-link .ghost { display: inline-block; text-decoration: none; }
.minutes-status { margin: 0 0 var(--space-2); color: var(--color-muted); font-size: var(--text-sm); }

.columns { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(16rem, .8fr); gap: var(--space-4); align-items: start; }
.agenda-heading { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-3); }
.agenda-heading h3 { margin: 0; }
.agenda-heading .ghost { text-decoration: none; }
.agenda-heading-links { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.agenda ol { display: grid; gap: var(--space-3); padding: 0; margin: 0 0 var(--space-4); list-style: none; }
.agenda li { display: grid; gap: var(--space-2); padding-bottom: var(--space-3); border-bottom: 1px solid var(--color-border); font-size: var(--text-base); }
.agenda li .row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.ref { color: var(--color-muted); font-size: var(--text-sm); margin-inline-end: var(--space-2); }
.pill { margin-inline-start: var(--space-2); }
.item-actions { white-space: nowrap; display: flex; gap: var(--space-1); }
.agenda-search input { inline-size: 100%; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); box-sizing: border-box; }
.results { display: grid; gap: .4rem; padding: 0; margin: var(--space-2) 0 0; list-style: none; }
.result { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); font-size: var(--text-base); }

.attendance ul { display: grid; gap: var(--space-2); padding: 0; margin: 0 0 var(--space-3); list-style: none; }
.attendance li { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); font-size: var(--text-base); }
.attendee-row { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.invitation-status { padding: .2rem .4rem; font-size: var(--text-xs); }
label.checkbox { display: flex; align-items: center; gap: .4rem; }
.rsvp { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.rsvp p { margin: 0; }
.rsvp-actions { display: flex; gap: var(--space-2); }
.propose-date-open { margin-block-end: var(--space-4); }
.propose-date label { display: grid; gap: .25rem; font-size: var(--text-sm); }
.propose-date input { padding: .35rem .5rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: inherit; font: inherit; }
.propose-date .hint { margin: 0 0 var(--space-3); color: var(--color-muted); font-size: var(--text-sm); }

@media (max-width: 720px) { .columns { grid-template-columns: 1fr; } }
</style>
