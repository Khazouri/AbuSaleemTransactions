<script setup>
/**
 * Decision wizard — sub-project 3. Art. 37's closure card and Appendix 47's
 * audit, moved out of RequestClosurePanel. Tri-state answers, because several
 * questions are genuinely inapplicable on some final paths.
 */
import { useI18n } from 'vue-i18n'
import { CLOSER_CHECKS } from '../../lib/requestClosure'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('requestClosure.formNote') }}</p>
    <div class="field-grid">
      <label>
        <span>{{ t('requestClosure.fields.approving_body') }} *</span>
        <input v-model="form.approving_body" type="text" required>
      </label>
      <label>
        <span>{{ t('requestClosure.fields.final_decision_number') }}</span>
        <input v-model="form.final_decision_number" type="text">
      </label>
      <label>
        <span>{{ t('requestClosure.fields.execution_date') }}</span>
        <input v-model="form.execution_date" type="date">
      </label>
      <label>
        <span>{{ t('requestClosure.fields.executing_body') }}</span>
        <input v-model="form.executing_body" type="text">
      </label>
    </div>
    <h4>{{ t('requestClosure.auditTitle') }}</h4>
    <p class="hint">{{ t('requestClosure.auditNote') }}</p>
    <ul class="checklist">
      <li v-for="check in CLOSER_CHECKS" :key="check">
        <span class="question">{{ t(`requestClosure.checks.${check}`) }}</span>
        <select v-model="form.audit[check]">
          <option value="yes">{{ t('requestClosure.answers.yes') }}</option>
          <option value="no">{{ t('requestClosure.answers.no') }}</option>
          <option value="not_applicable">{{ t('requestClosure.answers.not_applicable') }}</option>
        </select>
      </li>
    </ul>
  </div>
</template>
