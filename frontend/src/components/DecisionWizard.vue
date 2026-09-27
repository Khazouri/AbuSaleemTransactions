<script setup>
/**
 * Decision wizard — the one way to act on a request from its page: read the
 * file, clear this stage's own checks, pick an action knowing where it sends
 * the file, then confirm it as a تأشيرة.
 *
 * Sub-project 1 built it on `available_transitions` / `blocked_transitions`.
 * Sub-project 2 adds the committee's own moves on a file (`committee_actions`):
 * asking for completion, handing it to the legal member, and the legal
 * member's opinion, whose card fills the Checks step and whose five verdicts
 * join the choices. The server decides what is offered; the wizard lays it out.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DocumentValidityPanel from './DocumentValidityPanel.vue'
import IntakeGatePanel from './IntakeGatePanel.vue'
import JurisdictionTestForm from './JurisdictionTestForm.vue'
import LegalReviewCard from './LegalReviewCard.vue'
import RequestStageRail from './RequestStageRail.vue'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import api, { firstError } from '../lib/api'

const props = defineProps({
  request: { type: Object, required: true },
})
const emit = defineEmits(['updated', 'close'])

const { t, te, locale } = useI18n()

// Mirrors the page's old destructive tint: these end or refuse the file.
const DESTRUCTIVE = ['cancel', 'reject', 'reject_review', 'reject_formally', 'reject_by_committee', 'declare_no_jurisdiction']
// The three requirements_check outcomes Art. 45's test gates (Stage 54).
const CLASSIFYING = ['approve', 'declare_no_jurisdiction', 'reject_formally']
// [D] Art. 21's five outcomes in the article's order; the two that permit
// agenda insertion say so on the option.
const VERDICTS = [
  { code: 'sound_ready', permits: true },
  { code: 'needs_document', permits: false },
  { code: 'needs_clarification', permits: false },
  { code: 'jurisdiction_note', permits: false },
  { code: 'present_with_note', permits: true },
]
// Where each committee move posts. `defer` / `return_to_study` are workflow
// rows and ride /transition like every other one.
const COMMITTEE_ENDPOINTS = {
  require_completion: (id) => `/committee-candidates/${id}/request-completion`,
  send_to_legal_review: (id) => `/requests/${id}/legal-reviews/request`,
}

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar) ?? ''
const actionLabel = (action) => t(`workflow.actions.${action}`)
const meaning = (action) => (te(`decisionWizard.meaning.${action}`) ? t(`decisionWizard.meaning.${action}`) : '')

const available = computed(() => props.request.available_transitions ?? [])
const blocked = computed(() => props.request.blocked_transitions ?? [])
const committeeActions = computed(() => props.request.committee_actions ?? [])
const offersLegalOpinion = computed(() => committeeActions.value.some((item) => item.action === 'record_legal_review'))
const offered = computed(() => new Set([...available.value, ...blocked.value].map((item) => item.action)))
const stage = computed(() => props.request.current_stage?.code)
const gates = computed(() => props.request.control_gates ?? {})

// Only the checks whose owner is the person deciding right now: keyed on the
// action they are being offered, so a reader who merely holds a grant is not
// walked through someone else's gate.
const checks = computed(() => {
  const list = []
  if (gates.value.document_validity?.can_record) list.push('document_validity')
  if (stage.value === 'receive_and_register' && offered.value.has('register') && gates.value.employment_file) list.push('employment_file')
  if (stage.value === 'requirements_check' && CLASSIFYING.some((action) => offered.value.has(action))) {
    if (gates.value.intake) list.push('intake')
    list.push('jurisdiction_test')
  }
  if (offersLegalOpinion.value) list.push('legal_card')
  return list
})

const steps = computed(() => ['review', ...(checks.value.length ? ['checks'] : []), 'choose', 'confirm'])
const step = ref('review')

// Every option the Choose step lists, whichever endpoint it posts to.
const choices = computed(() => [
  ...available.value.map((item) => ({
    key: `transition:${item.action}`,
    kind: 'transition',
    action: item.action,
    label: actionLabel(item.action),
    meaning: meaning(item.action),
    destination: item.to_stage
      ? t('decisionWizard.choose.destination', { stage: name(item.to_stage), status: name(item.to_status) })
      : '',
    requiresComment: item.requires_comment,
    isException: item.is_exception,
  })),
  ...committeeActions.value
    .filter((item) => item.action !== 'record_legal_review')
    .map((item) => ({
      key: `committee:${item.action}`,
      kind: 'committee',
      action: item.action,
      label: t(`decisionWizard.actions.${item.action}`),
      meaning: meaning(item.action),
      destination: '',
      requiresComment: item.requires_comment,
      isException: false,
    })),
  ...(offersLegalOpinion.value
    ? VERDICTS.map((verdict) => ({
      key: `verdict:${verdict.code}`,
      kind: 'verdict',
      action: verdict.code,
      label: t(`meetingsUnit.legalReview.verdicts.${verdict.code}`),
      meaning: t(verdict.permits ? 'meetingsUnit.legalReview.form.permitsAgenda' : 'meetingsUnit.legalReview.form.blocksAgenda'),
      destination: '',
      // Mirrors StoreRequestLegalReviewRequest: every verdict but sound_ready carries the note.
      requiresComment: verdict.code !== 'sound_ready',
      isException: false,
    }))
    : []),
])
const groups = computed(() => {
  const ordinary = choices.value.filter((item) => !item.isException && item.kind !== 'verdict')
  const verdicts = choices.value.filter((item) => item.kind === 'verdict')
  const exceptional = choices.value.filter((item) => item.isException)
  return [
    { key: 'ordinary', label: '', items: ordinary },
    { key: 'verdicts', label: ordinary.length ? t('decisionWizard.choose.verdicts') : '', items: verdicts },
    { key: 'other', label: ordinary.length || verdicts.length ? t('decisionWizard.choose.other') : '', items: exceptional },
  ].filter((group) => group.items.length)
})

const selected = ref('')
const comment = ref('')
const legalCard = ref({
  primary_legislation: '',
  article_reference: '',
  supplementary_decision: '',
  committee_mandate: '',
  approving_body: '',
  requires_central_approval: '',
  legal_deadline: '',
  prohibiting_conditions: '',
})
const submitting = ref(false)
const error = ref('')

const choice = computed(() => choices.value.find((item) => item.key === selected.value) ?? null)
const isDestructive = (item) => item?.kind === 'transition' && DESTRUCTIVE.includes(item.action)
const commentMissing = computed(() => Boolean(choice.value?.requiresComment) && !comment.value.trim())
// F6 — requestReview() (send_to_legal_review) never reads a body; showing a
// note field that is silently dropped would mislead the actor.
const showsNote = computed(() => !(choice.value?.kind === 'committee' && choice.value?.action === 'send_to_legal_review'))

// A check can clear a gate and turn a blocked action into an available one; a
// selection the refreshed payload no longer offers is dropped, not kept stale.
watch(choices, (list) => {
  if (selected.value && !list.some((item) => item.key === selected.value)) selected.value = ''
})

const recentTimeline = computed(() => (props.request.timeline ?? []).slice(-3).reverse())
const dateTime = (value) => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
const reference = computed(() => props.request.reference_number || props.request.intake_receipt_number || `#${props.request.id}`)

// DocumentValidityPanel answers with nothing, the others with the full detail
// resource; either way the page's copy of the request is replaced, so the
// Choose step reflects what the check just unlocked.
async function onCheckSaved(resource) {
  if (resource) {
    emit('updated', resource)
    return
  }
  const { data } = await api.get(`/requests/${props.request.id}`)
  emit('updated', data.data)
}

async function post(item, note) {
  const id = props.request.id
  if (item.kind === 'transition') {
    const { data } = await api.post(`/requests/${id}/transition`, { action: item.action, ...(note ? { comment: note } : {}) })
    return data.data
  }
  if (item.kind === 'committee') {
    // F6 — requestReview() (send_to_legal_review) ignores any body it is
    // sent; never send one, matching the hidden note field on Confirm below.
    let body = {}
    if (item.action !== 'send_to_legal_review' && note) body = { comment: note }
    await api.post(COMMITTEE_ENDPOINTS[item.action](id), body)
  } else {
    const card = Object.fromEntries(Object.entries(legalCard.value).filter(([, value]) => value !== ''))
    await api.post(`/requests/${id}/legal-reviews`, { ...card, verdict: item.action, ...(note ? { legal_note: note } : {}) })
  }
  // Those endpoints answer with the list resource, not the workspace's.
  const { data } = await api.get(`/requests/${id}`)
  return data.data
}

async function submit() {
  if (submitting.value || !choice.value || commentMissing.value) return
  submitting.value = true
  error.value = ''
  try {
    emit('updated', await post(choice.value, comment.value.trim()))
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <WizardShell
    v-model:step="step"
    :title="t('decisionWizard.title')"
    :steps="steps"
    :can-advance="step !== 'choose' || Boolean(choice)"
    :submit-label="t('decisionWizard.submit', { action: choice?.label ?? '' })"
    :submit-disabled="!choice || commentMissing"
    :submitting="submitting"
    :destructive="isDestructive(choice)"
    @submit="submit"
    @close="emit('close')"
  >
    <template #review>
      <p class="lede">{{ t('decisionWizard.review.lede') }}</p>
      <dl class="facts">
        <div><dt>{{ t('decisionWizard.review.type') }}</dt><dd>{{ name(request.request_type) || '—' }}</dd></div>
        <div><dt>{{ t('requestDetail.subjectUser') }}</dt><dd>{{ request.subject?.name || request.created_by?.name || '—' }}</dd></div>
        <div v-if="request.subject && request.created_by && request.subject.id !== request.created_by.id">
          <dt>{{ t('requestDetail.filedBy') }}</dt><dd>{{ request.created_by.name }}</dd>
        </div>
        <div><dt>{{ t('requestDetail.currentStage') }}</dt><dd>{{ name(request.current_stage) || '—' }}</dd></div>
      </dl>
      <RequestStageRail
        variant="compact"
        :stage-progress="request.stage_progress"
        :stage-timeliness="request.stage_timeliness"
        :current-stage-name="name(request.current_stage)"
      />
      <p v-if="request.description" class="description">{{ request.description }}</p>

      <h4>{{ t('attachments.title') }}</h4>
      <p v-if="!request.attachments?.length" class="state">{{ t('requestDetail.noAttachments') }}</p>
      <ul v-else class="plain-list">
        <li v-for="attachment in request.attachments" :key="attachment.id">
          <span class="ltr file-name">{{ attachment.original_name }}</span>
          <small>{{ attachment.label || '' }}</small>
        </li>
      </ul>

      <h4>{{ t('decisionWizard.review.recent') }}</h4>
      <p v-if="!recentTimeline.length" class="state">{{ t('requestDetail.noTimeline') }}</p>
      <ul v-else class="plain-list">
        <li v-for="entry in recentTimeline" :key="entry.id">
          <strong>{{ actionLabel(entry.action) }}</strong>
          <small>{{ entry.acted_by?.name || '—' }}{{ locale === 'ar' ? '، ' : ', ' }}{{ dateTime(entry.acted_at) }}</small>
          <p v-if="entry.comment" class="quote">{{ entry.comment }}</p>
        </li>
      </ul>
      <p class="hint">{{ t('decisionWizard.review.more') }}</p>
    </template>

    <template #checks>
      <p class="lede">{{ t('decisionWizard.checks.lede') }}</p>
      <div v-for="check in checks" :key="check" class="check">
        <h4>{{ t(`decisionWizard.checks.${check}`) }}</h4>
        <DocumentValidityPanel
          v-if="check === 'document_validity'"
          :request-id="request.id"
          :rows="gates.document_validity.rows"
          :refusal="gates.document_validity.refusal"
          @updated="onCheckSaved()"
        />
        <IntakeGatePanel
          v-else-if="check === 'employment_file'"
          :request-id="request.id"
          :required-documents="gates.employment_file.required_documents"
          :record="gates.employment_file.record"
          :refusal="gates.employment_file.refusal"
          :recorded-by="gates.employment_file.prepared_by"
          :recorded-at="gates.employment_file.prepared_at"
          endpoint="employment-file"
          attestation-field="assembled"
          grant="notes_attachments.add"
          copy="employmentFile"
          @updated="onCheckSaved"
        />
        <IntakeGatePanel
          v-else-if="check === 'intake'"
          :request-id="request.id"
          :required-documents="gates.intake.required_documents"
          :record="gates.intake.record"
          :refusal="gates.intake.refusal"
          :recorded-by="gates.intake.recorded_by"
          :recorded-at="gates.intake.recorded_at"
          @updated="onCheckSaved"
        />
        <LegalReviewCard v-else-if="check === 'legal_card'" v-model="legalCard" :request-id="request.id" />
        <JurisdictionTestForm v-else :request="request" @updated="onCheckSaved" />
      </div>
    </template>

    <template #choose>
      <p class="lede">{{ t('decisionWizard.choose.lede') }}</p>
      <fieldset class="options">
        <legend class="sr-only">{{ t('decisionWizard.steps.choose') }}</legend>
        <template v-for="group in groups" :key="group.key">
          <p v-if="group.label" class="group-label">{{ group.label }}</p>
          <label
            v-for="item in group.items"
            :key="item.key"
            class="option"
            :class="{ destructive: isDestructive(item), chosen: selected === item.key }"
          >
            <input v-model="selected" type="radio" name="decision" :value="item.key" />
            <span class="option-text">
              <strong>{{ item.label }}</strong>
              <span v-if="item.meaning">{{ item.meaning }}</span>
              <small v-if="item.destination">{{ item.destination }}</small>
            </span>
          </label>
        </template>
        <template v-if="blocked.length">
          <p class="group-label">{{ t('decisionWizard.choose.blocked') }}</p>
          <div v-for="item in blocked" :key="item.action" class="option blocked">
            <input type="radio" name="decision" disabled :aria-label="actionLabel(item.action)" />
            <span class="option-text">
              <strong>{{ actionLabel(item.action) }}</strong>
              <span>{{ item.reason }}</span>
              <button v-if="steps.includes('checks')" class="link" type="button" @click="step = 'checks'">
                {{ t('decisionWizard.choose.toChecks') }}
              </button>
            </span>
          </div>
        </template>
      </fieldset>
      <p v-if="!choices.length" class="state">{{ t('decisionWizard.choose.none') }}</p>
    </template>

    <template #confirm>
      <WizardSlip kind="tashira" :reference="reference" :destructive="isDestructive(choice)">
        <p class="slip-action">{{ choice?.label }}</p>
        <p v-if="choice?.destination" class="slip-destination">{{ choice.destination }}</p>
        <label v-if="showsNote" class="slip-note">
          {{ choice?.requiresComment ? t('decisionWizard.slip.reasonRequired') : t('decisionWizard.slip.noteOptional') }}
          <textarea v-model="comment" rows="3" maxlength="5000" :required="choice?.requiresComment" :disabled="submitting" />
        </label>
      </WizardSlip>
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>
  </WizardShell>
</template>

<style scoped>
.description, .quote { margin: 0; color: var(--color-black-700); white-space: pre-wrap; max-inline-size: 70ch; }
.quote { padding-inline-start: var(--space-3); border-inline-start: 2px solid var(--color-border-hover); font-size: var(--text-sm); }
.file-name { overflow-wrap: anywhere; color: var(--color-black-700); font-size: var(--text-sm); }
.ltr { direction: ltr; justify-self: start; }
</style>
