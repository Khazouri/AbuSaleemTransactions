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
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { DECISION_OUTCOMES, SIGNATURE_OUTCOMES, VOTE_OPTIONS } from '../lib/decisionOutcomes'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'
import SignaturePad from './SignaturePad.vue'

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
const decisionError = ref('')
const decidingBusy = ref(false)
const signatureReady = ref(false)
const selectedTemplateId = ref('')
const templateDraftBusy = ref(false)
const templateDraftError = ref('')
const conflictReason = ref('')
const conflictBusy = ref(false)
const conflictError = ref('')
let signaturePad = null

const myConflictDeclaration = computed(() => (props.item.conflict_declarations ?? [])
  .find((declaration) => declaration.user.id === auth.user?.id) ?? null)

const isNonVotingRapporteur = computed(() => props.meeting?.rapporteur?.id === auth.user?.id
  && !props.meeting?.committee?.rapporteur_votes)

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

function setSignaturePad(instance) { signaturePad = instance }

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
    decisionComment.value = data.data.body
  } catch (requestError) {
    templateDraftError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    templateDraftBusy.value = false
  }
}

function tally(item) {
  const counts = Object.fromEntries(VOTE_OPTIONS.map((outcome) => [outcome, 0]))
  for (const vote of item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return counts
}

// Same plurality rule DecisionController::record applies server-side — used
// here only to decide whether to show the signature pad before submitting.
// Stage 41 — counted from DECISION_OUTCOMES only, same as the server's
// $tally: an abstain-heavy vote must never read as "leading" here either.
function predictedOutcome(item) {
  const counts = tally(item)
  const max = Math.max(...DECISION_OUTCOMES.map((outcome) => counts[outcome]))
  if (max === 0) return null
  const leaders = DECISION_OUTCOMES
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

async function recordDecision() {
  decisionError.value = ''
  decidingBusy.value = true
  try {
    const form = new FormData()
    const comment = decisionComment.value.trim()
    if (comment) form.append('comment', comment)
    const referral = referralAuthority.value.trim()
    if (referral) form.append('referral_authority', referral)
    if (selectedTemplateId.value) form.append('template_id', selectedTemplateId.value)
    if (SIGNATURE_OUTCOMES.includes(predictedOutcome(props.item))) {
      const signature = await signaturePad?.toFile()
      if (signature) form.append('signature', signature)
    }
    await api.post(`/meetings/${props.meetingId}/agenda/${props.item.id}/decision`, form)
    decisionComment.value = ''
    referralAuthority.value = ''
    selectedTemplateId.value = ''
    signatureReady.value = false
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

      <p v-if="myConflictDeclaration" class="alert">{{ t('decisions.conflict.declared') }}</p>
      <p v-else-if="isNonVotingRapporteur" class="alert">{{ t('decisions.conflict.rapporteurNoVote') }}</p>

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
      <p v-if="conflictError" class="alert">{{ conflictError }}</p>

      <div class="tally">
        <span v-for="outcome in VOTE_OPTIONS" :key="outcome">
          {{ t(`decisions.tally.${outcome}`) }}: {{ tally(item)[outcome] }}
        </span>
      </div>

      <div v-if="!myConflictDeclaration && !isNonVotingRapporteur" v-can="'decisions.add'" class="vote-actions">
        <button
          v-for="option in VOTE_OPTIONS"
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
      <p v-if="votingError" class="alert">{{ votingError }}</p>

      <div v-can="'decisions.approve'" class="record-decision">
        <div v-if="templates.length" class="template-picker">
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
        <p v-if="templateDraftError" class="alert">{{ templateDraftError }}</p>
        <textarea
          v-model="decisionComment"
          :placeholder="t('decisions.commentPlaceholder')"
          :aria-label="t('decisions.commentPlaceholder')"
          rows="2"
        />
        <input
          v-model="referralAuthority"
          type="text"
          :placeholder="t('decisions.referralAuthorityPlaceholder')"
          :aria-label="t('decisions.referralAuthorityPlaceholder')"
        >
        <SignaturePad
          v-if="SIGNATURE_OUTCOMES.includes(predictedOutcome(item))"
          :ref="setSignaturePad"
          :disabled="decidingBusy"
          @change="signatureReady = $event"
        />
        <div class="actions">
          <button
            class="primary"
            type="button"
            :disabled="decidingBusy || !predictedOutcome(item) || (SIGNATURE_OUTCOMES.includes(predictedOutcome(item)) && !signatureReady)"
            @click="recordDecision"
          >
            {{ decidingBusy ? t('decisions.recording') : t('decisions.record') }}
          </button>
        </div>
        <p v-if="decisionError" class="alert">{{ decisionError }}</p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.decision-block { display: grid; gap: .5rem; padding: .65rem .75rem; background: var(--color-surface-hover); border: 1px solid var(--color-border); border-radius: 8px; margin-bottom: .75rem; }
.decision-result { margin: 0; color: var(--color-brand-text); font-size: .82rem; font-weight: 600; }
.decision-template { margin: 0; color: var(--color-muted); font-size: .78rem; }
.decision-comment { margin: 0; color: var(--color-muted); font-size: .8rem; }
.conflict-list { font-size: .78rem; color: var(--color-muted); }
.conflict-list ul { margin: .2rem 0 0; padding-inline-start: 1.1rem; }
.conflict-declare { display: flex; gap: .4rem; }
.conflict-declare input {
  flex: 1; min-width: 0; padding: .4rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px;
  background: var(--color-surface); color: var(--color-foreground); font: inherit; box-sizing: border-box;
}
.tally { display: flex; gap: .85rem; flex-wrap: wrap; color: var(--color-muted); font-size: .78rem; }
.vote-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.vote-actions button.active { background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }
.record-decision { display: grid; gap: .5rem; margin-top: .25rem; padding-top: .5rem; border-top: 1px dashed var(--color-border-hover); }
.template-picker { display: flex; gap: .4rem; }
.template-picker select { flex: 1; min-width: 0; }
.record-decision textarea,
.record-decision input,
.record-decision select {
  width: 100%; padding: .45rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px;
  background: var(--color-surface); color: var(--color-foreground); resize: vertical; font: inherit; box-sizing: border-box;
}
.record-decision .actions { margin: 0; display: flex; justify-content: flex-end; }
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .55; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
.alert { padding: .5rem .65rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .82rem; margin: 0; }
</style>
