<script setup>
/** Decision wizard — sub-project 3. Art. 75 point 4's five questions, moved out of AppealsView. */
import { useI18n } from 'vue-i18n'
import { LEGAL_REVIEW_CHECKS } from '../../lib/appealActs'

defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
const LABELS = {
  factual_error: 'factualError',
  legal_text_violation: 'legalTextViolation',
  new_documents: 'newDocuments',
  formation_or_reasoning_defect: 'formationOrReasoningDefect',
  issued_by_competent_body: 'issuedByCompetentBody',
}
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('appeals.legalReview.hint') }}</p>
    <div class="field-grid">
      <label v-for="key in LEGAL_REVIEW_CHECKS" :key="key">
        <span>{{ t(`appeals.legalReview.${LABELS[key]}`) }} *</span>
        <select v-model="form[key]">
          <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
          <option value="yes">{{ t('appeals.verify.yes') }}</option>
          <option value="no">{{ t('appeals.verify.no') }}</option>
        </select>
      </label>
    </div>
  </div>
</template>
