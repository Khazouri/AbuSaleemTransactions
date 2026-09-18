<script setup>
// Stage 33 — the pre-meeting readiness gate: pick a meeting, see the four
// percentages/quorum/exceptions GET /meetings/{id}/readiness computes, and
// convene it (blocked without an override reason unless every exception is
// resolved). Meeting-picker pattern mirrors MeetingAgendaBuilderView.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()

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

// --- Readiness -----------------------------------------------------------------

const meeting = ref(null)
const readiness = ref(null)
const loading = ref(false)
const error = ref('')

async function load() {
  if (!meetingId.value) {
    meeting.value = null
    readiness.value = null
    return
  }
  loading.value = true
  error.value = ''
  try {
    const [{ data: meetingData }, { data: readinessData }] = await Promise.all([
      api.get(`/meetings/${meetingId.value}`),
      api.get(`/meetings/${meetingId.value}/readiness`),
    ])
    meeting.value = meetingData.data
    readiness.value = readinessData.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('meetingsUnit.readiness.error')
    meeting.value = null
    readiness.value = null
  } finally {
    loading.value = false
  }
}

watch(meetingId, load)

const PERCENTAGE_ROWS = [
  ['file', 'file_percentage'],
  ['member', 'member_percentage'],
  ['agenda', 'agenda_percentage'],
  ['invitation', 'invitation_percentage'],
]

const percentageRows = computed(() => {
  if (!readiness.value) return []
  return PERCENTAGE_ROWS.map(([key, apiKey]) => ({ key, value: readiness.value[apiKey] }))
})

const overallScore = computed(() => {
  if (!percentageRows.value.length) return 0
  const sum = percentageRows.value.reduce((acc, row) => acc + row.value, 0)
  return Math.round(sum / percentageRows.value.length)
})

const donutStyle = computed(() => {
  const ready = readiness.value?.ready
  const color = ready ? 'var(--color-success-fg)' : 'var(--color-danger-fg)'
  const pct = overallScore.value
  return {
    background: `conic-gradient(${color} ${pct}%, var(--color-surface-hover) ${pct}% 100%)`,
  }
})

function exceptionMessage(exception) {
  if (exception.code === 'quorum_not_met') {
    return t(`meetingsUnit.readiness.exceptions.${exception.code}`, {
      confirmed: exception.confirmed,
      required: exception.required,
    })
  }
  // Stage 85 — both of these count agenda items, and they mean different
  // things: `missing_files` is "this file has no documents at all",
  // `incomplete_required_documents` is "it has documents but not the ones its
  // own [D] Appendix 57 matrix states unconditionally".
  if (exception.code === 'missing_files' || exception.code === 'incomplete_required_documents') {
    return t(`meetingsUnit.readiness.exceptions.${exception.code}`, { count: exception.item_ids?.length ?? 0 })
  }
  if (exception.code === 'unconfirmed_members') {
    return t(`meetingsUnit.readiness.exceptions.${exception.code}`, { count: exception.user_ids?.length ?? 0 })
  }
  return t(`meetingsUnit.readiness.exceptions.${exception.code}`)
}

// --- Convene ---------------------------------------------------------------------

const showConvenePrompt = ref(false)
const conveneReason = ref('')
const conveneError = ref('')
const convening = ref(false)

function openConvenePrompt() {
  conveneReason.value = ''
  conveneError.value = ''
  showConvenePrompt.value = true
}

async function submitConvene() {
  convening.value = true
  conveneError.value = ''
  try {
    const { data } = await api.post(`/meetings/${meetingId.value}/convene`, {
      reason: conveneReason.value.trim() || undefined,
    })
    meeting.value = data.data
    showConvenePrompt.value = false
  } catch (requestError) {
    conveneError.value = requestError.response?.data?.errors?.reason?.[0]
      ?? requestError.response?.data?.errors?.status?.[0]
      ?? requestError.response?.data?.message
      ?? t('common.none')
  } finally {
    convening.value = false
  }
}

