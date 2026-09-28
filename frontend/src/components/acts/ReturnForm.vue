<script setup>
/**
 * Decision wizard — sub-project 3. Art. 94's سبب الإعادة with Appendix 34's
 * classification, moved out of ApprovalReturnPanel. Where the file goes next
 * is not a field: the server derives it from the kind.
 */
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RETURN_KINDS, reasonsForKind } from '../../lib/approvalReturn'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()

const reasonOptions = computed(() => reasonsForKind(form.value.return_kind))

// The server refuses a reason the other kind owns, so switching the kind
// never leaves one selected.
watch(() => form.value.return_kind, () => {
  if (!reasonOptions.value.includes(form.value.return_reason_code)) {
    form.value.return_reason_code = reasonOptions.value[0]
  }
})
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('approvalReturn.formNote') }}</p>
    <div class="field-grid">
      <label>
        <span>{{ t('approvalReturn.fields.return_kind') }} *</span>
        <select v-model="form.return_kind">
          <option v-for="kind in RETURN_KINDS" :key="kind" :value="kind">{{ t(`approvalReturn.kinds.${kind}`) }}</option>
        </select>
      </label>
      <label>
        <span>{{ t('approvalReturn.fields.return_reason_code') }} *</span>
        <select v-model="form.return_reason_code">
          <option v-for="code in reasonOptions" :key="code" :value="code">{{ t(`approvalReturn.reasons.${code}`) }}</option>
        </select>
      </label>
      <label>
        <span>{{ t('approvalReturn.fields.received_at') }} *</span>
        <input v-model="form.received_at" type="date" required>
      </label>
      <label>
        <span>{{ t('approvalReturn.fields.letter_number') }}</span>
        <input v-model="form.letter_number" type="text">
      </label>
    </div>
    <label>
      <span>{{ t('approvalReturn.fields.return_note') }} *</span>
      <textarea v-model="form.return_note" rows="3" required />
    </label>
    <p class="hint">
      {{ form.return_kind === 'substantive' ? t('approvalReturn.routing.substantive') : t('approvalReturn.routing.formal') }}
    </p>
  </div>
</template>
