<script setup>
/**
 * Stage 35 — the vote/tally/record-decision block for one agenda item.
 *
 * Factored out of MeetingDetailView.vue and MeetingLiveView.vue, which
 * carried an identical copy of this markup since Stage 34 duplicated it
 * ("reuse the existing vote/decision endpoints" was that stage's scope, not
 * a shared component) — see that stage's AGENT_NOTES entry, which flagged
 * exactly this as the next thing to factor out rather than copy a third
 * time. Both endpoints (`.../votes`, `.../decision`) and their semantics are
 * unchanged from Stage 21/25; Stage 35 added three more outcomes and an
 * optional template to draft the comment from. Stage 42 made that draft
 * real: `useTemplate()` now fetches the template merged with this agenda
 * item's own data from `.../decision-draft` instead of copying the
 * template's static body verbatim.
 *
 * Stage 48 — a conflict-of-interest declaration and a non-voting rapporteur
 * are both surfaced here too, ahead of the vote block: the server (this
 * panel's two endpoints, `.../votes` and `.../conflict-of-interest`) is the
 * real enforcement (DecisionEligibility), this is proactive UX so the vote
 * buttons don't just fail silently for someone who already knows why.
 *
 * Stage 63 — an `appeal` item votes/records through the same two endpoints,
 * but with its own, completely independent 5-outcome vocabulary
 * (APPEAL_DECISION_OUTCOMES) instead of the 7 employee_request ones.
 * Template drafting isn't wired for appeals (Stage 42's DecisionDraftComposer
 * has no appeal equivalent), so the template picker is hidden for that item
 * type.
 *
 * Signatures have been removed from the system — recording a decision whose
 * predicted outcome is `approve` now asks for a plain confirmation instead
 * of a drawn/uploaded signature.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  APPEAL_DECISION_OUTCOMES,
  APPEAL_VOTE_OPTIONS,
  DECISION_OUTCOMES,
  VOTE_OPTIONS,
} from '../lib/decisionOutcomes'
import {
  DECISION_INSTRUMENTS,
  DEFERRAL_FIELDS,
  REFUSAL_REASON_CODES,
  isSubstantiveOutcome,
  needsReferralAuthority,
  needsRefusalReason,
} from '../lib/decisionStructure'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  item: { type: Object, required: true },
  templates: { type: Array, default: () => [] },
  meeting: { type: Object, default: null },
})
const emit = defineEmits(['refresh'])

const { t, locale } = useI18n()
const auth = useAuthStore()

const votingError = ref('')
const votingBusy = ref(false)
const decisionComment = ref('')
const referralAuthority = ref('')
// Stage 74 — Appendix 27's four parts, Art. 90's instrument, Appendix 28's
// refusal reason and Art. 34's five deferral fields. One flat object so the
// form, the reset and the submit all read the same list of keys.
const structure = ref(emptyStructure())
const decisionError = ref('')
const decidingBusy = ref(false)
// Signatures have been removed from the system — recording an `approve`
// outcome now pauses for a plain confirmation instead.
const pendingApprove = ref(false)
const selectedTemplateId = ref('')
const templateDraftBusy = ref(false)
const templateDraftError = ref('')
const conflictReason = ref('')
const conflictBusy = ref(false)
const conflictError = ref('')

const myConflictDeclaration = computed(() => (props.item.conflict_declarations ?? [])
  .find((declaration) => declaration.user.id === auth.user?.id) ?? null)

const isNonVotingRapporteur = computed(() => props.meeting?.rapporteur?.id === auth.user?.id
  && !props.meeting?.committee?.rapporteur_votes)

function emptyStructure() {
  return {
    instrument: '',
    decision_subject: '',
    decision_facts: '',
    decision_basis: '',
    decision_operative: '',
    refusal_reason_code: '',
    ...Object.fromEntries(DEFERRAL_FIELDS.map((field) => [field, ''])),
  }
}

// Stage 74 — which parts of the structure this item's vote is heading
// towards needing. The server (DecisionStructureRules) is the enforcement;
// showing only the relevant fields is what keeps the form from asking for a
// سند on a تأجيل, which is exactly the distinction [D] draws.
const pendingOutcome = computed(() => predictedOutcome(props.item))
const needsFactsAndBasis = computed(() => isSubstantiveOutcome(pendingOutcome.value))
const needsRefusal = computed(() => needsRefusalReason(pendingOutcome.value))
const needsDeferral = computed(() => pendingOutcome.value === 'defer')
// 2026-09-20 — Art. 26 (د) / [E] 13D. Same principle as the three above: the
// field was shown on every decision, including an `approve` that refers to
// nobody, and was optional on the two outcomes that must name a destination.
const needsReferral = computed(() => needsReferralAuthority(pendingOutcome.value))

/**
 * Stage 74 — Appendix 22's own answer, recorded by the legal officer before
 * the sitting (Stage 68). A pre-selection only: Art. 14 (ب) leaves the
 * decision to the committee, so the recorder can change it, and `study_only`
 * (دراسة فقط) is not one of Art. 90's three instruments so it pre-fills
 * nothing.
 */
