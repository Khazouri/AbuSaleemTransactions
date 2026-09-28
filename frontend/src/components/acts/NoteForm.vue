<script setup>
/** Decision wizard — sub-project 3. An internal note, moved out of RequestNotes. */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('notes.add') }} *</span>
      <textarea v-model="form.body" rows="4" maxlength="5000" required />
    </label>
  </div>
</template>
