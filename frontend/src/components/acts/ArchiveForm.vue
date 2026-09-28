<script setup>
/**
 * Decision wizard — sub-project 3. Appendix 6 row 15's archive record, moved
 * out of RequestArchivePanel. One form for both files: the act says which.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()

const file = computed(() => (props.act.action === 'archive_service_file' ? 'service_file' : 'committee_file'))
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('requestArchive.intro') }}</p>
    <label>
      <span>{{ t(`requestArchive.files.${file}`) }} *</span>
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
