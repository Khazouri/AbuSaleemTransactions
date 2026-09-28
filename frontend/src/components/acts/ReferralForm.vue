<script setup>
/** Decision wizard — sub-project 3. Art. 30's outward three, moved out of ApprovalReferralPanel. */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('approvalReferral.formNote') }}</p>
    <div class="field-grid">
      <label>
        <span>{{ t('approvalReferral.fields.referred_at') }} *</span>
        <input v-model="form.referred_at" type="date" required>
      </label>
      <label>
        <span>{{ t('approvalReferral.fields.letter_number') }} *</span>
        <input v-model="form.letter_number" type="text" required>
      </label>
      <label>
        <span>{{ t('approvalReferral.fields.referred_to_body') }} *</span>
        <input v-model="form.referred_to_body" type="text" required>
      </label>
    </div>
  </div>
</template>
