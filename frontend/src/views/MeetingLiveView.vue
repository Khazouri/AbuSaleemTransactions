<script setup>
// Stage 34 — the live meeting runner. Item state (presented/discussion/
// voting/deciding/complete) and the discussion feed are new; the vote/tally/
// record-decision block below is lifted from MeetingDetailView.vue's Stage 21
// markup unchanged (same endpoints, same castVote/recordDecision logic) per
// this stage's own "reuse the existing vote/decision endpoints" scope.
// Polling GET /meetings/{id} every 5s is what keeps every attendee's runner
// — timer, votes, notes, item state — in sync without a socket.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import SignaturePad from '../components/SignaturePad.vue'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const { t, locale } = useI18n()
const auth = useAuthStore()

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(value))
}

// --- Meeting picker ----------------------------------------------------------

const meetings = ref([])
const meetingId = ref(route.query.meeting ? Number(route.query.meeting) : '')

async function loadMeetings() {
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } catch {
    meetings.value = []
  }
}

// --- Meeting + polling ---------------------------------------------------------

const meeting = ref(null)
const loading = ref(false)
const error = ref('')
const selectedItemId = ref(null)

async function load() {
  if (!meetingId.value) {
    meeting.value = null
    return
  }
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/meetings/${meetingId.value}`)
    meeting.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('meetingsUnit.live.error')
    meeting.value = null
  } finally {
    loading.value = false
  }
}

// Silent refresh for the polling tick — no loading spinner every 5s.
async function poll() {
  if (!meetingId.value) return
  try {
    const { data } = await api.get(`/meetings/${meetingId.value}`)
    meeting.value = data.data
  } catch {
    // Transient poll failures are not surfaced — the next tick tries again.
  }
}

let pollTimer = null
watch(meetingId, () => {
  clearInterval(pollTimer)
  selectedItemId.value = null
  load()
  if (meetingId.value) pollTimer = setInterval(poll, 5000)
})

// --- Ticking clock for the per-item timer -------------------------------------

const clockNow = ref(Date.now())
let clockTimer = null
onMounted(() => { clockTimer = setInterval(() => { clockNow.value = Date.now() }, 1000) })
onUnmounted(() => {
  clearInterval(clockTimer)
  clearInterval(pollTimer)
})

// --- Progress / current item ---------------------------------------------------

const totalCount = computed(() => meeting.value?.agenda_items?.length ?? 0)
const resolvedCount = computed(() => (meeting.value?.agenda_items ?? []).filter((i) => i.is_resolved).length)
const allResolved = computed(() => totalCount.value === 0 || resolvedCount.value === totalCount.value)

const currentItem = computed(() => {
  const items = meeting.value?.agenda_items ?? []
  if (!items.length) return null
  if (selectedItemId.value) {
    return items.find((i) => i.id === selectedItemId.value) ?? items[0]
  }
  return items.find((i) => !i.is_resolved) ?? items[items.length - 1]
})

const presentAttendees = computed(() => (meeting.value?.attendees ?? []).filter((a) => a.attended))

const elapsed = computed(() => {
  const changedAt = currentItem.value?.state_changed_at
  if (!changedAt) return null
  const seconds = Math.max(0, Math.floor((clockNow.value - new Date(changedAt).getTime()) / 1000))
  const minutes = String(Math.floor(seconds / 60)).padStart(2, '0')
  const secs = String(seconds % 60).padStart(2, '0')
  return `${minutes}:${secs}`
})

function itemStates(item) {
  return item.item_type === 'employee_request'
    ? ['presented', 'discussion', 'voting', 'deciding']
    : ['presented', 'discussion', 'voting', 'deciding', 'complete']
}

// --- Item state (chair only) ----------------------------------------------------

const stateBusy = ref(false)
const stateError = ref('')

async function setItemState(item, state) {
  stateError.value = ''
  stateBusy.value = true
  try {
    await api.patch(`/meetings/${meeting.value.id}/agenda/${item.id}/state`, { item_state: state })
    await load()
  } catch (requestError) {
    stateError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    stateBusy.value = false
  }
}

// --- Discussion feed -----------------------------------------------------------

const noteDraft = ref('')
const notesBusy = ref(false)
const notesError = ref('')

async function postNote(item) {
  if (!noteDraft.value.trim()) return
  notesError.value = ''
  notesBusy.value = true
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda/${item.id}/notes`, { note: noteDraft.value.trim() })
    noteDraft.value = ''
    await load()
  } catch (requestError) {
    notesError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    notesBusy.value = false
  }
}

// --- Voting & decisions (Stage 21 endpoints, unchanged) -------------------------

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

