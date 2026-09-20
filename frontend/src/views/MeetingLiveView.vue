<script setup>
// Stage 34 — the live meeting runner. Item state (presented/discussion/
// voting/deciding/complete) and the discussion feed are new. The vote/tally/
// record-decision block is Stage 35's shared AgendaItemDecisionPanel (it used
// to be lifted, duplicated, from MeetingDetailView.vue's Stage 21 markup —
// see that component's docblock).
// Polling GET /meetings/{id} every 5s is what keeps every attendee's runner
// — timer, votes, notes, item state — in sync without a socket.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import AgendaItemDecisionPanel from '../components/AgendaItemDecisionPanel.vue'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const { t, locale } = useI18n()
const auth = useAuthStore()

// Stage 82 — the same gate the runner's own state controls use, needed here as
// a script-side boolean because a checkbox's `disabled` can't be set by the
// v-can directive (which only toggles display).
const canRunItems = computed(() => auth.can('meeting_live', 'edit'))

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
  // Deep-linked from the agenda builder's "open presentation memo" action
  // (?meeting=&item=) — only honoured once, on the meeting that was actually
  // requested; a plain meeting switch through the picker still defaults to
  // the first unresolved item.
  selectedItemId.value = route.query.item && meetingId.value === Number(route.query.meeting)
    ? Number(route.query.item)
    : null
  load()
  if (meetingId.value) pollTimer = setInterval(poll, 5000)
}, { immediate: true })

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
  // Stage 63 — an appeal item completes the same way an employee_request
  // item does (a recorded decision), never a manual `complete` click.
  return ['employee_request', 'appeal'].includes(item.item_type)
    ? ['presented', 'discussion', 'voting', 'deciding']
    : ['presented', 'discussion', 'voting', 'deciding', 'complete']
}

