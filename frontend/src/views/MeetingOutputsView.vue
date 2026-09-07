<script setup>
/**
 * Stage 37 — one meeting's decisions followed back into the live request
 * workflow, through final approval, execution, and status-only closure.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import RequestClosurePanel from '../components/RequestClosurePanel.vue'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()

function name(entity) {
  if (!entity) return t('common.none')
  return (locale.value === 'ar' ? entity.name_ar : entity.name_en)
    || entity.name_ar
    || entity.name_en
    || t('common.none')
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const meetings = ref([])
const meetingId = ref(route.query.meeting ? Number(route.query.meeting) : '')
const tracker = ref(null)
const loading = ref(false)
const error = ref('')
const completingId = ref(null)
const actionError = ref('')

async function loadMeetings() {
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } catch {
    meetings.value = []
  }
}

async function load({ quiet = false } = {}) {
  if (!meetingId.value) {
    tracker.value = null
    return
  }
  if (!quiet) loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/meetings/${meetingId.value}/outputs`)
    tracker.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('meetingsUnit.outputs.error')
    tracker.value = null
  } finally {
    loading.value = false
  }
}

// Stage 69 — [D] Art. 38 keeps 19 (منفذة) and 20 (مغلقة ومؤرشفة) apart, so a
// row offers one of two actions: record the effect, then close the file.
//
// Stage 75 — only the first is a meeting-output action. Closure carries Art.
// 37's card and Appendix 47's audit, and is a request-level act (two of that
// article's four final paths close requests that never reached an agenda), so
// it runs through the shared RequestClosurePanel against the request itself.
async function markExecuted(output) {
  if (!window.confirm(t('meetingsUnit.outputs.executeConfirm'))) return

  completingId.value = output.agenda_item_id
  actionError.value = ''
  try {
    const { data } = await api.post(
      `/meetings/${meetingId.value}/outputs/${output.agenda_item_id}/execute`,
    )
    tracker.value = data.data
  } catch (requestError) {
    actionError.value = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.message
      ?? t('meetingsUnit.outputs.error')
  } finally {
    completingId.value = null
  }
}

watch(meetingId, load)

const summaryCards = computed(() => {
  if (!tracker.value) return []
  return ['total_items', 'decisions', 'advanced', 'awaiting_action', 'in_execution', 'executed', 'completed_closed']
    .map((key) => ({ key, value: tracker.value.summary[key] }))
})

function statusClass(code) {
  if (['completed_closed', 'archived', 'final_approved', 'executed'].includes(code)) return 'good'
  if (['in_execution', 'approved', 'decided', 'approved_with_conditions',
    'awaiting_municipal_approval', 'awaiting_central_approval'].includes(code)) return 'info'
  if (['rejected', 'cancelled', 'not_approved'].includes(code)) return 'bad'
  return 'pending'
}

let refreshTimer = null
onMounted(async () => {
  await loadMeetings()
  await load()
  // Approval actors work on separate screens; a light poll keeps their moves
  // visible here without asking the meeting unit to refresh manually.
  refreshTimer = window.setInterval(() => load({ quiet: true }), 30_000)
})
onUnmounted(() => window.clearInterval(refreshTimer))
</script>

<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>{{ t('meetingsUnit.outputs.title') }}</h1>
        <p class="subtitle">{{ t('meetingsUnit.outputs.subtitle') }}</p>
      </div>
      <button v-if="meetingId" class="ghost" type="button" :disabled="loading" @click="load()">
        {{ t('meetingsUnit.outputs.refresh') }}
      </button>
    </div>

    <div class="card picker">
      <label>
        {{ t('meetingsUnit.outputs.chooseMeeting') }}
        <select v-model="meetingId">
          <option value="">{{ t('meetingsUnit.outputs.chooseMeetingPlaceholder') }}</option>
          <option v-for="meeting in meetings" :key="meeting.id" :value="meeting.id">
            {{ meeting.title }} — {{ dateTime(meeting.scheduled_at) }}
          </option>
        </select>
      </label>
    </div>

    <p v-if="!meetingId" class="state">{{ t('meetingsUnit.outputs.noMeetingSelected') }}</p>
    <p v-else-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load()">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="tracker">
      <section class="card meeting-summary">
        <div>
          <span class="eyebrow">{{ tracker.meeting.meeting_number || t('meetingsUnit.outputs.meeting') }}</span>
          <h2>{{ tracker.meeting.title }}</h2>
          <p>{{ name(tracker.meeting.committee) }} — {{ dateTime(tracker.meeting.scheduled_at) }}</p>
        </div>
      </section>

      <section class="summary-grid" :aria-label="t('meetingsUnit.outputs.summary.title')">
        <article v-for="card in summaryCards" :key="card.key" class="card summary-card">
          <span>{{ t(`meetingsUnit.outputs.summary.${card.key}`) }}</span>
          <strong>{{ card.value }}</strong>
        </article>
      </section>

      <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>

      <section class="card table-card">
        <div class="table-heading">
          <div>
            <h3>{{ t('meetingsUnit.outputs.tableTitle') }}</h3>
            <p>{{ t('meetingsUnit.outputs.liveHint') }}</p>
          </div>
        </div>

        <p v-if="!tracker.outputs.length" class="state">{{ t('meetingsUnit.outputs.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('meetingsUnit.outputs.columns.request') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.employee') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.decision') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.decisionDate') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.nextAction') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.responsibleBody') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.status') }}</th>
                <th>{{ t('meetingsUnit.outputs.columns.action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="output in tracker.outputs" :key="output.agenda_item_id">
                <td>
                  <RouterLink
                    v-if="output.request"
                    class="request-link"
                    :to="{ name: 'request_details', params: { id: output.request.id } }"
                  >
                    <span class="ref ltr">{{ output.request.reference_number }}</span>
                    <strong>{{ output.request.title }}</strong>
                  </RouterLink>
                  <span v-else>{{ t('common.none') }}</span>
                </td>
                <td>{{ output.request?.employee?.name ?? t('common.none') }}</td>
                <td>
                  <template v-if="output.decision">
                    <strong>{{ t(`decisions.outcome.${output.decision.outcome}`) }}</strong>
                    <small v-if="output.decision.comment">{{ output.decision.comment }}</small>
                  </template>
                  <span v-else class="muted">{{ t('meetingsUnit.outputs.awaitingDecision') }}</span>
                </td>
                <td>{{ dateTime(output.decision?.decided_at) }}</td>
                <td>{{ name(output.next_action) }}</td>
                <td>{{ name(output.responsible_body) }}</td>
                <td>
                  <span
                    v-if="output.execution_status"
                    class="pill"
                    :class="statusClass(output.execution_status.code)"
                  >
                    {{ name(output.execution_status) }}
                  </span>
                  <span v-else>{{ t('common.none') }}</span>
                </td>
                <td>
                  <button
                    v-if="output.can_mark_executed"
                    v-can="'meeting_outputs.edit'"
                    class="primary compact"
                    type="button"
                    :disabled="completingId === output.agenda_item_id"
                    @click="markExecuted(output)"
                  >
                    {{ completingId === output.agenda_item_id
                      ? t('common.saving')
                      : t('meetingsUnit.outputs.markExecuted') }}
                  </button>
                  <RequestClosurePanel
                    v-else-if="output.can_close && output.request"
                    :request-id="output.request.id"
                    @closed="() => load({ quiet: true })"
                  />
                  <span v-else class="muted">{{ t('common.none') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 90rem; }
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0; color: var(--color-muted); font-size: .85rem; }
.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.picker label { display: flex; flex-direction: column; gap: .3rem; max-inline-size: 25rem; color: var(--color-black-700); font-size: .875rem; }
select { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px; background: var(--color-surface); color: var(--color-foreground); font: inherit; }
.state, .muted { color: var(--color-muted); font-size: .82rem; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; }
button { cursor: pointer; border-radius: 8px; font: inherit; font-size: .82rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.ghost { padding: .4rem .7rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.primary { padding: .5rem .8rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.compact { white-space: nowrap; padding: .4rem .65rem; }
.meeting-summary { display: flex; justify-content: space-between; align-items: center; }
.meeting-summary h2 { margin: .2rem 0; color: var(--color-black-800); font-size: 1.1rem; }
.meeting-summary p { margin: 0; color: var(--color-muted); font-size: .82rem; }
.eyebrow { color: var(--color-brand-text); font-size: .75rem; font-weight: 700; }
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; }
.summary-card { display: flex; flex-direction: column; gap: .35rem; min-block-size: 4.5rem; }
.summary-card span { color: var(--color-muted); font-size: .75rem; }
.summary-card strong { color: var(--color-brand-text); font-size: 1.45rem; font-variant-numeric: tabular-nums; }
.table-heading h3 { margin: 0; color: var(--color-black-800); font-size: 1rem; }
.table-heading p { margin: .25rem 0 1rem; color: var(--color-muted); font-size: .78rem; }
.table-wrap { overflow-x: auto; }
table { inline-size: 100%; border-collapse: collapse; min-inline-size: 75rem; }
th, td { padding: .65rem .55rem; border-bottom: 1px solid var(--color-border); text-align: start; vertical-align: top; font-size: .78rem; }
th { color: var(--color-black-700); background: var(--color-surface-hover); white-space: nowrap; }
td { color: var(--color-black-700); }
td small { display: block; margin-top: .25rem; color: var(--color-muted); max-inline-size: 18rem; }
.request-link { display: flex; flex-direction: column; gap: .2rem; color: var(--color-foreground); text-decoration: none; min-inline-size: 12rem; }
.request-link:hover strong { color: var(--color-brand-text); }
.ref { color: var(--color-brand-text); font-size: .72rem; }
.pill { display: inline-block; padding: .22rem .55rem; border: 1px solid var(--color-border); border-radius: 999px; white-space: nowrap; font-size: .72rem; }
.pill.good { color: var(--color-success-fg); background: var(--color-success-bg); border-color: var(--color-success-border); }
.pill.info { color: var(--color-info-fg); background: var(--color-info-bg); border-color: var(--color-info-border); }
.pill.bad { color: var(--color-danger-fg); background: var(--color-danger-bg); border-color: var(--color-danger-border); }
.pill.pending { color: var(--color-warning-fg); background: var(--color-warning-bg); border-color: var(--color-warning-border); }
@media (max-width: 720px) { .page { padding: 1rem; } .page-heading { align-items: stretch; flex-direction: column; } }
</style>