// --- Close meeting ---------------------------------------------------------------

const closing = ref(false)
const closeError = ref('')

async function closeMeeting() {
  closeError.value = ''
  closing.value = true
  try {
    const { data } = await api.put(`/meetings/${meeting.value.id}`, { status: 'completed' })
    meeting.value = data.data
  } catch (requestError) {
    closeError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    closing.value = false
  }
}

onMounted(loadMeetings)
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.live.title') }}</h1>
    <p class="subtitle">{{ t('meetingsUnit.live.subtitle') }}</p>

    <div class="card picker">
      <label>
        {{ t('meetingsUnit.live.chooseMeeting') }}
        <select v-model="meetingId">
          <option value="">{{ t('meetingsUnit.live.chooseMeetingPlaceholder') }}</option>
          <option v-for="m in meetings" :key="m.id" :value="m.id">{{ m.title }} — {{ dateTime(m.scheduled_at) }}</option>
        </select>
      </label>
    </div>

    <p v-if="!meetingId" class="state">{{ t('meetingsUnit.live.noMeetingSelected') }}</p>
    <p v-else-if="loading && !meeting" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="meeting">
      <div v-if="!meeting.convened_at" class="card notice warning">
        <span>{{ t('meetingsUnit.live.notConvened') }}</span>
        <RouterLink :to="{ name: 'meeting_readiness', query: { meeting: meeting.id } }">
          {{ t('meetingsUnit.live.goToReadiness') }}
        </RouterLink>
      </div>

      <header class="runner-header">
        <div>
          <p class="committee">{{ name(meeting.committee) }}</p>
          <h2>{{ meeting.title }}</h2>
        </div>
        <div class="header-actions">
          <span class="pill" :class="allResolved ? 'good' : 'bad'">
            {{ resolvedCount }}/{{ totalCount }} {{ t('meetingsUnit.live.progress.resolved') }}
          </span>
          <button
            v-can="'meetings.edit'"
            class="primary"
            type="button"
            :disabled="closing || !allResolved || !meeting.convened_at || meeting.status !== 'scheduled'"
            @click="closeMeeting"
          >
            {{ closing ? t('common.saving') : t('meetingsUnit.live.closeMeeting') }}
          </button>
        </div>
      </header>
      <p v-if="closeError" class="alert" role="alert">{{ closeError }}</p>

      <div class="video-placeholder">{{ t('meetingsUnit.live.videoPlaceholder') }}</div>

      <div class="columns">
        <aside class="card side">
          <h3>{{ t('meetingsUnit.live.presentAttendees') }}</h3>
          <p v-if="!presentAttendees.length" class="state">{{ t('meetingsUnit.live.noAttendees') }}</p>
          <ul v-else class="attendee-list">
            <li v-for="attendee in presentAttendees" :key="attendee.id">{{ attendee.user.name }}</li>
          </ul>

          <h3>{{ t('meetings.agenda.title') }}</h3>
          <p v-if="!meeting.agenda_items?.length" class="state">{{ t('meetings.agenda.empty') }}</p>
          <ol v-else class="agenda-list">
            <li
              v-for="item in meeting.agenda_items"
              :key="item.id"
              class="agenda-row"
              :class="{ active: currentItem && currentItem.id === item.id, resolved: item.is_resolved }"
              @click="selectedItemId = item.id"
            >
              <span class="agenda-label">{{ item.transaction ? item.transaction.title : item.subject }}</span>
              <span class="pill small">{{ t(`meetingsUnit.live.states.${item.item_state}`) }}</span>
            </li>
          </ol>
        </aside>

        <section v-if="currentItem" class="card current-item">
          <div class="item-heading">
            <div>
              <template v-if="currentItem.transaction">
                <span class="ref ltr">{{ currentItem.transaction.reference_number || `#${currentItem.transaction.id}` }}</span>
                <strong>{{ currentItem.transaction.title }}</strong>
              </template>
              <template v-else>
                <strong>{{ currentItem.subject }}</strong>
                <span class="pill">{{ t(`meetings.agenda.itemType.${currentItem.item_type}`) }}</span>
              </template>
              <span v-if="currentItem.priority" class="pill">{{ t(`meetings.agenda.priority.${currentItem.priority}`) }}</span>
              <span v-if="currentItem.estimated_minutes" class="pill">{{ currentItem.estimated_minutes }} {{ t('meetings.agenda.minutesShort') }}</span>
            </div>
            <p v-if="elapsed" class="timer">{{ t('meetingsUnit.live.timer') }}: <strong>{{ elapsed }}</strong></p>
          </div>

          <div v-can="'meeting_live.edit'" class="state-controls">
            <button
              v-for="state in itemStates(currentItem)"
              :key="state"
              class="ghost"
              :class="{ active: currentItem.item_state === state }"
              type="button"
              :disabled="stateBusy || currentItem.is_resolved"
              @click="setItemState(currentItem, state)"
            >
              {{ t(`meetingsUnit.live.states.${state}`) }}
            </button>
          </div>
          <p v-if="stateError" class="alert">{{ stateError }}</p>

          <div v-if="currentItem.item_type === 'employee_request'" class="decision-block">
            <template v-if="currentItem.decision">
              <p class="decision-result">
                {{ t(`decisions.outcome.${currentItem.decision.outcome}`) }}
                — {{ t('decisions.decidedBy') }} {{ currentItem.decision.decided_by?.name }}
                ({{ dateTime(currentItem.decision.decided_at) }})
              </p>
              <p v-if="currentItem.decision.comment" class="decision-comment">{{ currentItem.decision.comment }}</p>
            </template>
            <template v-else>
              <div class="tally">
                <span>{{ t('decisions.tally.approve') }}: {{ tally(currentItem).approve }}</span>
                <span>{{ t('decisions.tally.reject') }}: {{ tally(currentItem).reject }}</span>
                <span>{{ t('decisions.tally.defer') }}: {{ tally(currentItem).defer }}</span>
              </div>

              <div v-can="'decisions.add'" class="vote-actions">
                <button
                  v-for="option in ['approve', 'reject', 'defer']"
                  :key="option"
                  class="ghost"
                  :class="{ active: myVote(currentItem) === option }"
                  type="button"
                  :disabled="votingBusy[currentItem.id]"
                  @click="castVote(currentItem, option)"
                >
                  {{ t(`decisions.vote.${option}`) }}
                </button>
              </div>
              <p v-if="votingError[currentItem.id]" class="alert">{{ votingError[currentItem.id] }}</p>

              <div v-can="'decisions.approve'" class="record-decision">
                <textarea
                  v-model="decisionComments[currentItem.id]"
                  :placeholder="t('decisions.commentPlaceholder')"
                  :aria-label="t('decisions.commentPlaceholder')"
                  rows="2"
                />
                <SignaturePad
                  v-if="predictedOutcome(currentItem) === 'approve'"
                  :ref="(instance) => setSignaturePad(currentItem.id, instance)"
                  :disabled="decidingBusy[currentItem.id]"
                  @change="signatureReady[currentItem.id] = $event"
                />
                <div class="actions">
                  <button
                    class="primary"
                    type="button"
                    :disabled="decidingBusy[currentItem.id] || !predictedOutcome(currentItem) || (predictedOutcome(currentItem) === 'approve' && !signatureReady[currentItem.id])"
                    @click="recordDecision(currentItem)"
                  >
                    {{ decidingBusy[currentItem.id] ? t('decisions.recording') : t('decisions.record') }}
                  </button>
                </div>
                <p v-if="decisionError[currentItem.id]" class="alert">{{ decisionError[currentItem.id] }}</p>
              </div>
            </template>
          </div>

          <div class="discussion">
            <h4>{{ t('meetingsUnit.live.discussion.title') }}</h4>
            <ul v-if="currentItem.notes?.length" class="notes">
              <li v-for="note in currentItem.notes" :key="note.id">
                <div class="note-meta">
                  <strong>{{ note.created_by?.name ?? t('common.none') }}</strong>
                  <span class="note-time">{{ dateTime(note.created_at) }}</span>
                </div>
                <p>{{ note.note }}</p>
              </li>
            </ul>
            <p v-else class="state">{{ t('meetingsUnit.live.discussion.empty') }}</p>

            <div v-can="'meeting_live.add'" class="note-form">
              <textarea
                v-model="noteDraft"
                rows="2"
                :placeholder="t('meetingsUnit.live.discussion.placeholder')"
                :aria-label="t('meetingsUnit.live.discussion.placeholder')"
              />
              <button class="ghost" type="button" :disabled="notesBusy || !noteDraft.trim()" @click="postNote(currentItem)">
                {{ notesBusy ? t('common.saving') : t('meetingsUnit.live.discussion.post') }}
              </button>
            </div>
            <p v-if="notesError" class="alert">{{ notesError }}</p>
          </div>
        </section>
        <section v-else class="card current-item">
          <p class="state">{{ t('meetingsUnit.live.noItems') }}</p>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 84rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0 0 1rem; color: var(--color-muted); font-size: .85rem; }

