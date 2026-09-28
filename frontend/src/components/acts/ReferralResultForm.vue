<script setup>
/** Decision wizard — sub-project 3. Art. 30's inward three, against the referral the act names. */
import { useI18n } from 'vue-i18n'
import { REFERRAL_OUTCOMES } from '../../lib/approvalReferral'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <div class="field-grid">
      <label>
        <span>{{ t('approvalReferral.fields.result_outcome') }} *</span>
        <select v-model="form.result_outcome">
          <option v-for="outcome in REFERRAL_OUTCOMES" :key="outcome" :value="outcome">
            {{ t(`approvalReferral.outcomes.${outcome}`) }}
          </option>
        </select>
      </label>
      <label>
        <span>{{ t('approvalReferral.fields.result_received_at') }} *</span>
        <input v-model="form.result_received_at" type="date" required>
      </label>
      <label>
        <span>
          {{ t('approvalReferral.fields.approval_decision_number') }}<template v-if="form.result_outcome === 'approved'"> *</template>
        </span>
        <input v-model="form.approval_decision_number" type="text">
      </label>
    </div>
    <label>
      <span>{{ t('approvalReferral.fields.result_note') }}</span>
      <textarea v-model="form.result_note" rows="3" />
    </label>
  </div>
</template>
