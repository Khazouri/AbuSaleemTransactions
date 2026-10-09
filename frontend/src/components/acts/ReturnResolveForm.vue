<script setup>
/** Decision wizard — sub-project 3. Art. 94's الإجراء الذي اتخذ بشأنها; routing follows the recorded kind. */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">
      {{ act.return_kind === 'substantive' ? t('approvalReturn.routing.substantive') : t('approvalReturn.routing.formal') }}
    </p>
    <label>
      <span>{{ t('approvalReturn.fields.resolution_action') }} *</span>
      <textarea v-model="form.resolution_action" rows="3" required />
    </label>
  </div>
</template>
