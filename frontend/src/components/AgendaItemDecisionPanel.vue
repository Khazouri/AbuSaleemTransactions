<script setup>
/**
 * Stage 35 — the vote/tally/decision block for one agenda item, now read-only:
 * the recorded decision, declared conflicts and the running tally. Voting and
 * recording moved into AgendaItemWizard (decision wizard, sub-project 2); this
 * panel's button opens it, so each has exactly one way in.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { APPEAL_VOTE_OPTIONS, VOTE_OPTIONS } from '../lib/decisionOutcomes'
import { DEFERRAL_FIELDS } from '../lib/decisionStructure'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  item: { type: Object, required: true },
  meeting: { type: Object, default: null },
})
const emit = defineEmits(['decide'])

const { t, locale } = useI18n()
const auth = useAuthStore()

const myConflictDeclaration = computed(() => (props.item.conflict_declarations ?? [])
  .find((declaration) => declaration.user.id === auth.user?.id) ?? null)
const isNonVotingRapporteur = computed(() => props.meeting?.rapporteur?.id === auth.user?.id
  && !props.meeting?.committee?.rapporteur_votes)
const voteOptions = computed(() => (props.item.item_type === 'appeal' ? APPEAL_VOTE_OPTIONS : VOTE_OPTIONS))
const canAct = computed(() => auth.can('decisions', 'add') || auth.can('decisions', 'approve'))

const tally = computed(() => {
  const counts = Object.fromEntries(voteOptions.value.map((outcome) => [outcome, 0]))
  for (const vote of props.item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return counts
})

function templateLabel(template) {
  return locale.value === 'ar' ? (template.name_ar || template.name_en) : (template.name_en || template.name_ar)
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
            {{ declaration.user.name }}<template v-if="declaration.reason">: {{ declaration.reason }}</template>
          </li>
        </ul>
      </div>

      <p v-if="myConflictDeclaration" class="alert warning">{{ t('decisions.conflict.declared') }}</p>
      <p v-else-if="isNonVotingRapporteur" class="alert warning">{{ t('decisions.conflict.rapporteurNoVote') }}</p>

      <div class="tally">
        <span v-for="outcome in voteOptions" :key="outcome">
          {{ t(`decisions.tally.${outcome}`) }}: {{ tally[outcome] }}
        </span>
      </div>

      <!-- Decision wizard — sub-project 2. -->
      <button v-if="canAct" class="primary" type="button" @click="emit('decide')">{{ t('decisionWizard.act') }}</button>
    </template>
  </div>
</template>

<style scoped>
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
.tally {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  color: var(--color-muted);
  font-size: var(--text-xs);
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
.decision-block > button {
  justify-self: start;
}
</style>