.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); max-inline-size: 24rem; }
select, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px; background: var(--color-surface); color: var(--color-foreground); font: inherit; }

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: .5rem 0 0; }
.notice { font-size: .85rem; color: var(--color-black-700); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.notice.warning { background: var(--color-warning-bg); color: var(--color-warning-fg); border-color: var(--color-warning-border); }
.notice a { color: inherit; font-weight: 600; }

.runner-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: .5rem; }
.committee { margin: 0 0 .2rem; color: var(--color-muted); font-size: .8rem; }
.runner-header h2 { margin: 0; color: var(--color-brand-text); }
.header-actions { display: flex; align-items: center; gap: .6rem; }

.pill { display: inline-block; padding: .25rem .7rem; border-radius: 999px; font-size: .8rem; background: var(--color-surface-hover); border: 1px solid var(--color-border); }
.pill.small { font-size: .7rem; padding: .15rem .5rem; }
.pill.good { background: var(--color-success-bg); color: var(--color-success-fg); border-color: var(--color-success-border); }
.pill.bad { background: var(--color-danger-bg); color: var(--color-danger-fg); border-color: var(--color-danger-border); }

/* Deliberately fixed, not themed: a video feed area reads as a dark screen
   in both themes — same exception SignaturePad's canvas and ApprovalTrail's
   signature background make (see AGENTS.md's dark-mode note). */