const expectedInstrument = computed(() => {
  const expected = props.item.request?.expected_instrument
  return DECISION_INSTRUMENTS.includes(expected) ? expected : ''
})

watch(expectedInstrument, (expected) => {
  if (expected && !structure.value.instrument) structure.value.instrument = expected
}, { immediate: true })

// Stage 63 — which of the two, entirely independent, outcome vocabularies
// this item's votes/decision are drawn from.
const isAppeal = computed(() => props.item.item_type === 'appeal')
const voteOptions = computed(() => (isAppeal.value ? APPEAL_VOTE_OPTIONS : VOTE_OPTIONS))
const outcomeOptions = computed(() => (isAppeal.value ? APPEAL_DECISION_OUTCOMES : DECISION_OUTCOMES))

async function declareConflict() {
  conflictError.value = ''
  conflictBusy.value = true
  try {
    await api.post(`/meetings/${props.meetingId}/agenda/${props.item.id}/conflict-of-interest`, {
      reason: conflictReason.value.trim() || undefined,
    })
    conflictReason.value = ''
    emit('refresh')
  } catch (requestError) {
    conflictError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    conflictBusy.value = false
  }
}

function templateLabel(template) {
  return locale.value === 'ar' ? (template.name_ar || template.name_en) : (template.name_en || template.name_ar)
}

/** Stage 42 — fetches the chosen template's draft merged with this agenda
 *  item's own request/employee/date data, into the comment box — still a
 *  starting point the user can edit before recording, nothing beyond this
 *  is enforced server-side. */
