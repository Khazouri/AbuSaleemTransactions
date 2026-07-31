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
import SignaturePad from '../components/SignaturePad.vue'
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

// --- Voting & decisions (Stage 21) -------------------------------------------

const votingError = ref({})
const votingBusy = ref({})
const decisionComments = ref({})
const decisionError = ref({})
const decidingBusy = ref({})
const signatureReady = ref({})
const signaturePads = new Map()

function setSignaturePad(id, instance) {
  if (instance) signaturePads.set(id, instance)
  else signaturePads.delete(id)
}

function tally(item) {
  const counts = { approve: 0, reject: 0, defer: 0 }
  for (const vote of item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return counts
}

// Same plurality rule DecisionController::record applies server-side — used
// here only to decide whether to show the signature pad before submitting.
function predictedOutcome(item) {
  const counts = tally(item)
  const max = Math.max(counts.approve, counts.reject, counts.defer)
  if (max === 0) return null
  const leaders = Object.entries(counts).filter(([, count]) => count === max)
  return leaders.length === 1 ? leaders[0][0] : null
}

function myVote(item) {
  return (item.votes ?? []).find((vote) => vote.user.id === auth.user?.id)?.vote ?? null
}

async function castVote(item, voteValue) {
  votingError.value[item.id] = ''
  votingBusy.value[item.id] = true
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda/${item.id}/votes`, { vote: voteValue })
    await load()
  } catch (requestError) {
    votingError.value[item.id] = requestError.response?.data?.message ?? t('common.none')
  } finally {
    votingBusy.value[item.id] = false
  }
}

async function recordDecision(item) {
  decisionError.value[item.id] = ''
  decidingBusy.value[item.id] = true
  try {
    const form = new FormData()
    const comment = decisionComments.value[item.id]?.trim()
    if (comment) form.append('comment', comment)
    if (predictedOutcome(item) === 'approve') {
      const signature = await signaturePads.get(item.id)?.toFile()
      if (signature) form.append('signature', signature)
    }
    await api.post(`/meetings/${meeting.value.id}/agenda/${item.id}/decision`, form)
    delete decisionComments.value[item.id]
    delete signatureReady.value[item.id]
    await load()
  } catch (requestError) {
    decisionError.value[item.id] = requestError.response?.data?.message ?? t('common.none')
  } finally {
    decidingBusy.value[item.id] = false
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
              <div class="row">
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
              </div>

              <div class="decision-block">
                <template v-if="item.decision">
                  <p class="decision-result">
                    {{ t(`decisions.outcome.${item.decision.outcome}`) }}
                    — {{ t('decisions.decidedBy') }} {{ item.decision.decided_by?.name }}
                    ({{ dateTime(item.decision.decided_at) }})
                  </p>
                  <p v-if="item.decision.comment" class="decision-comment">{{ item.decision.comment }}</p>
                </template>
                <template v-else>
                  <div class="tally">
                    <span>{{ t('decisions.tally.approve') }}: {{ tally(item).approve }}</span>
                    <span>{{ t('decisions.tally.reject') }}: {{ tally(item).reject }}</span>
                    <span>{{ t('decisions.tally.defer') }}: {{ tally(item).defer }}</span>
                  </div>

                  <div v-can="'decisions.add'" class="vote-actions">
                    <button
                      v-for="option in ['approve', 'reject', 'defer']"
                      :key="option"
                      class="ghost"
                      :class="{ active: myVote(item) === option }"
                      type="button"
                      :disabled="votingBusy[item.id]"
                      @click="castVote(item, option)"
                    >
                      {{ t(`decisions.vote.${option}`) }}
                    </button>
                  </div>
                  <p v-if="votingError[item.id]" class="alert">{{ votingError[item.id] }}</p>

                  <div v-can="'decisions.approve'" class="record-decision">
                    <textarea
                      v-model="decisionComments[item.id]"
                      :placeholder="t('decisions.commentPlaceholder')"
                      :aria-label="t('decisions.commentPlaceholder')"
                      rows="2"
                    />
                    <SignaturePad
                      v-if="predictedOutcome(item) === 'approve'"
                      :ref="(instance) => setSignaturePad(item.id, instance)"
                      :disabled="decidingBusy[item.id]"
                      @change="signatureReady[item.id] = $event"
                    />
                    <div class="actions">
                      <button
                        class="primary"
                        type="button"
                        :disabled="decidingBusy[item.id] || !predictedOutcome(item) || (predictedOutcome(item) === 'approve' && !signatureReady[item.id])"
                        @click="recordDecision(item)"
                      >
                        {{ decidingBusy[item.id] ? t('decisions.recording') : t('decisions.record') }}
                      </button>
                    </div>
                    <p v-if="decisionError[item.id]" class="alert">{{ decisionError[item.id] }}</p>
                  </div>
                </template>
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
.agenda li { display: grid; gap: .5rem; padding-bottom: .75rem; border-bottom: 1px solid #f3f4f6; font-size: .86rem; }
.agenda li .row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.ref { color: #9ca3af; font-size: .78rem; margin-inline-end: .5rem; }
.pill { margin-inline-start: .5rem; padding: .1rem .5rem; background: #f3f4f6; color: #4b5563; border-radius: 999px; font-size: .72rem; }
.item-actions { white-space: nowrap; }
.agenda-search input { width: 100%; padding: .5rem .6rem; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
.results { display: grid; gap: .4rem; padding: 0; margin: .5rem 0 0; list-style: none; }
.result { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .84rem; }

.decision-block { display: grid; gap: .5rem; padding: .65rem .75rem; background: #f9fafb; border: 1px solid #f3f4f6; border-radius: 8px; }
.decision-result { margin: 0; color: #0f5132; font-size: .82rem; font-weight: 600; }
.decision-comment { margin: 0; color: #6b7280; font-size: .8rem; }
.tally { display: flex; gap: .85rem; color: #6b7280; font-size: .78rem; }
.vote-actions { display: flex; gap: .4rem; }
.vote-actions button.active { background: #0f5132; color: #fff; border-color: #0f5132; }
.record-decision { display: grid; gap: .5rem; margin-top: .25rem; padding-top: .5rem; border-top: 1px dashed #e5e7eb; }
.record-decision textarea { width: 100%; padding: .45rem .6rem; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical; font: inherit; box-sizing: border-box; }
.record-decision .actions { margin: 0; display: flex; justify-content: flex-end; }

.attendance ul { display: grid; gap: .5rem; padding: 0; margin: 0 0 .85rem; list-style: none; }
.attendance li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .85rem; }
label.checkbox { display: flex; align-items: center; gap: .4rem; }
.add-attendee { display: flex; gap: .5rem; }

@media (max-width: 720px) { .columns { grid-template-columns: 1fr; } }
</style>