.video-placeholder {
  display: flex; align-items: center; justify-content: center;
  block-size: 10rem; border-radius: 12px; margin-bottom: 1rem;
  background: #111; color: #d4d4d4;
  font-size: .85rem; border: 1px dashed var(--color-border-hover);
}

.columns { display: grid; grid-template-columns: minmax(220px, 26%) 1fr; gap: 1rem; align-items: start; }
@media (max-width: 60rem) { .columns { grid-template-columns: 1fr; } }

.side h3 { margin: 0 0 .5rem; font-size: .9rem; color: var(--color-black-800); }
.side h3:not(:first-child) { margin-top: 1rem; }
.attendee-list { list-style: none; margin: 0 0 .5rem; padding: 0; display: grid; gap: .3rem; font-size: .82rem; color: var(--color-black-700); }

.agenda-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
.agenda-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .5rem .6rem; border: 1px solid var(--color-border); border-radius: 8px; cursor: pointer; font-size: .82rem; }
.agenda-row:hover { background: var(--color-surface-hover); }
.agenda-row.active { border-color: var(--color-brand); background: var(--color-surface-hover); }
.agenda-row.resolved .agenda-label { color: var(--color-muted); text-decoration: line-through; }
.agenda-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.item-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .75rem; }
.item-heading .ref { font-size: .78rem; color: var(--color-muted); margin-inline-end: .4rem; }
.timer { margin: 0; font-size: .85rem; color: var(--color-black-700); white-space: nowrap; }
.timer strong { font-variant-numeric: tabular-nums; color: var(--color-brand-text); }

.state-controls { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .75rem; }
.state-controls button.active { background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }

.decision-block { display: grid; gap: .5rem; padding: .65rem .75rem; background: var(--color-surface-hover); border: 1px solid var(--color-border); border-radius: 8px; margin-bottom: .75rem; }
.decision-result { margin: 0; color: var(--color-brand-text); font-size: .82rem; font-weight: 600; }
.decision-comment { margin: 0; color: var(--color-muted); font-size: .8rem; }
.tally { display: flex; gap: .8rem; font-size: .82rem; color: var(--color-black-700); }
.vote-actions { display: flex; gap: .4rem; }
.vote-actions button.active { background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }
.record-decision { display: grid; gap: .5rem; margin-top: .25rem; padding-top: .5rem; border-top: 1px dashed var(--color-border-hover); }
.record-decision textarea { width: 100%; padding: .45rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px; background: var(--color-surface); resize: vertical; font: inherit; box-sizing: border-box; }
.record-decision .actions { margin: 0; display: flex; justify-content: flex-end; }

.discussion { padding-top: .5rem; border-top: 1px dashed var(--color-border-hover); }
.discussion h4 { margin: 0 0 .5rem; font-size: .88rem; color: var(--color-black-800); }
.notes { list-style: none; margin: 0 0 .6rem; padding: 0; display: grid; gap: .5rem; max-block-size: 14rem; overflow-y: auto; }
.notes li { padding: .5rem .6rem; background: var(--color-surface-hover); border-radius: 8px; }
.note-meta { display: flex; justify-content: space-between; gap: .5rem; font-size: .78rem; color: var(--color-muted); }
.notes p { margin: .25rem 0 0; font-size: .85rem; color: var(--color-black-700); }
.note-form { display: flex; gap: .5rem; align-items: flex-start; }
.note-form textarea { flex: 1; box-sizing: border-box; }

button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
</style>