/** Stage 63 — a label for an agenda item that has neither a request nor a subject. */
function agendaItemLabel(item) {
  if (item.request) return item.request.title
  if (item.appeal) return item.appeal.appellant?.name ?? t('common.none')
  return item.subject
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

// --- Quick-info tabs (Stage 44) --------------------------------------------
// [C] §6's six tabs: ملخص الطلب | بيانات الموظف | الدراسة | المرفقات |
// الطلبات السابقة | مالحظات اللجنة. The sixth is the discussion feed below,
// folded into the tab strip; the other five come from a dedicated read-only
// endpoint fetched whenever the current item changes.

const INFO_TABS = ['summary', 'employee', 'study', 'attachments', 'previous', 'memo', 'notes']
const activeTab = ref('summary')
const context = ref(null)
const contextLoading = ref(false)
const contextError = ref('')

async function loadContext(item) {
  context.value = null
  contextError.value = ''
  if (!meeting.value || !item || item.item_type !== 'employee_request') return
  contextLoading.value = true
  try {
    const { data } = await api.get(`/meetings/${meeting.value.id}/agenda/${item.id}/context`)
    context.value = data.data
  } catch (requestError) {
    contextError.value = requestError.response?.data?.message ?? t('meetingsUnit.live.context.loadError')
  } finally {
    contextLoading.value = false
  }
}

// Watches the id only — currentItem is a computed that recomputes on every
// 5s poll tick, and re-fetching context on every tick would be wasteful.
watch(() => currentItem.value?.id, (id) => {
  activeTab.value = 'summary'
  if (id) {
    loadContext(currentItem.value)
    loadMemo(currentItem.value)
    loadStudySequence(currentItem.value)
  } else {
    context.value = null
    memo.value = null
    studySequence.value = null
  }
})

// --- Stage 82: [D] Art. 85's per-item sequence (النموذج 11's card) -------------
//
// Its own fetch rather than a field on the agenda payload: two of the nine
// steps are derived from the item's votes and decision, and a resource has no
// business querying for those.

const studySequence = ref(null)
const stepBusy = ref('')
const stepError = ref('')

async function loadStudySequence(item) {
  studySequence.value = null
  if (!item) return
  try {
    const { data } = await api.get(`/meetings/${meeting.value.id}/agenda/${item.id}/study-sequence`)
    studySequence.value = data.data
  } catch {
    studySequence.value = null
  }
}

async function toggleStep(step) {
  if (step.mode === 'derived' || !step.applicable) return
  stepError.value = ''
  stepBusy.value = step.code
  try {
    const { data } = await api.patch(
      `/meetings/${meeting.value.id}/agenda/${currentItem.value.id}/study-sequence`,
      { step: step.code, done: !step.done },
    )
    studySequence.value = data.data
    // The completion flag opens voting, so the agenda payload has to catch up.
    await load()
  } catch (requestError) {
    stepError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    stepBusy.value = ''
  }
}

function fullDate(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
}

function fileSize(bytes) {
  if (!bytes && bytes !== 0) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

async function downloadAttachment(attachment) {
  try {
    const { data } = await api.get(attachment.preview_url, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.original_name
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 0)
  } catch {
    contextError.value = t('attachments.downloadFailed')
  }
}

// --- Presentation memo (Stage 46, [D] Art. 22) --------------------------------
// A separate fetch from `context` above (its own endpoint/lifecycle, and it
// can be missing entirely until someone generates it), but driven by the
// same item-change watcher.

const memo = ref(null)
const memoLoading = ref(false)
const memoError = ref('')
const memoGenerating = ref(false)
const memoSaving = ref(false)
const memoDraft = ref({ facts_summary: '', employment_status_notes: '', legal_opinion: '', committee_question: '' })

function syncMemoDraft() {
  memoDraft.value = {
    facts_summary: memo.value?.authored?.facts_summary ?? '',
    employment_status_notes: memo.value?.authored?.employment_status_notes ?? '',
    legal_opinion: memo.value?.authored?.legal_opinion ?? '',
    committee_question: memo.value?.authored?.committee_question ?? '',
  }
}

async function loadMemo(item) {
  memo.value = null
  memoError.value = ''
  if (!meeting.value || !item || item.item_type !== 'employee_request') return
  memoLoading.value = true
  try {
    const { data } = await api.get(`/meetings/${meeting.value.id}/agenda/${item.id}/presentation-memo`)
    memo.value = data.data
    syncMemoDraft()
  } catch (requestError) {
    memoError.value = requestError.response?.data?.message ?? t('meetingsUnit.live.memo.loadError')
  } finally {
    memoLoading.value = false
  }
}

async function generateMemo(item) {
  memoError.value = ''
  memoGenerating.value = true
  try {
    const { data } = await api.post(`/meetings/${meeting.value.id}/agenda/${item.id}/presentation-memo/generate`)
    memo.value = data.data
    syncMemoDraft()
  } catch (requestError) {
    memoError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    memoGenerating.value = false
  }
}

async function saveMemo(item) {
  memoError.value = ''
  memoSaving.value = true
  try {
    const { data } = await api.patch(`/meetings/${meeting.value.id}/agenda/${item.id}/presentation-memo`, memoDraft.value)
    memo.value = data.data
    syncMemoDraft()
  } catch (requestError) {
    memoError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    memoSaving.value = false
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

// --- Decision templates (Stage 35) -----------------------------------------
// Fed to AgendaItemDecisionPanel — see its docblock for why the vote/tally/
// record-decision block used to be duplicated inline here.

const decisionTemplates = ref([])

async function loadDecisionTemplates() {
  try {
    const { data } = await api.get('/decisions/filters')
    decisionTemplates.value = data.data?.templates ?? []
  } catch {
    decisionTemplates.value = []
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

onMounted(async () => {
  await Promise.all([loadMeetings(), loadDecisionTemplates()])
})
</script>

<template>
  <section class="page live-meeting">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.live.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.live.subtitle') }}</p>
      </div>
    </div>

    <div class="card card-flat card-pad picker">
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
      <div v-if="!meeting.convened_at" class="card card-flat card-pad notice warning">
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
          <RouterLink v-can="'meeting_minutes.view'" class="ghost" :to="{ name: 'meeting_minutes', query: { meeting: meeting.id } }">
            {{ t('meetings.openMinutes') }}
          </RouterLink>
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

      <div class="columns">
        <aside class="card card-flat card-pad side">
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
              <span class="agenda-label">{{ agendaItemLabel(item) }}</span>
              <span class="pill small">{{ t(`meetingsUnit.live.states.${item.item_state}`) }}</span>
            </li>
          </ol>
        </aside>

        <section v-if="currentItem" class="card card-flat card-pad current-item">
          <div class="item-heading">
            <div>
              <template v-if="currentItem.request">
                <span class="ref ltr">{{ currentItem.request.reference_number || `#${currentItem.request.id}` }}</span>
                <strong>{{ currentItem.request.title }}</strong>
              </template>
              <template v-else-if="currentItem.appeal">
                <span class="ref ltr">#{{ currentItem.appeal.id }}</span>
                <strong>{{ currentItem.appeal.appellant?.name ?? t('common.none') }}</strong>
                <span class="pill">{{ t('meetings.agenda.itemType.appeal') }}</span>
                <span v-if="currentItem.appeal.original_request" class="pill">
                  {{ currentItem.appeal.original_request.reference_number || `#${currentItem.appeal.original_request.id}` }}
                </span>
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

          <!-- Stage 82 — النموذج 11's card: [D] Art. 85's nine-step sequence,
               grouped by Appendix 25's five إلزامية stages. The last two steps
               are read from the item's own votes and decision, so they render
               read-only rather than as ticks. -->
          <section v-if="studySequence" class="study-sequence">
            <h3>{{ t('meetingsUnit.live.studySequence.title') }}</h3>
            <p v-if="studySequence.material_frozen" class="alert">
              {{ t('meetingsUnit.live.studySequence.frozen') }}
            </p>
            <p v-else-if="!studySequence.is_complete" class="state">
              {{ t('meetingsUnit.live.studySequence.incomplete') }}
            </p>
            <ol class="steps">
              <li
                v-for="step in studySequence.steps"
                :key="step.code"
                :class="{ done: step.done, na: !step.applicable, derived: step.mode === 'derived' }"
              >
                <label>
                  <input
                    type="checkbox"
                    :checked="step.done"
                    :disabled="step.mode === 'derived' || !step.applicable || stepBusy === step.code || !canRunItems"
                    @change="toggleStep(step)"
                  />
                  <span class="step-name">{{ locale === 'ar' ? step.name_ar : step.name_en }}</span>
                  <span class="pill small">{{ locale === 'ar' ? step.stage_name_ar : step.stage_name_en }}</span>
                  <span v-if="step.mode === 'derived'" class="pill small">{{ t('meetingsUnit.live.studySequence.derived') }}</span>
                  <span v-else-if="step.mode === 'optional'" class="pill small">{{ t('meetingsUnit.live.studySequence.optional') }}</span>
                  <span v-else-if="!step.applicable" class="pill small">{{ t('meetingsUnit.live.studySequence.notApplicable') }}</span>
                </label>
              </li>
            </ol>
            <p v-if="stepError" class="alert" role="alert">{{ stepError }}</p>
          </section>

          <AgendaItemDecisionPanel
            v-if="['employee_request', 'appeal'].includes(currentItem.item_type)"
            :meeting-id="meeting.id"
            :item="currentItem"
            :templates="decisionTemplates"
            :meeting="meeting"
            @refresh="load"
          />

          <div v-if="currentItem.item_type === 'employee_request'" class="info-tabs">
            <div class="tabs" role="tablist" :aria-label="t('meetingsUnit.live.title')">
              <button
                v-for="tab in INFO_TABS"
                :key="tab"
                type="button"
                class="tab"
                role="tab"
                :aria-selected="activeTab === tab ? 'true' : 'false'"
                @click="activeTab = tab"
              >
                {{ t(`meetingsUnit.live.tabs.${tab}`) }}
              </button>
            </div>

            <p v-if="contextLoading && activeTab !== 'memo'" class="state">{{ t('common.loading') }}</p>
            <p v-else-if="contextError && activeTab !== 'notes' && activeTab !== 'memo'" class="alert">{{ contextError }}</p>

            <div v-else-if="context || activeTab === 'notes' || activeTab === 'memo'" class="tab-panel">
              <dl v-if="activeTab === 'summary'" class="info-grid">
                <dt>{{ t('meetingsUnit.live.context.summary.reference') }}</dt>
                <dd class="ltr">{{ context.request.reference_number || `#${context.request.id}` }}</dd>
                <dt>{{ t('meetingsUnit.live.context.summary.requestType') }}</dt>
                <dd>{{ context.request.request_type ? name(context.request.request_type) : t('common.none') }}</dd>
                <dt>{{ t('meetingsUnit.live.context.summary.department') }}</dt>
                <dd>{{ context.request.department ? name(context.request.department) : t('common.none') }}</dd>
                <dt>{{ t('meetingsUnit.live.context.summary.status') }}</dt>
                <dd>
                  <span class="status" :style="{ '--status-color': context.request.status?.color || 'var(--color-muted)' }">
                    {{ locale === 'ar' ? context.request.status?.name_ar : context.request.status?.name_en }}
                  </span>
                </dd>
                <dt>{{ t('meetingsUnit.live.context.summary.submitted') }}</dt>
                <dd>{{ fullDate(context.request.submitted_at) }}</dd>
                <dt>{{ t('meetingsUnit.live.context.summary.dueDate') }}</dt>
                <dd>{{ fullDate(context.request.due_date) }}</dd>
                <dt v-if="context.request.decision_grade">{{ t('meetingsUnit.live.context.summary.decisionGrade') }}</dt>
                <dd v-if="context.request.decision_grade">{{ context.request.decision_grade }}</dd>
                <dt>{{ t('meetingsUnit.live.context.summary.description') }}</dt>
                <dd>{{ context.request.description || t('meetingsUnit.live.context.summary.noDescription') }}</dd>
              </dl>

              <dl v-else-if="activeTab === 'employee'" class="info-grid">
                <template v-if="context.employee">
                  <dt>{{ t('meetingsUnit.live.context.employee.name') }}</dt>
                  <dd>{{ context.employee.name }}</dd>
                  <dt>{{ t('meetingsUnit.live.context.employee.email') }}</dt>
                  <dd class="ltr">{{ context.employee.email || t('common.none') }}</dd>
                  <dt>{{ t('meetingsUnit.live.context.employee.phone') }}</dt>
                  <dd class="ltr">{{ context.employee.phone || t('common.none') }}</dd>
                  <dt>{{ t('meetingsUnit.live.context.employee.department') }}</dt>
                  <dd>{{ context.employee.department ? name(context.employee.department) : t('common.none') }}</dd>
                  <dt>{{ t('meetingsUnit.live.context.employee.manager') }}</dt>
                  <dd>{{ context.employee.manager?.name ?? t('common.none') }}</dd>
                </template>
                <p v-else class="state">{{ t('meetingsUnit.live.context.employee.unavailable') }}</p>
              </dl>

              <div v-else-if="activeTab === 'study'">
                <p v-if="!context.study?.length" class="state">{{ t('meetingsUnit.live.context.study.empty') }}</p>
                <ul v-else class="notes">
                  <li v-for="log in context.study" :key="log.id">
                    <div class="note-meta">
                      <strong>{{ log.acted_by?.name ?? t('common.none') }}</strong>
                      <span class="note-time">{{ fullDate(log.acted_at) }}</span>
                    </div>
                    <p>{{ log.comment || t('common.none') }}</p>
                  </li>
                </ul>
              </div>

              <div v-else-if="activeTab === 'attachments'">
                <p v-if="!context.attachments?.length" class="state">{{ t('meetingsUnit.live.context.attachments.empty') }}</p>
                <ul v-else class="attachment-list">
                  <li v-for="attachment in context.attachments" :key="attachment.id">
                    <span>{{ attachment.original_name }}</span>
                    <span class="muted">{{ fileSize(attachment.size_bytes) }}</span>
                    <button class="ghost" type="button" @click="downloadAttachment(attachment)">
                      {{ t('attachments.download') }}
                    </button>
                  </li>
                </ul>
              </div>

              <div v-else-if="activeTab === 'previous'">
                <p v-if="!context.previous_requests?.length" class="state">{{ t('meetingsUnit.live.context.previous.empty') }}</p>
                <ul v-else class="attachment-list">
                  <li v-for="previous in context.previous_requests" :key="previous.id">
                    <span class="ltr">{{ previous.reference_number || `#${previous.id}` }}</span>
                    <span>{{ previous.title }}</span>
                    <span class="status" :style="{ '--status-color': previous.status?.color || 'var(--color-muted)' }">
                      {{ locale === 'ar' ? previous.status?.name_ar : previous.status?.name_en }}
                    </span>
                  </li>
                </ul>
              </div>

              <div v-else-if="activeTab === 'memo'" class="memo-panel">
                <p v-if="memoLoading" class="state">{{ t('common.loading') }}</p>
                <template v-else>
                  <p v-if="memoError" class="alert">{{ memoError }}</p>
                  <div v-can="'meeting_agenda.add'" class="memo-generate">
                    <button class="ghost" type="button" :disabled="memoGenerating" @click="generateMemo(currentItem)">
                      {{ memoGenerating ? t('common.saving') : (memo ? t('meetingsUnit.live.memo.regenerate') : t('meetingsUnit.live.memo.generate')) }}
                    </button>
                  </div>

                  <template v-if="memo">
                    <dl class="info-grid">
                      <dt>{{ t('meetingsUnit.live.context.summary.reference') }}</dt>
                      <dd class="ltr">{{ memo.derived.reference_number || `#${memo.derived.employee?.id}` }}</dd>
                      <dt>{{ t('meetingsUnit.live.memo.workUnit') }}</dt>
                      <dd>{{ memo.derived.work_unit ? name(memo.derived.work_unit) : t('common.none') }}</dd>
                      <dt>{{ t('meetingsUnit.live.memo.referringBody') }}</dt>
                      <dd>{{ memo.derived.referring_body ? name(memo.derived.referring_body) : t('common.none') }}</dd>
                      <dt>{{ t('meetingsUnit.live.context.summary.submitted') }}</dt>
                      <dd>{{ fullDate(memo.derived.submission_date) }}</dd>
                    </dl>

                    <h4>{{ t('meetingsUnit.live.memo.keyDocuments') }}</h4>
                    <p v-if="!memo.derived.key_documents?.length" class="state">{{ t('meetingsUnit.live.context.attachments.empty') }}</p>
                    <ul v-else class="attachment-list">
                      <li v-for="document in memo.derived.key_documents" :key="document.id">
                        <span>{{ document.original_name }}</span>
                        <span class="muted">{{ fileSize(document.size_bytes) }}</span>
                      </li>
                    </ul>

                    <h4>{{ t('meetingsUnit.live.memo.priorDecisions') }}</h4>
                    <p v-if="!memo.derived.prior_decisions?.length" class="state">{{ t('meetingsUnit.live.memo.noPriorDecisions') }}</p>
                    <ul v-else class="notes">
                      <li v-for="decision in memo.derived.prior_decisions" :key="decision.id">
                        <div class="note-meta">
                          <strong>{{ t(`decisions.outcome.${decision.outcome}`) }}</strong>
                          <span class="note-time">{{ fullDate(decision.decided_at) }}</span>
                        </div>
                        <p>{{ decision.comment || t('common.none') }}</p>
                      </li>
                    </ul>

                    <div v-can="'meeting_agenda.add'" class="memo-authored">
                      <label>
                        {{ t('meetingsUnit.live.memo.factsSummary') }}
                        <textarea v-model="memoDraft.facts_summary" rows="3" />
                      </label>
                      <label>
                        {{ t('meetingsUnit.live.memo.employmentStatus') }}
                        <textarea v-model="memoDraft.employment_status_notes" rows="2" />
                      </label>
                      <label>
                        {{ t('meetingsUnit.live.memo.legalOpinion') }}
                        <textarea v-model="memoDraft.legal_opinion" rows="2" />
                      </label>
                      <label>
                        {{ t('meetingsUnit.live.memo.committeeQuestion') }}
                        <textarea v-model="memoDraft.committee_question" rows="2" />
                      </label>
                      <button class="ghost" type="button" :disabled="memoSaving" @click="saveMemo(currentItem)">
                        {{ memoSaving ? t('common.saving') : t('common.save') }}
                      </button>
                    </div>
                  </template>
                  <p v-else class="state">{{ t('meetingsUnit.live.memo.notGenerated') }}</p>
                </template>
              </div>

              <div v-else-if="activeTab === 'notes'" class="discussion">
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
            </div>
          </div>

          <div v-else class="discussion">
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
        <section v-else class="card card-flat card-pad current-item">
          <p class="state">{{ t('meetingsUnit.live.noItems') }}</p>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.page.live-meeting { max-inline-size: 84rem; }
.picker { margin-bottom: var(--space-4); }
.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); max-inline-size: 24rem; }
select, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; }

.notice { margin-bottom: var(--space-4); display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); }
.notice a { color: inherit; font-weight: 600; }

.runner-header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-4); margin-bottom: var(--space-2); }
.committee { margin: 0 0 .2rem; color: var(--color-muted); font-size: var(--text-sm); }
.runner-header h2 { margin: 0; color: var(--color-brand-text); }
.header-actions { display: flex; align-items: center; gap: var(--space-2); }
.header-actions a.ghost { display: inline-block; text-decoration: none; }

