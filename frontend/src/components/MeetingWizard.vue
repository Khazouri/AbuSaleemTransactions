<script setup>
/**
 * Decision wizard — sub-project 2: the meeting's own acts — answering the
 * proposed date, adopting the agenda and entering the paper sitting's results
 * (2026-10-18). Review the sitting and its five seats, choose, then confirm as
 * a line in the session register.
 *
 * `duties` is GET meetings/{m}/duties, fetched by MeetingDutiesCard; its
 * "blocked" reasons are the endpoints' own refusals (MeetingDuties).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import MeetingResultsForm from './MeetingResultsForm.vue'
import SeatStrip from './SeatStrip.vue'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import api, { firstError } from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  duties: { type: Object, required: true },
})
const emit = defineEmits(['updated', 'close'])
const { t, te, locale } = useI18n()

// What each act posts; the endpoints are unchanged.
const REQUESTS = {
  respond_accept: (id) => api.post(`/meetings/${id}/respond`, { response: 'accept' }),
  respond_decline: (id) => api.post(`/meetings/${id}/respond`, { response: 'decline' }),
  adopt_agenda: (id) => api.post(`/meetings/${id}/agenda/adopt`),
  // 2026-10-18 — the paper sitting's results and its محضر, in one submit.
  record_results: (id, results) => api.post(`/meetings/${id}/results`, results),
}

const meeting = ref(null)
const loadError = ref('')
const available = computed(() => props.duties.available ?? [])
const blocked = computed(() => props.duties.blocked ?? [])

onMounted(async () => {
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}`)
    meeting.value = data.data
    resultRows.value = Object.fromEntries((meeting.value.agenda_items ?? []).map((item) => [item.id, {
      recommendation: '',
      // An appeal row names no جهة الاعتماد, so it never carries one.
      approving_authority: item.item_type === 'appeal' ? null : item.request?.suggested_authority ?? 'dean',
      note: '',
    }]))
  } catch (requestError) {
    loadError.value = firstError(requestError, t('requestDetail.loadFailed'))
  }
})

const steps = ['review', 'choose', 'confirm']
const step = ref('review')

const label = (action) => t(`decisionWizard.actions.${action}`)
const meaning = (action) => (te(`decisionWizard.meaning.${action}`) ? t(`decisionWizard.meaning.${action}`) : '')
const choices = computed(() => available.value.map((duty) => ({
  key: duty.action,
  action: duty.action,
  label: label(duty.action),
  meaning: meaning(duty.action),
})))
const selected = ref('')
const choice = computed(() => choices.value.find((option) => option.key === selected.value) ?? null)
watch(choices, (list) => {
  if (selected.value && !list.some((option) => option.key === selected.value)) selected.value = ''
})

// 2026-10-18 — MeetingResultsForm's state: agenda item id => its row.
const resultRows = ref({})
const minutesFile = ref(null)
const resultsComplete = computed(() => Boolean(minutesFile.value)
  && Object.values(resultRows.value).length > 0
  && (meeting.value?.agenda_items ?? []).every((item) => {
    const row = resultRows.value[item.id]
    // An appeal always states its reason and names no authority.
    if (item.item_type === 'appeal') return row.recommendation && row.note.trim()
    return row.recommendation
      && (row.recommendation === 'defer' || row.approving_authority)
      && (row.recommendation === 'approve' || row.note.trim())
  }))

function resultsPayload() {
  const form = new FormData()
  form.append('minutes_file', minutesFile.value)
  for (const [id, row] of Object.entries(resultRows.value)) {
    form.append(`items[${id}][recommendation]`, row.recommendation)
    if (row.recommendation !== 'defer' && row.approving_authority) form.append(`items[${id}][approving_authority]`, row.approving_authority)
    if (row.note.trim()) form.append(`items[${id}][note]`, row.note.trim())
  }
  return form
}
const submitting = ref(false)
const error = ref('')
const submitDisabled = computed(() => !choice.value
  || (choice.value.action === 'record_results' && !resultsComplete.value))

// pending_confirmation → meetings.statusPendingConfirmation
const statusLabel = (status) => t(`meetings.status${status.replace(/(^|_)(\w)/g, (_, __, c) => c.toUpperCase())}`)
const dateTime = (value) => (value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : t('common.none'))

async function submit() {
  if (submitting.value || submitDisabled.value) return
  submitting.value = true
  error.value = ''
  try {
    await REQUESTS[choice.value.action](
      props.meetingId,
      choice.value.action === 'record_results' ? resultsPayload() : null,
    )
    emit('updated')
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
    // F3(c) — a 422 can mean the meeting changed elsewhere; let the card
    // refresh so a newly-open or newly-blocked act shows up without a reload.
    if (requestError.response?.status === 422) emit('updated')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <WizardShell
    v-model:step="step"
    :title="t('decisionWizard.meetingTitle')"
    :steps="steps"
    :can-advance="step !== 'choose' || Boolean(choice)"
    :submit-label="t('decisionWizard.submit', { action: choice?.label ?? '' })"
    :submit-disabled="submitDisabled"
    :submitting="submitting"
    @submit="submit"
    @close="emit('close')"
  >
    <template #review>
      <p class="lede">{{ t('decisionWizard.review.meetingLede') }}</p>
      <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
      <dl v-if="meeting" class="facts">
        <div><dt>{{ t('decisionWizard.review.meeting') }}</dt><dd>{{ meeting.title }}</dd></div>
        <div><dt>{{ t('decisionWizard.review.date') }}</dt><dd>{{ dateTime(meeting.scheduled_at) }}</dd></div>
        <div><dt>{{ t('decisionWizard.review.status') }}</dt><dd>{{ statusLabel(meeting.status) }}</dd></div>
        <div>
          <dt>{{ t('decisionWizard.review.agenda') }}</dt>
          <dd>
            {{ t('decisionWizard.review.items', { count: meeting.agenda_items?.length ?? 0 }) }}{{ locale === 'ar' ? '،' : ',' }}
            {{ meeting.agenda_adopted_at ? t('decisionWizard.review.agendaAdopted') : t('decisionWizard.review.agendaNotAdopted') }}
          </dd>
        </div>
      </dl>
      <SeatStrip :seats="duties.seats" />
    </template>

    <template #choose>
      <p class="lede">{{ t('decisionWizard.choose.meetingLede') }}</p>
      <fieldset class="options">
        <legend class="sr-only">{{ t('decisionWizard.steps.choose') }}</legend>
        <label v-for="option in choices" :key="option.key" class="option" :class="{ chosen: selected === option.key }">
          <input v-model="selected" type="radio" name="meeting-act" :value="option.key" />
          <span class="option-text">
            <strong>{{ option.label }}</strong>
            <span v-if="option.meaning">{{ option.meaning }}</span>
          </span>
        </label>
        <template v-if="blocked.length">
          <p class="group-label">{{ t('decisionWizard.choose.blocked') }}</p>
          <div v-for="entry in blocked" :key="entry.action" class="option blocked">
            <input type="radio" name="meeting-act" disabled :aria-label="label(entry.action)" />
            <span class="option-text">
              <strong>{{ label(entry.action) }}</strong>
              <span>{{ entry.reason }}</span>
            </span>
          </div>
        </template>
      </fieldset>
    </template>

    <template #confirm>
      <WizardSlip kind="ledger" :reference="meeting?.meeting_number ?? ''">
        <p class="slip-action">{{ choice ? t(`decisionWizard.ledger.${choice.action}`) : '' }}</p>
        <p class="slip-destination">{{ meeting?.title }}</p>
      </WizardSlip>
      <MeetingResultsForm
        v-if="choice?.action === 'record_results' && meeting"
        v-model:rows="resultRows"
        v-model:minutes="minutesFile"
        :items="meeting.agenda_items ?? []"
        :disabled="submitting"
      />
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>
  </WizardShell>
</template>

