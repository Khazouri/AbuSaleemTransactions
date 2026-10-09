<script setup>
/**
 * Decision wizard — sub-project 3. Stage 65's closure record. The final
 * result is derived server-side; the form shows which it will be.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()

const result = computed(() => {
  const code = props.appeal.status?.code
  if (code === 'rejected' || code === 'outside_jurisdiction') return t(`appeals.filters.statuses.${code}`)
  const outcome = props.appeal.committee_decision?.outcome
  return outcome ? t(`decisions.outcome.${outcome}`) : t('common.none')
})
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('appeals.closure.hint') }}</p>
    <p><strong>{{ t('appeals.closure.finalResult') }}:</strong> {{ result }}</p>
    <div class="field-grid">
      <label>
        <span>{{ t('appeals.closure.approvingBody') }} *</span>
        <input v-model="form.approving_body" type="text" required>
      </label>
      <label>
        <span>{{ t('appeals.closure.fileStorageLocation') }} *</span>
        <input v-model="form.file_storage_location" type="text" required>
      </label>
      <label>
        <span>{{ t('appeals.closure.finalDecisionNumber') }}</span>
        <input v-model="form.final_decision_number" type="text">
      </label>
      <label>
        <span>{{ t('appeals.closure.executionDate') }}</span>
        <input v-model="form.execution_date" type="date">
      </label>
      <label>
        <span>{{ t('appeals.closure.executingBody') }}</span>
        <input v-model="form.executing_body" type="text">
      </label>
    </div>
  </div>
</template>
