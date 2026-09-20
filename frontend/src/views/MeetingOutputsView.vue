<script setup>
/**
 * Stage 37 — one meeting's decisions followed back into the live request
 * workflow, through final approval, execution, and status-only closure.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import RequestClosurePanel from '../components/RequestClosurePanel.vue'
import RequestExecutionPanel from '../components/RequestExecutionPanel.vue'
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
//
// Stage 76 — recording the effect stopped being a one-click confirm. Appendix
// 70 requires دليل التنفيذ, so both actions are now panels that own their own
// posting and hand the refreshed tracker back.
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
  return 'warn'
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
  <section class="page outputs">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.outputs.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.outputs.subtitle') }}</p>
      </div>
      <button v-if="meetingId" class="ghost" type="button" :disabled="loading" @click="load()">
        {{ t('meetingsUnit.outputs.refresh') }}
      </button>
    </div>

    <div class="card card-flat card-pad picker">
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
      <section class="card card-flat card-pad meeting-summary">
        <div>
          <span class="eyebrow">{{ tracker.meeting.meeting_number || t('meetingsUnit.outputs.meeting') }}</span>
          <h2>{{ tracker.meeting.title }}</h2>
          <p>{{ name(tracker.meeting.committee) }} — {{ dateTime(tracker.meeting.scheduled_at) }}</p>
        </div>
      </section>

      <section class="summary-grid" :aria-label="t('meetingsUnit.outputs.summary.title')">
        <article v-for="card in summaryCards" :key="card.key" class="card card-flat card-pad summary-card">
          <span>{{ t(`meetingsUnit.outputs.summary.${card.key}`) }}</span>
          <strong>{{ card.value }}</strong>
        </article>
      </section>

      <section class="card card-flat card-pad table-card">
        <div class="table-heading">
          <div>
            <h3>{{ t('meetingsUnit.outputs.tableTitle') }}</h3>
            <p>{{ t('meetingsUnit.outputs.liveHint') }}</p>
          </div>
        </div>

        <p v-if="!tracker.outputs.length" class="state">{{ t('meetingsUnit.outputs.empty') }}</p>
        <div v-else class="table-wrap">
          <table class="data-table">
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
                  <RequestExecutionPanel
                    v-if="output.can_mark_executed && output.request"
                    :meeting-id="tracker.meeting.id"
                    :agenda-item-id="output.agenda_item_id"
                    :attachments="output.request.attachments"
                    :has-financial-impact="output.has_financial_impact"
                    @executed="(data) => (tracker = data)"
                  />
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
.page.outputs { max-inline-size: 90rem; }
.picker, .meeting-summary, .table-card { margin-bottom: var(--space-4); }
.picker label { display: flex; flex-direction: column; gap: .3rem; max-inline-size: 25rem; color: var(--color-black-700); font-size: var(--text-base); }
select { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; }
.muted { color: var(--color-muted); font-size: var(--text-sm); }

.meeting-summary { display: flex; justify-content: space-between; align-items: center; }
.meeting-summary h2 { margin: .2rem 0; color: var(--color-black-800); font-size: var(--text-lg); }
.meeting-summary p { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.eyebrow { color: var(--color-brand-text); font-size: var(--text-xs); font-weight: 700; }
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: var(--space-3); margin-bottom: var(--space-4); }
.summary-card { display: flex; flex-direction: column; gap: .35rem; min-block-size: 4.5rem; }
.summary-card span { color: var(--color-muted); font-size: var(--text-xs); }
.summary-card strong { color: var(--color-brand-text); font-size: 1.45rem; font-variant-numeric: tabular-nums; }
.table-heading h3 { margin: 0; color: var(--color-black-800); font-size: var(--text-lg); }
.table-heading p { margin: .25rem 0 var(--space-4); color: var(--color-muted); font-size: var(--text-sm); }
.data-table { min-inline-size: 75rem; }
.data-table th, .data-table td { vertical-align: top; font-size: var(--text-sm); }
.data-table th { color: var(--color-black-700); background: var(--color-surface-hover); }
.data-table td { color: var(--color-black-700); }
td small { display: block; margin-top: .25rem; color: var(--color-muted); max-inline-size: 18rem; }
.request-link { display: flex; flex-direction: column; gap: .2rem; color: var(--color-foreground); text-decoration: none; min-inline-size: 12rem; }
.request-link:hover strong { color: var(--color-brand-text); }
.ref { color: var(--color-brand-text); font-size: var(--text-xs); }
@media (max-width: 720px) { .heading { align-items: stretch; flex-direction: column; } }
</style>
