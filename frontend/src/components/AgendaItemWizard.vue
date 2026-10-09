<script setup>
/**
 * Decision wizard — sub-project 2: a member's vote and the chair's recorded
 * result on one agenda item. Review the matter and who has voted, clear this
 * actor's own checks (declaring a conflict; Art. 85's study sequence for the
 * chair), choose, then confirm on the paper the act produces — a ballot, or
 * the draft decision.
 *
 * What is offered comes from GET …/agenda/{item}/duties, whose "blocked"
 * reasons are the endpoints' own refusals (DecisionEligibility for the vote,
 * DecisionTally for the result). It is fetched again after every check.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DecisionRecordForm from './DecisionRecordForm.vue'
import SeatStrip from './SeatStrip.vue'
import StudySequencePanel from './StudySequencePanel.vue'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import { APPEAL_VOTE_OPTIONS, VOTE_OPTIONS } from '../lib/decisionOutcomes'
import { DEFERRAL_FIELDS } from '../lib/decisionStructure'
import api, { firstError } from '../lib/api'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  meeting: { type: Object, required: true },
  item: { type: Object, required: true },
  templates: { type: Array, default: () => [] },
})
const emit = defineEmits(['updated', 'close'])
const { t, locale } = useI18n()
const auth = useAuthStore()

const duties = ref(null)
const loadError = ref('')
async function loadDuties() {
  loadError.value = ''
  try {
    const { data } = await api.get(`/meetings/${props.meeting.id}/agenda/${props.item.id}/duties`)
    duties.value = data.data
  } catch (requestError) {
    loadError.value = firstError(requestError, t('requestDetail.loadFailed'))
  }
}
onMounted(loadDuties)

// F2 — the live runner polls, so the tally can move under the chair while
// they review this window; a vote-set signature (not the poll tick) is what
// should refetch duties, so the predicted outcome and DecisionRecordForm's
// conditional fields never go stale behind what is actually recorded.
const voteSignature = computed(() => (props.item.votes ?? [])
  .map((vote) => `${vote.user_id}:${vote.vote}`)
  .sort()
  .join(','))
watch(voteSignature, loadDuties)

const available = computed(() => duties.value?.available ?? [])
const blocked = computed(() => duties.value?.blocked ?? [])
const offered = computed(() => new Set([...available.value, ...blocked.value].map((duty) => duty.action)))
const voteDuty = computed(() => available.value.find((duty) => duty.action === 'vote') ?? null)
const recordDuty = computed(() => available.value.find((duty) => duty.action === 'record_decision') ?? null)
const isAppeal = computed(() => props.item.item_type === 'appeal')
const voteOptions = computed(() => (isAppeal.value ? APPEAL_VOTE_OPTIONS : VOTE_OPTIONS))
const myDeclaration = computed(() => (props.item.conflict_declarations ?? []).find((entry) => entry.user?.id === auth.user?.id) ?? null)
const canRunItem = computed(() => auth.can('meeting_live', 'edit'))

const checks = computed(() => [
  ...(offered.value.has('vote') && !myDeclaration.value ? ['conflict'] : []),
  ...(offered.value.has('record_decision') && canRunItem.value ? ['study_sequence'] : []),
])
const steps = computed(() => ['review', ...(checks.value.length ? ['checks'] : []), 'choose', 'confirm'])
const step = ref('review')

const choices = computed(() => [
  ...(voteDuty.value
    ? voteOptions.value.map((option) => ({
      key: `vote:${option}`,
      kind: 'vote',
      value: option,
      label: t(`decisions.vote.${option}`),
      meaning: voteDuty.value.my_vote === option ? t('decisionWizard.ballot.current') : '',
    }))
    : []),
  ...(recordDuty.value
    ? [{
      key: 'record',
      kind: 'record',
      label: t('decisionWizard.actions.record_decision'),
      meaning: t('decisionWizard.decision.predicted', { outcome: t(`decisions.outcome.${recordDuty.value.outcome}`) }),
    }]
    : []),
])
const selected = ref('')
const choice = computed(() => choices.value.find((option) => option.key === selected.value) ?? null)
watch(choices, (list) => {
  if (selected.value && !list.some((option) => option.key === selected.value)) selected.value = ''
})

const voteNote = ref('')
const draft = ref(emptyDraft())
const submitting = ref(false)
const error = ref('')
const commentMissing = computed(() => choice.value?.kind === 'record'
  && Boolean(recordDuty.value?.requires_comment) && !draft.value.comment.trim())

function emptyDraft() {
  return {
    template_id: '',
    instrument: '',
    decision_subject: '',
    decision_facts: '',
    decision_basis: '',
    decision_operative: '',
    refusal_reason_code: '',
    ...Object.fromEntries(DEFERRAL_FIELDS.map((field) => [field, ''])),
    comment: '',
    referral_authority: '',
  }
}

// A check can open or close an option, so both the duties and the host's copy
// of the item are refreshed.
async function afterCheck() {
  emit('updated')
  await loadDuties()
}

// Stage 48 — declaring a conflict IS the recusal (DecisionEligibility::isRecused).
const conflictReason = ref('')
const conflictBusy = ref(false)
const conflictError = ref('')
async function declareConflict() {
  conflictError.value = ''
  conflictBusy.value = true
  try {
    await api.post(`/meetings/${props.meeting.id}/agenda/${props.item.id}/conflict-of-interest`, {
      reason: conflictReason.value.trim() || undefined,
    })
    conflictReason.value = ''
    await afterCheck()
  } catch (requestError) {
    conflictError.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    conflictBusy.value = false
  }
}

const reference = computed(() => props.item.request?.reference_number ?? '')
const itemTitle = computed(() => props.item.request?.title ?? props.item.appeal?.original_request?.title ?? props.item.subject ?? '')
const tallySummary = computed(() => {
  const counts = {}
  for (const vote of props.item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return Object.entries(counts)
    .map(([vote, count]) => `${t(`decisions.tally.${vote}`)} ${count}`)
    .join(locale.value === 'ar' ? '، ' : ', ')
})
// The draft decision's clauses — only the parts actually written, in Appendix 27's order.
const clauses = computed(() => ['decision_subject', 'decision_facts', 'decision_basis', 'decision_operative']
  .filter((field) => draft.value[field]?.trim())
  .map((field) => ({ field, label: t(`decisions.parts.${field.replace('decision_', '')}`), text: draft.value[field].trim() })))

async function submit() {
  if (submitting.value || !choice.value || commentMissing.value) return
  submitting.value = true
  error.value = ''
  const base = `/meetings/${props.meeting.id}/agenda/${props.item.id}`
  try {
    if (choice.value.kind === 'vote') {
      const note = voteNote.value.trim()
      await api.post(`${base}/votes`, { vote: choice.value.value, ...(note ? { comment: note } : {}) })
    } else {
      // F2 — the tally can move while the chair sits on this slip; refuse to
      // post a decision keyed to an outcome that is no longer the live one
      // rather than let a stale predicted outcome record the wrong result.
      const expectedOutcome = recordDuty.value?.outcome
      await loadDuties()
      if (recordDuty.value?.outcome !== expectedOutcome) {
        error.value = t('decisionWizard.decision.outcomeChanged')
        return
      }
      // Blanks are omitted; the server says in Arabic which part this outcome still needs.
      const payload = Object.fromEntries(Object.entries(draft.value)
        .map(([field, value]) => [field, String(value ?? '').trim()])
        .filter(([, value]) => value))
      await api.post(`${base}/decision`, payload)
    }
    emit('updated')
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
    // F3(c) — a 422 can mean the meeting/item changed elsewhere; let the
    // host (and MeetingDutiesCard) refresh so a newly-open act shows up.
    if (requestError.response?.status === 422) emit('updated')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <WizardShell
    v-model:step="step"
    :title="t('decisionWizard.itemTitle')"
    :steps="steps"
    :can-advance="step !== 'choose' || Boolean(choice)"
    :submit-label="choice?.kind === 'vote' ? t('decisionWizard.ballot.cast') : t('decisions.record')"
    :submit-disabled="!choice || commentMissing"
    :submitting="submitting"
    @submit="submit"
    @close="emit('close')"
  >
    <template #review>
      <p class="lede">{{ t('decisionWizard.review.itemLede') }}</p>
      <dl class="facts">
        <div><dt>{{ t('decisionWizard.review.item') }}</dt><dd>{{ itemTitle || '—' }}</dd></div>
        <div v-if="reference"><dt>{{ t('decisionWizard.review.reference') }}</dt><dd class="ltr">{{ reference }}</dd></div>
        <div><dt>{{ t('decisionWizard.review.meeting') }}</dt><dd>{{ meeting.title }}</dd></div>
        <div><dt>{{ t('decisionWizard.review.votes') }}</dt><dd>{{ tallySummary || t('decisionWizard.review.noVotes') }}</dd></div>
      </dl>
      <SeatStrip v-if="duties" :seats="duties.seats" />
      <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
    </template>

    <template #checks>
      <p class="lede">{{ t('decisionWizard.checks.lede') }}</p>
      <div v-for="check in checks" :key="check" class="check">
        <h4>{{ t(`decisionWizard.checks.${check}`) }}</h4>
        <template v-if="check === 'conflict'">
          <p class="hint">{{ t('decisionWizard.checks.conflictLede') }}</p>
          <div class="conflict-declare">
            <input
              v-model="conflictReason"
              type="text"
              :placeholder="t('decisions.conflict.reasonPlaceholder')"
              :aria-label="t('decisions.conflict.reasonPlaceholder')"
            />
            <button class="ghost" type="button" :disabled="conflictBusy" @click="declareConflict">
              {{ conflictBusy ? t('decisions.conflict.declaring') : t('decisions.conflict.declare') }}
            </button>
          </div>
          <p v-if="conflictError" class="alert" role="alert">{{ conflictError }}</p>
        </template>
        <StudySequencePanel
          v-else
          :meeting-id="meeting.id"
          :item-id="item.id"
          :can-edit="canRunItem"
          :show-title="false"
          @changed="afterCheck"
        />
      </div>
    </template>

    <template #choose>
      <p class="lede">{{ t('decisionWizard.choose.itemLede') }}</p>
      <fieldset class="options">
        <legend class="sr-only">{{ t('decisionWizard.steps.choose') }}</legend>
        <label v-for="option in choices" :key="option.key" class="option" :class="{ chosen: selected === option.key }">
          <input v-model="selected" type="radio" name="item-decision" :value="option.key" />
          <span class="option-text">
            <strong>{{ option.label }}</strong>
            <span v-if="option.meaning">{{ option.meaning }}</span>
          </span>
        </label>
        <template v-if="blocked.length">
          <p class="group-label">{{ t('decisionWizard.choose.blocked') }}</p>
          <div v-for="entry in blocked" :key="entry.action" class="option blocked">
            <input type="radio" name="item-decision" disabled :aria-label="t(`decisionWizard.actions.${entry.action}`)" />
            <span class="option-text">
              <strong>{{ t(`decisionWizard.actions.${entry.action}`) }}</strong>
              <span>{{ entry.reason }}</span>
              <button v-if="steps.includes('checks')" class="link" type="button" @click="step = 'checks'">
                {{ t('decisionWizard.choose.toChecks') }}
              </button>
            </span>
          </div>
        </template>
      </fieldset>
      <p v-if="duties && !choices.length && !blocked.length" class="state">{{ t('decisionWizard.choose.nothing') }}</p>
    </template>

    <template #confirm>
      <WizardSlip v-if="choice?.kind === 'vote'" kind="ballot" :reference="reference">
        <p class="slip-destination">{{ itemTitle }}</p>
        <ul class="ballot">
          <li v-for="option in voteOptions" :key="option" :class="{ chosen: option === choice.value }">
            <span class="box" aria-hidden="true">
              <svg v-if="option === choice.value" viewBox="0 0 24 24"><path d="M4 12.5l5 5L20 6.5" /></svg>
            </span>
            <span>
              {{ t(`decisions.vote.${option}`) }}
              <span v-if="option === choice.value" class="sr-only">({{ t('decisionWizard.ballot.yourChoice') }})</span>
            </span>
          </li>
        </ul>
        <label class="slip-note">
          {{ t('decisionWizard.slip.noteOptional') }}
          <textarea v-model="voteNote" rows="2" maxlength="2000" :disabled="submitting" />
        </label>
      </WizardSlip>

      <template v-else-if="choice">
        <h4>{{ t('decisionWizard.decision.form') }}</h4>
        <DecisionRecordForm
          v-model="draft"
          :meeting-id="meeting.id"
          :item="item"
          :templates="templates"
          :outcome="recordDuty?.outcome"
        />
        <WizardSlip kind="decision" :reference="reference">
          <p class="slip-action">{{ t(`decisions.outcome.${recordDuty?.outcome}`) }}</p>
          <ol v-if="clauses.length" class="clauses">
            <li v-for="clause in clauses" :key="clause.field" :class="{ operative: clause.field === 'decision_operative' }">
              <span>{{ clause.label }}</span>
              <p>{{ clause.text }}</p>
            </li>
          </ol>
          <p v-if="tallySummary" class="slip-destination">{{ t('decisionWizard.decision.tally', { summary: tallySummary }) }}</p>
        </WizardSlip>
      </template>
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>
  </WizardShell>
</template>

<style scoped>
.ltr { direction: ltr; justify-self: start; }
.conflict-declare { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.conflict-declare input { flex: 1; min-inline-size: min(14rem, 100%); }

/* The ballot: every option, the chosen one marked — a ballot shows what you
   did not pick too. */
