<script setup>
/** Decision wizard — sub-project 3. Stage 60's formal verification, moved out of AppealsView. */
import { useI18n } from 'vue-i18n'
import { VERIFY_CHECKS } from '../../lib/appealActs'

defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
const LABELS = { appellant_standing: 'appellantStanding', valid_target_decision: 'validTargetDecision', non_duplication: 'nonDuplication' }
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('appeals.verify.hint') }}</p>
    <div class="field-grid">
      <label v-for="key in VERIFY_CHECKS" :key="key">
        <span>{{ t(`appeals.verify.${LABELS[key]}`) }} *</span>
        <select v-model="form[key]">
          <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
          <option value="yes">{{ t('appeals.verify.yes') }}</option>
          <option value="no">{{ t('appeals.verify.no') }}</option>
        </select>
      </label>
    </div>
    <label>
      <span>{{ t('appeals.verify.reason') }}</span>
      <textarea v-model="form.reason" rows="2" />
    </label>
  </div>
</template>
