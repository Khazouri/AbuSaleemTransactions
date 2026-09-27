<script setup>
/**
 * Stage 74 — the structured decision a committee records: Art. 90's
 * instrument, Appendix 27's four parts, Appendix 28's refusal reason, Art. 34's
 * deferral fields, the notes, and Art. 26 (د)'s referral authority. Moved out
 * of AgendaItemDecisionPanel into the agenda-item wizard's Confirm step
 * (decision wizard, sub-project 2). Which parts are asked follows the outcome
 * the tally produces; DecisionStructureRules is the enforcement.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  DECISION_INSTRUMENTS,
  DEFERRAL_FIELDS,
  REFUSAL_REASON_CODES,
  isSubstantiveOutcome,
  needsReferralAuthority,
  needsRefusalReason,
} from '../lib/decisionStructure'
import api, { firstError } from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  item: { type: Object, required: true },
  templates: { type: Array, default: () => [] },
  outcome: { type: String, default: null },
})
const draft = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()

const isAppeal = computed(() => props.item.item_type === 'appeal')
const needsFactsAndBasis = computed(() => isSubstantiveOutcome(props.outcome))
const needsRefusal = computed(() => needsRefusalReason(props.outcome))
const needsDeferral = computed(() => props.outcome === 'defer')
const needsReferral = computed(() => needsReferralAuthority(props.outcome))

// Appendix 22's answer from the legal card pre-selects Art. 90's instrument;
// `study_only` is not one of the three, so it pre-fills nothing.
const expectedInstrument = computed(() => {
  const expected = props.item.request?.expected_instrument
  return DECISION_INSTRUMENTS.includes(expected) ? expected : ''
})
watch(expectedInstrument, (expected) => {
  if (expected && !draft.value.instrument) draft.value.instrument = expected
}, { immediate: true })

const templateBusy = ref(false)
const templateError = ref('')
const templateLabel = (template) => (locale.value === 'ar' ? template.name_ar || template.name_en : template.name_en || template.name_ar)

/** Stage 42 — Appendix 59's formula merged with this item's data, into the منطوق. */
async function useTemplate() {
  if (!draft.value.template_id) return
  templateError.value = ''
  templateBusy.value = true
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/agenda/${props.item.id}/decision-draft`, {
      params: { template_id: draft.value.template_id, locale: locale.value },
    })
    draft.value.decision_operative = data.data.body
  } catch (requestError) {
    templateError.value = firstError(requestError, t('common.none'))
  } finally {
    templateBusy.value = false
  }
}
</script>

<template>
  <div class="record-form">
    <div v-if="templates.length && !isAppeal" class="template-picker">
      <select v-model="draft.template_id" :aria-label="t('decisions.template.choose')">
        <option value="">{{ t('decisions.template.choose') }}</option>
        <option v-for="template in templates" :key="template.id" :value="template.id">{{ templateLabel(template) }}</option>
      </select>
      <button class="ghost" type="button" :disabled="!draft.template_id || templateBusy" @click="useTemplate">
        {{ templateBusy ? t('decisions.template.drafting') : t('decisions.template.use') }}
      </button>
    </div>
    <p v-if="templateError" class="alert warning">{{ templateError }}</p>

    <label class="field">
      <span>{{ t('decisions.instrument.label') }}</span>
      <select v-model="draft.instrument">
        <option value="">{{ t('decisions.instrument.choose') }}</option>
        <option v-for="option in DECISION_INSTRUMENTS" :key="option" :value="option">{{ t(`decisions.instrument.${option}`) }}</option>
      </select>
      <small v-if="expectedInstrument" class="hint">
        {{ t('decisions.instrument.expected', { value: t(`decisions.instrument.${expectedInstrument}`) }) }}
      </small>
    </label>

    <label class="field">
      <span>{{ t('decisions.parts.subject') }}</span>
      <textarea v-model="draft.decision_subject" rows="2" />
    </label>
    <template v-if="needsFactsAndBasis">
      <label class="field">
        <span>{{ t('decisions.parts.facts') }}</span>
        <textarea v-model="draft.decision_facts" rows="2" />
      </label>
      <label class="field">
        <span>{{ t('decisions.parts.basis') }}</span>
        <textarea v-model="draft.decision_basis" rows="2" />
      </label>
    </template>
    <label class="field">
      <span>{{ t('decisions.parts.operative') }}</span>
      <textarea v-model="draft.decision_operative" rows="3" />
      <small class="hint">{{ t('decisions.parts.operativeHint') }}</small>
    </label>

    <label v-if="needsRefusal" class="field">
      <span>{{ t('decisions.refusal.label') }}</span>
      <select v-model="draft.refusal_reason_code">
        <option value="">{{ t('decisions.refusal.choose') }}</option>
        <option v-for="code in REFUSAL_REASON_CODES" :key="code" :value="code">{{ t(`decisions.refusal.reasons.${code}`) }}</option>
      </select>
      <small class="hint">{{ t('decisions.refusal.hint') }}</small>
    </label>

    <fieldset v-if="needsDeferral" class="deferral">
      <legend>{{ t('decisions.deferral.legend') }}</legend>
      <p class="hint">{{ t('decisions.deferral.hint') }}</p>
      <label v-for="field in DEFERRAL_FIELDS" :key="field" class="field">
        <span>{{ t(`decisions.deferral.${field}`) }}</span>
        <input v-model="draft[field]" type="text" />
      </label>
    </fieldset>

    <label class="field">
      <span>{{ t('decisions.notesLabel') }}</span>
      <textarea v-model="draft.comment" :placeholder="t('decisions.commentPlaceholder')" rows="2" maxlength="2000" />
    </label>
    <label v-if="needsReferral" class="field">
      <span>{{ t('decisions.referralAuthorityLabel') }}</span>
      <input v-model="draft.referral_authority" type="text" required :placeholder="t('decisions.referralAuthorityPlaceholder')" />
    </label>
  </div>
</template>

<style scoped>
.record-form { display: grid; gap: var(--space-2); }
.template-picker { display: flex; gap: var(--space-2); }
.template-picker select { flex: 1; min-width: 0; }
.field { display: grid; gap: 0.25rem; }
.field > span { color: var(--color-muted); font-size: var(--text-xs); font-weight: 600; }
.field textarea, .field input, .field select { inline-size: 100%; resize: vertical; }
.hint { color: var(--color-muted); font-size: var(--text-xs); }
.deferral { display: grid; gap: 0.45rem; margin: 0; padding: var(--space-2) var(--space-3); border: 1px solid var(--color-warning-border); background: var(--color-warning-bg); border-radius: var(--radius-lg); }
.deferral legend { padding: 0 0.3rem; color: var(--color-warning-fg); font-size: var(--text-xs); font-weight: 600; }
</style>