.ballot { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(6.5rem, 100%), 1fr)); gap: var(--space-3); padding: 0; margin: 0; list-style: none; }
.ballot li { display: grid; justify-items: center; gap: 0.35rem; text-align: center; color: var(--color-muted); font-size: var(--text-sm); }
.ballot li.chosen { color: var(--color-brand-text); font-weight: 700; }
.box { display: grid; place-items: center; inline-size: 2.75rem; block-size: 2.75rem; border: 2px solid var(--color-border-hover); border-radius: var(--radius-sm); }
.ballot li.chosen .box { border-color: currentColor; }
.box svg { inline-size: 1.9rem; block-size: 1.9rem; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 26; stroke-dashoffset: 0; animation: draw 320ms ease-out; }
@keyframes draw { from { stroke-dashoffset: 26; } }
@media (prefers-reduced-motion: reduce) { .box svg { animation: none; } }

/* The draft decision: Appendix 27's parts as numbered clauses, the منطوق largest. */
.clauses { display: grid; gap: var(--space-2); margin: 0; padding-inline-start: 1.4rem; }
.clauses span { color: var(--color-muted); font-size: var(--text-xs); }
.clauses p { margin: 0; color: var(--color-black-700); white-space: pre-wrap; }
.clauses li.operative p { color: var(--color-brand-text); font-size: var(--text-xl); font-weight: 700; }
</style>
