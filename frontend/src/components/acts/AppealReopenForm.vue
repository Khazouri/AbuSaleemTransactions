<script setup>
/** Decision wizard — sub-project 3. Stage 66 — a closed appeal reopens for one of six reasons only. */
import { useI18n } from 'vue-i18n'

defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
const REASON_CODES = [
  'new_document', 'external_reply_received', 'material_error_correction',
  'legal_status_change', 'returned_by_approving_body', 'competent_authority_restudy',
]
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('appeals.reopen.hint') }}</p>
    <label>
      <span>{{ t('appeals.reopen.reasonLabel') }} *</span>
      <select v-model="form.reason_code" required>
        <option value="" disabled>{{ t('appeals.reopen.chooseReason') }}</option>
        <option v-for="code in REASON_CODES" :key="code" :value="code">{{ t(`reopenReasons.${code}`) }}</option>
      </select>
    </label>
    <label>
      <span>{{ t('appeals.reopen.noteLabel') }}</span>
      <textarea v-model="form.note" rows="2" maxlength="5000" />
    </label>
  </div>
</template>
