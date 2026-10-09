<script setup>
/** Decision wizard — sub-project 3. Appendix 30 step 1, moved out of RequestLifecyclePanel. */
import { useI18n } from 'vue-i18n'
import { CONFLICT_KINDS } from '../../lib/lifecycle'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('lifecycle.conflicts.fields.conflict_kind') }} *</span>
      <select v-model="form.conflict_kind">
        <option v-for="kind in CONFLICT_KINDS" :key="kind" :value="kind">{{ t(`lifecycle.conflicts.kinds.${kind}`) }}</option>
      </select>
    </label>
    <label>
      <span>{{ t('lifecycle.conflicts.fields.detail') }} *</span>
      <textarea v-model="form.detail" rows="3" required />
    </label>
  </div>
</template>