async function useTemplate() {
  if (!selectedTemplateId.value) return
  templateDraftError.value = ''
  templateDraftBusy.value = true
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/agenda/${props.item.id}/decision-draft`, {
      params: { template_id: selectedTemplateId.value, locale: locale.value },
    })
    // Stage 74 — Appendix 59's formulas are single flowing "قررت اللجنة…"
    // statements, i.e. Appendix 27's منطوق; dropping one into the notes box
    // (where Stage 42 put it, before there was anywhere better) would leave
    // the operative clause the محضر actually needs empty.
    structure.value.decision_operative = data.data.body
  } catch (requestError) {
    templateDraftError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    templateDraftBusy.value = false
  }
}

function tally(item) {
  const options = item.item_type === 'appeal' ? APPEAL_VOTE_OPTIONS : VOTE_OPTIONS
  const counts = Object.fromEntries(options.map((outcome) => [outcome, 0]))
  for (const vote of item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return counts
}

// Same plurality rule DecisionController::record/recordAppealDecision
// applies server-side — used here only to decide whether to pause for a
// confirmation before submitting an `approve` outcome. Stage 41 — counted
// from the outcome vocabulary only (never abstain), same as the server's
// $tally: an abstain-heavy vote must never read as "leading" here either.
function predictedOutcome(item) {
  const outcomes = item.item_type === 'appeal' ? APPEAL_DECISION_OUTCOMES : DECISION_OUTCOMES
  const counts = tally(item)
  const max = Math.max(...outcomes.map((outcome) => counts[outcome]))
  if (max === 0) return null
  const leaders = outcomes
    .map((outcome) => [outcome, counts[outcome]])
    .filter(([, count]) => count === max)
  return leaders.length === 1 ? leaders[0][0] : null
}

function myVote(item) {
  return (item.votes ?? []).find((vote) => vote.user.id === auth.user?.id)?.vote ?? null
}

async function castVote(voteValue) {
  votingError.value = ''
  votingBusy.value = true
  try {
    await api.post(`/meetings/${props.meetingId}/agenda/${props.item.id}/votes`, { vote: voteValue })
    emit('refresh')
  } catch (requestError) {
    votingError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    votingBusy.value = false
  }
}

// Recording an `approve` outcome pauses for a plain confirmation first
// (signatures have been removed from the system); every other outcome
// records immediately, as before.
function submitDecision() {
  if (predictedOutcome(props.item) === 'approve' && !pendingApprove.value) {
    pendingApprove.value = true
    return
  }
  recordDecision()
}

function closeApproveConfirm() {
  if (decidingBusy.value) return
  pendingApprove.value = false
}

async function recordDecision() {
  decisionError.value = ''
  decidingBusy.value = true
  try {
    const payload = {}
    const comment = decisionComment.value.trim()
    if (comment) payload.comment = comment
    const referral = referralAuthority.value.trim()
    if (referral) payload.referral_authority = referral
    // Stage 74 — blanks are simply omitted; the server decides which of them
    // this outcome actually required and says so in Arabic if one is missing.
    for (const [field, value] of Object.entries(structure.value)) {
      const trimmed = String(value ?? '').trim()
      if (trimmed) payload[field] = trimmed
    }
    if (selectedTemplateId.value) payload.template_id = selectedTemplateId.value
    await api.post(`/meetings/${props.meetingId}/agenda/${props.item.id}/decision`, payload)
    decisionComment.value = ''
    referralAuthority.value = ''
    structure.value = emptyStructure()
    selectedTemplateId.value = ''
    pendingApprove.value = false
    emit('refresh')
  } catch (requestError) {
    decisionError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    decidingBusy.value = false
  }
}
</script>

<template>
  <div class="decision-block">
    <template v-if="item.decision">
      <p class="decision-result">
        {{ t(`decisions.outcome.${item.decision.outcome}`) }}
        — {{ t('decisions.decidedBy') }} {{ item.decision.decided_by?.name }}
        ({{ new Intl.DateTimeFormat(locale === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(item.decision.decided_at)) }})
      </p>
      <p v-if="item.decision.instrument" class="decision-instrument">
        {{ t('decisions.instrument.label') }}: {{ t(`decisions.instrument.${item.decision.instrument}`) }}
      </p>
      <dl v-if="item.decision.decision_subject || item.decision.decision_operative" class="decision-parts">
        <template v-if="item.decision.decision_subject">
          <dt>{{ t('decisions.parts.subject') }}</dt><dd>{{ item.decision.decision_subject }}</dd>
        </template>
        <template v-if="item.decision.decision_facts">
          <dt>{{ t('decisions.parts.facts') }}</dt><dd>{{ item.decision.decision_facts }}</dd>
        </template>
        <template v-if="item.decision.decision_basis">
          <dt>{{ t('decisions.parts.basis') }}</dt><dd>{{ item.decision.decision_basis }}</dd>
        </template>
        <template v-if="item.decision.decision_operative">
          <dt>{{ t('decisions.parts.operative') }}</dt><dd>{{ item.decision.decision_operative }}</dd>
        </template>
        <template v-if="item.decision.refusal_reason_code">
          <dt>{{ t('decisions.refusal.label') }}</dt>
          <dd>{{ t(`decisions.refusal.reasons.${item.decision.refusal_reason_code}`) }}</dd>
        </template>
        <template v-for="field in DEFERRAL_FIELDS" :key="field">
          <template v-if="item.decision[field]">
            <dt>{{ t(`decisions.deferral.${field}`) }}</dt><dd>{{ item.decision[field] }}</dd>
          </template>
        </template>
      </dl>
      <p v-if="item.decision.template" class="decision-template">{{ t('decisions.template.usedLabel') }}: {{ templateLabel(item.decision.template) }}</p>
      <p v-if="item.decision.comment" class="decision-comment">{{ item.decision.comment }}</p>
      <p v-if="item.decision.referral_authority" class="decision-comment">{{ t('decisions.referralAuthorityLabel') }}: {{ item.decision.referral_authority }}</p>
    </template>
    <template v-else>
      <div v-if="(item.conflict_declarations ?? []).length" class="conflict-list">
        <strong>{{ t('decisions.conflict.listTitle') }}</strong>
        <ul>
          <li v-for="declaration in item.conflict_declarations" :key="declaration.id">
            {{ declaration.user.name }}<template v-if="declaration.reason"> — {{ declaration.reason }}</template>
          </li>
        </ul>
      </div>

      <p v-if="myConflictDeclaration" class="alert warning">{{ t('decisions.conflict.declared') }}</p>
      <p v-else-if="isNonVotingRapporteur" class="alert warning">{{ t('decisions.conflict.rapporteurNoVote') }}</p>

      <div v-if="!myConflictDeclaration" v-can="'decisions.add'" class="conflict-declare">
        <input
          v-model="conflictReason"
          type="text"
          :placeholder="t('decisions.conflict.reasonPlaceholder')"
          :aria-label="t('decisions.conflict.reasonPlaceholder')"
        >
        <button class="ghost" type="button" :disabled="conflictBusy" @click="declareConflict">
          {{ conflictBusy ? t('decisions.conflict.declaring') : t('decisions.conflict.declare') }}
        </button>
      </div>
      <p v-if="conflictError" class="alert warning">{{ conflictError }}</p>

      <div class="tally">
        <span v-for="outcome in voteOptions" :key="outcome">
          {{ t(`decisions.tally.${outcome}`) }}: {{ tally(item)[outcome] }}
        </span>
      </div>

      <div v-if="!myConflictDeclaration && !isNonVotingRapporteur" v-can="'decisions.add'" class="vote-actions">
        <button
          v-for="option in voteOptions"
          :key="option"
          class="ghost"
          :class="{ active: myVote(item) === option }"
          type="button"
          :disabled="votingBusy"
          @click="castVote(option)"
        >
          {{ t(`decisions.vote.${option}`) }}
        </button>
      </div>
      <p v-if="votingError" class="alert warning">{{ votingError }}</p>

      <div v-can="'decisions.approve'" class="record-decision">
        <div v-if="templates.length && !isAppeal" class="template-picker">
          <select v-model="selectedTemplateId" :aria-label="t('decisions.template.choose')">
            <option value="">{{ t('decisions.template.choose') }}</option>
            <option v-for="template in templates" :key="template.id" :value="template.id">
              {{ templateLabel(template) }}
            </option>
          </select>
          <button class="ghost" type="button" :disabled="!selectedTemplateId || templateDraftBusy" @click="useTemplate">
            {{ templateDraftBusy ? t('decisions.template.drafting') : t('decisions.template.use') }}
          </button>
        </div>
        <p v-if="templateDraftError" class="alert warning">{{ templateDraftError }}</p>

        <!-- Stage 74 - Art. 90: which of the three the committee is issuing,
             pre-selected from the legal card but always the recorder's own
             choice. -->
        <label class="field">
          <span>{{ t('decisions.instrument.label') }}</span>
          <select v-model="structure.instrument" :aria-label="t('decisions.instrument.label')">
            <option value="">{{ t('decisions.instrument.choose') }}</option>
            <option v-for="option in DECISION_INSTRUMENTS" :key="option" :value="option">
              {{ t(`decisions.instrument.${option}`) }}
            </option>
          </select>
          <small v-if="expectedInstrument" class="hint">
            {{ t('decisions.instrument.expected', { value: t(`decisions.instrument.${expectedInstrument}`) }) }}
          </small>
        </label>

        <!-- Stage 74 - Appendix 27's four parts. The subject and operative
             clause are asked of every outcome (Art. 89); facts and basis only
             of the ones that actually dispose of the matter. -->
        <label class="field">
          <span>{{ t('decisions.parts.subject') }}</span>
          <textarea v-model="structure.decision_subject" :aria-label="t('decisions.parts.subject')" rows="2" />
        </label>
        <template v-if="needsFactsAndBasis">
          <label class="field">
            <span>{{ t('decisions.parts.facts') }}</span>
            <textarea v-model="structure.decision_facts" :aria-label="t('decisions.parts.facts')" rows="2" />
          </label>
          <label class="field">
            <span>{{ t('decisions.parts.basis') }}</span>
            <textarea v-model="structure.decision_basis" :aria-label="t('decisions.parts.basis')" rows="2" />
          </label>
        </template>
        <label class="field">
          <span>{{ t('decisions.parts.operative') }}</span>
          <textarea v-model="structure.decision_operative" :aria-label="t('decisions.parts.operative')" rows="3" />
          <small class="hint">{{ t('decisions.parts.operativeHint') }}</small>
        </label>

        <!-- Stage 74 - Appendix 28's professional reason, on the outcomes
             Art. 91 names. -->
        <label v-if="needsRefusal" class="field">
          <span>{{ t('decisions.refusal.label') }}</span>
          <select v-model="structure.refusal_reason_code" :aria-label="t('decisions.refusal.label')">
            <option value="">{{ t('decisions.refusal.choose') }}</option>
            <option v-for="code in REFUSAL_REASON_CODES" :key="code" :value="code">
              {{ t(`decisions.refusal.reasons.${code}`) }}
            </option>
          </select>
          <small class="hint">{{ t('decisions.refusal.hint') }}</small>
        </label>

        <!-- Stage 74 - Art. 34's five fields; the last one is conditional in
             the source itself ("if any"), so only the first four are gates. -->
        <fieldset v-if="needsDeferral" class="deferral">
          <legend>{{ t('decisions.deferral.legend') }}</legend>
          <p class="hint">{{ t('decisions.deferral.hint') }}</p>
          <label v-for="field in DEFERRAL_FIELDS" :key="field" class="field">
            <span>{{ t(`decisions.deferral.${field}`) }}</span>
            <input v-model="structure[field]" type="text" :aria-label="t(`decisions.deferral.${field}`)">
          </label>
        </fieldset>

        <label class="field">
          <span>{{ t('decisions.notesLabel') }}</span>
          <textarea
            v-model="decisionComment"
            :placeholder="t('decisions.commentPlaceholder')"
            :aria-label="t('decisions.commentPlaceholder')"
            rows="2"
          />
        </label>
        <input
          v-if="needsReferral"
          v-model="referralAuthority"
          type="text"
          required
          :placeholder="t('decisions.referralAuthorityPlaceholder')"
          :aria-label="t('decisions.referralAuthorityPlaceholder')"
        >
        <div class="actions">
          <button
            class="primary"
            type="button"
            :disabled="decidingBusy || !predictedOutcome(item) || (isAppeal && !decisionComment.trim())"
            @click="submitDecision"
          >
            {{ decidingBusy ? t('decisions.recording') : t('decisions.record') }}
          </button>
        </div>
        <p v-if="decisionError" class="alert warning">{{ decisionError }}</p>
      </div>
    </template>

    <Teleport to="body">
      <div v-if="pendingApprove" class="modal-backdrop" @click.self="closeApproveConfirm">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-decision-approve-title">
          <h3 id="confirm-decision-approve-title">{{ t('decisions.confirmApprove.title') }}</h3>
          <p>{{ t('decisions.confirmApprove.body') }}</p>
          <p v-if="decisionError" class="alert warning">{{ decisionError }}</p>
          <div class="modal-actions">
            <button class="ghost" type="button" :disabled="decidingBusy" @click="closeApproveConfirm">
              {{ t('common.cancel') }}
            </button>
            <button class="primary" type="button" :disabled="decidingBusy" @click="recordDecision">
              {{ decidingBusy ? t('decisions.recording') : t('decisions.confirmApprove.confirm') }}
            </button>
          </div>
        </section>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Everything button/alert/modal-shaped comes from the global primitives; the
   markup below carries only what genuinely belongs to this panel's own
   layout: the decision result block, the tally, and the structured-decision
   form's fields. */
.decision-block {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3);
  background: var(--color-surface-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  margin-bottom: var(--space-3);
}
.decision-result {
  margin: 0;
  color: var(--color-brand-text);
  font-size: var(--text-sm);
  font-weight: 600;
}
.decision-template,
.decision-instrument {
  margin: 0;
  color: var(--color-muted);
  font-size: var(--text-xs);
}
.decision-comment {
  margin: 0;
  color: var(--color-muted);
  font-size: var(--text-sm);
}
.conflict-list {
  font-size: var(--text-xs);
  color: var(--color-muted);
}
.conflict-list ul {
  margin: 0.2rem 0 0;
  padding-inline-start: 1.1rem;
}
.conflict-declare {
  display: flex;
  gap: var(--space-2);
}
.conflict-declare input {
  flex: 1;
  min-width: 0;
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
  box-sizing: border-box;
}
.tally {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  color: var(--color-muted);
  font-size: var(--text-xs);
}
.vote-actions {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}
.vote-actions button.active {
  background: var(--color-brand);
  color: var(--color-on-brand);
  border-color: var(--color-brand);
}
.record-decision {
  display: grid;
  gap: var(--space-2);
  margin-top: 0.25rem;
  padding-top: var(--space-2);
  border-top: 1px dashed var(--color-border-hover);
}
.template-picker {
  display: flex;
  gap: var(--space-2);
}
.template-picker select {
  flex: 1;
  min-width: 0;
}
.record-decision textarea,
.record-decision input,
.record-decision select {
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  resize: vertical;
  font: inherit;
  box-sizing: border-box;
}
.record-decision .actions {
  margin: 0;
  display: flex;
  justify-content: flex-end;
}
.field {
  display: grid;
  gap: 0.25rem;
}
.field > span {
  color: var(--color-muted);
  font-size: var(--text-xs);
  font-weight: 600;
}
.deferral {
  display: grid;
  gap: 0.45rem;
  margin: 0;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-warning-border);
  background: var(--color-warning-bg);
  border-radius: var(--radius-lg);
}
.deferral legend {
  padding: 0 0.3rem;
  color: var(--color-warning-fg);
  font-size: var(--text-xs);
  font-weight: 600;
}
.deferral input {
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
  box-sizing: border-box;
}
.decision-parts {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.15rem var(--space-2);
  margin: 0;
  font-size: var(--text-xs);
}
.decision-parts dt {
  color: var(--color-muted);
  font-weight: 600;
}
.decision-parts dd {
  margin: 0;
  color: var(--color-foreground);
}
</style>
