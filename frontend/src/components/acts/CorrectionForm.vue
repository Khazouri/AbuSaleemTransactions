<script setup>
/**
 * Decision wizard — sub-project 3. Appendix 53's memo. Only the five material
 * kinds are offered; the note names the route the substantive ones take.
 */
import { useI18n } from 'vue-i18n'
import { MATERIAL_ERROR_KINDS, SUBSTANTIVE_ERROR_KINDS } from '../../lib/lifecycle'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('lifecycle.corrections.fields.error_kind') }} *</span>
      <select v-model="form.error_kind">
        <option v-for="kind in MATERIAL_ERROR_KINDS" :key="kind" :value="kind">{{ t(`lifecycle.corrections.kinds.${kind}`) }}</option>
      </select>
    </label>
    <p class="hint">
      {{ t('lifecycle.corrections.substantiveNote') }}
      {{ SUBSTANTIVE_ERROR_KINDS.map((kind) => t(`lifecycle.corrections.kinds.${kind}`)).join(locale === 'ar' ? '، ' : ', ') }}
    </p>
    <label>
      <span>{{ t('lifecycle.corrections.fields.detail') }} *</span>
      <textarea v-model="form.detail" rows="2" required />
    </label>
    <label>
      <span>{{ t('lifecycle.corrections.fields.incorrect_value') }} *</span>
      <input v-model="form.incorrect_value" required>
    </label>
    <label>
      <span>{{ t('lifecycle.corrections.fields.corrected_value') }} *</span>
      <input v-model="form.corrected_value" required>
    </label>
    <label>
      <span>{{ t('lifecycle.corrections.fields.memo_reference') }}</span>
      <input v-model="form.memo_reference">
    </label>
  </div>
</template>