.pill.small { font-size: var(--text-xs); padding: .15rem .5rem; }

.columns { display: grid; grid-template-columns: minmax(220px, 26%) 1fr; gap: var(--space-4); align-items: start; }
@media (max-width: 60rem) { .columns { grid-template-columns: 1fr; } }

.side h3 { margin: 0 0 var(--space-2); font-size: var(--text-lg); color: var(--color-black-800); }
.side h3:not(:first-child) { margin-top: var(--space-4); }
.attendee-list { list-style: none; margin: 0 0 var(--space-2); padding: 0; display: grid; gap: .3rem; font-size: var(--text-sm); color: var(--color-black-700); }

.agenda-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
.agenda-row { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); padding: .5rem .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); cursor: pointer; font-size: var(--text-sm); }
.agenda-row:hover { background: var(--color-surface-hover); }
.agenda-row.active { border-color: var(--color-brand); background: var(--color-surface-hover); }
.agenda-row.resolved .agenda-label { color: var(--color-muted); text-decoration: line-through; }
.agenda-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.item-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-4); flex-wrap: wrap; margin-bottom: var(--space-3); }
.item-heading .ref { font-size: var(--text-sm); color: var(--color-muted); margin-inline-end: .4rem; }
.timer { margin: 0; font-size: var(--text-sm); color: var(--color-black-700); white-space: nowrap; }
.timer strong { font-variant-numeric: tabular-nums; color: var(--color-brand-text); }

