<script setup>
/** Decision wizard — sub-project 3. Appendix 68 step 1 — the filer's written request. */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('lifecycle.withdrawals.fields.reason') }} *</span>
      <textarea v-model="form.reason" rows="3" required maxlength="2000" />
    </label>
  </div>
</template>
