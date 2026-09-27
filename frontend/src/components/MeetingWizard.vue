<script setup>
/**
 * Decision wizard — sub-project 2: the meeting's own acts — answering the
 * proposed date, adopting the agenda, convening, preparing / approving /
 * returning / signing the minutes, and closing. Review the sitting and its
 * five seats, check what the act rests on (readiness before convening, the
 * reviewer's own control before approving), choose, then confirm as a line in
 * the session register.
 *
 * `duties` is GET meetings/{m}/duties, fetched by MeetingDutiesCard; its
 * "blocked" reasons are the endpoints' own refusals (MeetingDuties).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SeatStrip from './SeatStrip.vue'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import api, { firstError } from '../lib/api'
import { MINUTES_REVIEWER_CHECK } from '../lib/controlGates'
import { readinessExceptionMessage } from '../lib/readiness'

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
  convene: (id, note) => api.post(`/meetings/${id}/convene`, note ? { reason: note } : {}),
  generate_minutes: (id) => api.post(`/meetings/${id}/minutes/generate`),
  approve_minutes: (id, note, checked) => api.post(`/meetings/${id}/minutes/review`, {
    decision: 'approve',
    quality_checks: { [MINUTES_REVIEWER_CHECK]: checked },
  }),
  return_minutes: (id, note) => api.post(`/meetings/${id}/minutes/review`, { decision: 'changes_requested', comment: note }),
  sign_minutes: (id) => api.post(`/meetings/${id}/minutes/sign`),
  close: (id) => api.put(`/meetings/${id}`, { status: 'completed' }),
}
// The acts whose slip carries a written reason or note.
const TAKES_NOTE = ['convene', 'return_minutes']

const meeting = ref(null)
const readiness = ref(null)
const loadError = ref('')
const available = computed(() => props.duties.available ?? [])
const blocked = computed(() => props.duties.blocked ?? [])
const offered = computed(() => new Set([...available.value, ...blocked.value].map((duty) => duty.action)))

onMounted(async () => {
  try {
    const [{ data: meetingData }, readinessResponse] = await Promise.all([
      api.get(`/meetings/${props.meetingId}`),
      offered.value.has('convene') ? api.get(`/meetings/${props.meetingId}/readiness`) : Promise.resolve(null),
    ])
    meeting.value = meetingData.data
    readiness.value = readinessResponse?.data?.data ?? null
  } catch (requestError) {
    loadError.value = firstError(requestError, t('common.none'))
  }
})

const checks = computed(() => [
  ...(offered.value.has('convene') ? ['readiness'] : []),
  ...(available.value.some((duty) => duty.action === 'approve_minutes') ? ['minutes_quality'] : []),
])
const steps = computed(() => ['review', ...(checks.value.length ? ['checks'] : []), 'choose', 'confirm'])
const step = ref('review')

const label = (action) => t(`decisionWizard.actions.${action === 'generate_minutes' && meeting.value?.minutes_status ? 'regenerate_minutes' : action}`)
const meaning = (action) => (te(`decisionWizard.meaning.${action}`) ? t(`decisionWizard.meaning.${action}`) : '')
const choices = computed(() => available.value.map((duty) => ({
  key: duty.action,
  action: duty.action,
  label: label(duty.action),
  meaning: meaning(duty.action),
  requiresComment: duty.requires_comment,
})))
const selected = ref('')
const choice = computed(() => choices.value.find((option) => option.key === selected.value) ?? null)
watch(choices, (list) => {
  if (selected.value && !list.some((option) => option.key === selected.value)) selected.value = ''
})

const note = ref('')
const reviewerChecked = ref(false)
const submitting = ref(false)
const error = ref('')
const submitDisabled = computed(() => !choice.value
  || (choice.value.requiresComment && !note.value.trim())
  || (choice.value.action === 'approve_minutes' && !reviewerChecked.value))

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
    await REQUESTS[choice.value.action](props.meetingId, note.value.trim(), reviewerChecked.value)
    emit('updated')
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('common.none'))
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
        <div>
          <dt>{{ t('decisionWizard.review.minutes') }}</dt>
          <dd>{{ meeting.minutes_status ? t(`meetingsUnit.minutes.status.${meeting.minutes_status}`) : t('decisionWizard.review.noMinutes') }}</dd>
        </div>
      </dl>
      <SeatStrip :seats="duties.seats" />
    </template>

    <template #checks>
      <p class="lede">{{ t('decisionWizard.checks.meetingLede') }}</p>
      <div v-for="check in checks" :key="check" class="check">
        <h4>{{ t(`decisionWizard.checks.${check}`) }}</h4>
        <template v-if="check === 'readiness'">
          <p v-if="!readiness" class="state">{{ t('common.loading') }}</p>
          <template v-else>
            <p class="lede">
              {{ readiness.ready ? t('meetingsUnit.readiness.verdict.ready') : t('meetingsUnit.readiness.verdict.notReady') }}
            </p>
            <p v-if="!readiness.exceptions?.length" class="state">{{ t('meetingsUnit.readiness.exceptions.none') }}</p>
            <ul v-else class="plain-list">
              <li v-for="(exception, index) in readiness.exceptions" :key="index">{{ readinessExceptionMessage(t, exception) }}</li>
            </ul>
            <p v-if="!readiness.ready" class="hint">{{ t('meetingsUnit.readiness.convene.reasonRequiredHint') }}</p>
          </template>
        </template>
        <template v-else>
          <p class="hint">{{ t('controlGates.minutesQuality.note') }}</p>
          <label class="quality-check">
            <input v-model="reviewerChecked" type="checkbox" />
            <span>{{ t('controlGates.minutesQuality.reviewerCheck') }}</span>
          </label>
        </template>
      </div>
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
        <label v-if="choice && TAKES_NOTE.includes(choice.action)" class="slip-note">
          {{ choice.requiresComment ? t('decisionWizard.slip.reasonRequired') : t('decisionWizard.slip.noteOptional') }}
          <textarea v-model="note" rows="3" maxlength="5000" :required="choice.requiresComment" :disabled="submitting" />
        </label>
      </WizardSlip>
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>
  </WizardShell>
</template>

<style scoped>
.quality-check { display: flex; align-items: flex-start; gap: var(--space-2); color: var(--color-black-700); }
.quality-check input { margin-block-start: 0.3rem; accent-color: var(--color-brand); }
</style>