/* A sequential state-progression control, not a tab strip — advancing it
   performs an action, so it stays a filled button group rather than the
   underline role="tab" style the info-tabs strip below uses. */
.state-controls { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: var(--space-3); }
.state-controls button.active { background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }

.info-tabs { margin-bottom: var(--space-3); padding-top: var(--space-2); border-top: 1px dashed var(--color-border-hover); }
.info-tabs .tabs { margin-bottom: var(--space-3); }
.tab-panel { font-size: var(--text-sm); }
.info-grid { display: grid; grid-template-columns: max-content 1fr; gap: .35rem .75rem; margin: 0; }
.info-grid dt { color: var(--color-muted); font-size: var(--text-sm); }
.info-grid dd { margin: 0; color: var(--color-black-700); }
.attachment-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
.attachment-list li { display: flex; align-items: center; gap: var(--space-2); padding: .5rem .6rem; background: var(--color-surface-hover); border-radius: var(--radius-lg); flex-wrap: wrap; }
.attachment-list .muted { color: var(--color-muted); font-size: var(--text-sm); }

/* Stage 82 — النموذج 11's card on the current item. */
.study-sequence { padding-top: var(--space-2); margin-bottom: var(--space-3); border-top: 1px dashed var(--color-border-hover); }
.study-sequence h3 { margin: 0 0 .4rem; font-size: var(--text-lg); color: var(--color-brand-text); }
.study-sequence .steps { list-style: none; margin: .4rem 0 0; padding: 0; display: grid; gap: .3rem; }
.study-sequence .steps li label { display: flex; align-items: center; gap: .45rem; font-size: var(--text-base); }
.study-sequence .steps li.done .step-name { font-weight: 600; }
.study-sequence .steps li.na .step-name,
.study-sequence .steps li.derived .step-name { color: var(--color-black-600); }

.discussion { padding-top: var(--space-2); border-top: 1px dashed var(--color-border-hover); }
.discussion h4 { margin: 0 0 var(--space-2); font-size: var(--text-lg); color: var(--color-black-800); }

.memo-panel h4 { margin: var(--space-4) 0 var(--space-2); font-size: var(--text-lg); color: var(--color-black-800); }
.memo-generate { margin-bottom: var(--space-3); }
.memo-authored { display: grid; gap: .6rem; margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px dashed var(--color-border-hover); }
.memo-authored label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-sm); color: var(--color-black-700); }
.memo-authored textarea { box-sizing: border-box; inline-size: 100%; }
.notes { list-style: none; margin: 0 0 var(--space-3); padding: 0; display: grid; gap: var(--space-2); max-block-size: 14rem; overflow-y: auto; }
.notes li { padding: .5rem .6rem; background: var(--color-surface-hover); border-radius: var(--radius-lg); }
.note-meta { display: flex; justify-content: space-between; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-muted); }
.notes p { margin: .25rem 0 0; font-size: var(--text-sm); color: var(--color-black-700); }
.note-form { display: flex; gap: var(--space-2); align-items: flex-start; }
.note-form textarea { flex: 1; box-sizing: border-box; }
</style>
