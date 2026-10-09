<script setup>
/**
 * Decision wizard — sub-project 3. Stage 66's re-presentation, moved out of
 * RequestDetailView: one of six enumerated reasons, and the stage to resume at.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../lib/api'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()

const REASON_CODES = [
  'new_document', 'external_reply_received', 'material_error_correction',
  'legal_status_change', 'returned_by_approving_body', 'competent_authority_restudy',
]
const stages = ref([])
const loadError = ref('')

onMounted(async () => {
  try {
    const { data } = await api.get('/appeals/redo-stage-options')
    stages.value = data.data ?? []
  } catch {
    loadError.value = t('requestDetail.reopen.loadStagesFailed')
  }
})
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('requestDetail.reopen.hint') }}</p>
    <label>
      <span>{{ t('requestDetail.reopen.reasonLabel') }} *</span>
      <select v-model="form.reason_code" required>
        <option value="" disabled>{{ t('requestDetail.reopen.chooseReason') }}</option>
        <option v-for="code in REASON_CODES" :key="code" :value="code">{{ t(`reopenReasons.${code}`) }}</option>
      </select>
    </label>
    <label>
      <span>{{ t('requestDetail.reopen.targetStageLabel') }} *</span>
      <select v-model="form.target_stage_id" required>
        <option value="" disabled>{{ t('requestDetail.reopen.chooseStage') }}</option>
        <option v-for="stage in stages" :key="stage.id" :value="stage.id">{{ locale === 'ar' ? stage.name_ar : stage.name_en }}</option>
      </select>
    </label>
    <p v-if="loadError" class="alert warning" role="alert">{{ loadError }}</p>
    <label>
      <span>{{ t('requestDetail.reopen.noteLabel') }}</span>
      <textarea v-model="form.note" rows="2" maxlength="5000" />
    </label>
  </div>
</template>
