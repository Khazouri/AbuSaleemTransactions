<script setup>
/** Decision wizard — sub-project 3. What handling a special case concluded — a real field, not window.prompt. */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('lifecycle.specialCases.resolvePrompt') }} *</span>
      <textarea v-model="form.resolution_note" rows="3" required maxlength="2000" />
    </label>
  </div>
</template>