onMounted(loadMeetings)
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.readiness.title') }}</h1>
    <p class="subtitle">{{ t('meetingsUnit.readiness.subtitle') }}</p>

    <div class="card picker">
      <label>
        {{ t('meetingsUnit.readiness.chooseMeeting') }}
        <select v-model="meetingId">
          <option value="">{{ t('meetingsUnit.readiness.chooseMeetingPlaceholder') }}</option>
          <option v-for="m in meetings" :key="m.id" :value="m.id">{{ m.title }} — {{ dateTime(m.scheduled_at) }}</option>
        </select>
      </label>
    </div>

    <p v-if="!meetingId" class="state">{{ t('meetingsUnit.readiness.noMeetingSelected') }}</p>
    <p v-else-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="readiness && meeting">
      <div v-if="meeting.convened_at" class="card notice">
        {{ t('meetingsUnit.readiness.convene.alreadyConvened', { date: dateTime(meeting.convened_at) }) }}
        <span v-if="meeting.readiness_override_reason" class="muted">— {{ meeting.readiness_override_reason }}</span>
      </div>

      <div class="grid">
        <section class="card verdict-card">
          <div class="donut" :style="donutStyle">
            <div class="donut-hole">
              <strong>{{ overallScore }}%</strong>
            </div>
          </div>
          <span class="pill" :class="readiness.ready ? 'good' : 'bad'">
            {{ readiness.ready ? t('meetingsUnit.readiness.verdict.ready') : t('meetingsUnit.readiness.verdict.notReady') }}
          </span>
          <button
            v-can="'meeting_readiness.edit'"
            class="primary"
            type="button"
            :disabled="!!meeting.convened_at"
            @click="openConvenePrompt"
          >
            {{ t('meetingsUnit.readiness.convene.button') }}
          </button>
        </section>

        <section class="card panel">
          <h3>{{ t('meetingsUnit.readiness.quorum.title') }}</h3>
          <p class="quorum-line">
            {{ t('meetingsUnit.readiness.quorum.confirmed') }}: <strong>{{ readiness.quorum_confirmed }}</strong>
            / {{ t('meetingsUnit.readiness.quorum.required') }}:
            <!-- Stage 73 — a committee whose قرار التشكيل has not been
                 transcribed has no quorum, and the screen says so rather than
                 showing a figure the system made up. -->
            <strong>{{ readiness.quorum_required ?? t('meetingsUnit.readiness.quorum.notRecorded') }}</strong>
          </p>
          <p v-if="readiness.quorum_rule?.quorum_text" class="quorum-source">
            {{ t('meetingsUnit.readiness.quorum.perText') }}: {{ readiness.quorum_rule.quorum_text }}
          </p>
          <span v-if="readiness.quorum_required !== null" class="pill" :class="readiness.quorum_met ? 'good' : 'bad'">
            {{ readiness.quorum_met ? t('meetingsUnit.readiness.quorum.met') : t('meetingsUnit.readiness.quorum.notMet') }}
          </span>
          <span v-else class="pill bad">{{ t('meetingsUnit.readiness.quorum.notRecorded') }}</span>
        </section>

        <section class="card panel">
          <h3>{{ t('meetingsUnit.dashboard.funnel.title') }}</h3>
          <ul class="bars">
            <li v-for="row in percentageRows" :key="row.key">
              <span class="bar-label">{{ t(`meetingsUnit.readiness.percentages.${row.key}`) }}</span>
              <span class="bar-track">
                <span class="bar-fill" :style="{ inlineSize: `${row.value}%` }" />
              </span>
              <span class="bar-value">{{ row.value }}%</span>
            </li>
          </ul>
        </section>

        <section class="card panel">
          <h3>{{ t('meetingsUnit.readiness.exceptions.title') }}</h3>
          <p v-if="!readiness.exceptions.length" class="state good-text">{{ t('meetingsUnit.readiness.exceptions.none') }}</p>
          <ul v-else class="exceptions">
            <li v-for="(exception, index) in readiness.exceptions" :key="index">{{ exceptionMessage(exception) }}</li>
          </ul>
        </section>
      </div>
    </template>

    <div v-if="showConvenePrompt" class="overlay" @click.self="showConvenePrompt = false">
      <div class="card modal">
        <h3>{{ t('meetingsUnit.readiness.convene.title') }}</h3>
        <label>
          {{ t('meetingsUnit.readiness.convene.reasonLabel') }}
          <span class="hint">
            {{ readiness.ready
              ? t('meetingsUnit.readiness.convene.reasonOptionalHint')
              : t('meetingsUnit.readiness.convene.reasonRequiredHint') }}
          </span>
          <textarea v-model="conveneReason" rows="3"></textarea>
        </label>
        <p v-if="conveneError" class="alert">{{ conveneError }}</p>
        <div class="modal-actions">
          <button class="ghost" type="button" :disabled="convening" @click="showConvenePrompt = false">
            {{ t('meetingsUnit.readiness.convene.cancel') }}
          </button>
          <button class="primary" type="button" :disabled="convening" @click="submitConvene">
            {{ convening ? t('meetingsUnit.readiness.convene.processing') : t('meetingsUnit.readiness.convene.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 78rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0 0 1rem; color: var(--color-muted); font-size: .85rem; }

.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); max-inline-size: 24rem; }
select, textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.good-text { color: var(--color-success-fg); }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: .5rem 0 0; }
.notice { font-size: .85rem; color: var(--color-black-700); }
.muted { color: var(--color-muted); }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
.panel h3 { margin: 0 0 1rem; font-size: 1rem; color: var(--color-black-800); }

.verdict-card { display: flex; flex-direction: column; align-items: center; gap: .75rem; text-align: center; }
.donut { inline-size: 120px; block-size: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.donut-hole { inline-size: 84px; block-size: 84px; border-radius: 50%; background: var(--color-surface); display: flex; align-items: center; justify-content: center; }
.donut-hole strong { color: var(--color-brand-text); font-size: 1.2rem; }

.pill { display: inline-block; padding: .25rem .7rem; border-radius: 999px; font-size: .8rem; }
.pill.good { background: var(--color-success-bg); color: var(--color-success-fg); border: 1px solid var(--color-success-border); }
.pill.bad { background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); }

.quorum-line { margin: 0 0 .5rem; font-size: .9rem; color: var(--color-black-700); }
.quorum-source { margin: 0 0 .5rem; font-size: .78rem; color: var(--color-muted); }

.bars { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
.bars li { display: grid; grid-template-columns: minmax(90px, 40%) 1fr auto; align-items: center; gap: .6rem; }
.bar-label { font-size: .8rem; color: var(--color-black-700); }
.bar-track { block-size: 9px; background: var(--color-surface-hover); border-radius: 999px; overflow: hidden; }
.bar-fill { display: block; block-size: 100%; border-radius: 999px; background: var(--color-brand); }
.bar-value { font-size: .8rem; color: var(--color-muted); font-variant-numeric: tabular-nums; }

.exceptions { margin: 0; padding-inline-start: 1.2rem; display: grid; gap: .4rem; font-size: .85rem; color: var(--color-black-700); }

button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }

.overlay {
  position: fixed; inset: 0; background: var(--color-overlay);
  display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 50;
}
.modal { inline-size: min(28rem, 100%); }
.modal h3 { margin: 0 0 .75rem; color: var(--color-brand-text); font-size: 1rem; }
.modal label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
.modal .hint { font-size: .74rem; color: var(--color-muted); }
.modal textarea { resize: vertical; }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
</style>
