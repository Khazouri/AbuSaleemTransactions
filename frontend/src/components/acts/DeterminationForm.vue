<script setup>
/**
 * Decision wizard — sub-project 3. Appendices 68 step 4 / 69. The outcomes
 * are the ones the server says this withdrawal may take (act.outcomes).
 */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="quote">{{ act.target?.label }}</p>
    <label>
      <span>{{ t('lifecycle.withdrawals.fields.outcome') }} *</span>
      <select v-model="form.outcome">
        <option v-for="outcome in act.outcomes" :key="outcome" :value="outcome">{{ t(`lifecycle.withdrawals.outcomes.${outcome}`) }}</option>
      </select>
    </label>
    <label>
      <span>{{ t('lifecycle.withdrawals.fields.determination_note') }} *</span>
      <textarea v-model="form.determination_note" rows="3" required maxlength="2000" />
    </label>
  </div>
</template>
