<script setup>
/**
 * Decision wizard — sub-project 3. Appendix 6 row 15's archive record, moved
 * out of RequestArchivePanel. Only HR's service file since 2026-10-25.
 */
import { useI18n } from 'vue-i18n'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('requestArchive.intro') }}</p>
    <label>
      <span>{{ t('requestArchive.files.service_file') }}<span class="required-mark" aria-hidden="true">*</span></span>
      <input
        v-model="form.location"
        type="text"
        required
        maxlength="255"
        :placeholder="t('requestArchive.locationPlaceholder')"
      >
    </label>
  </div>
</template>
