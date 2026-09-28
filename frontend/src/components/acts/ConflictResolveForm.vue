<script setup>
/**
 * Decision wizard — sub-project 3. Appendix 30 steps 2–4. All three fields
 * together: the appendix refuses a resolution resting on nobody's authority.
 */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('lifecycle.conflicts.resolveNote') }}</p>
    <label>
      <span>{{ t('lifecycle.conflicts.fields.authority_consulted') }} *</span>
      <input v-model="form.authority_consulted" required>
    </label>
    <label>
      <span>{{ t('lifecycle.conflicts.fields.authoritative_document') }} *</span>
      <textarea v-model="form.authoritative_document" rows="2" required />
    </label>
    <label>
      <span>{{ t('lifecycle.conflicts.fields.correction_note') }} *</span>
      <textarea v-model="form.correction_note" rows="2" required />
    </label>
  </div>
</template>
