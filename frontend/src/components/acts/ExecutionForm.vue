<script setup>
/**
 * Decision wizard — sub-project 3. النموذج 17 and Appendix 70's دليل التنفيذ,
 * moved out of the former RequestExecutionPanel. Evidence is chosen from the
 * file's own documents, never uploaded blind; uploading proof is its own act.
 */
import { useI18n } from 'vue-i18n'
import { EVIDENCE_TYPES, EXECUTOR_CHECKS, SERVICE_FILE_ITEMS } from '../../lib/requestExecution'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('requestExecution.formNote') }}</p>

    <div class="field-grid">
      <label>
        <span>{{ t('requestExecution.fields.executing_body') }} *</span>
        <input v-model="form.executing_body" type="text" required>
      </label>
      <label>
        <span>{{ t('requestExecution.fields.effective_date') }} *</span>
        <input v-model="form.effective_date" type="date" required>
      </label>
      <label>
        <span>{{ t('requestExecution.fields.approving_body') }} *</span>
        <input v-model="form.approving_body" type="text" required>
      </label>
      <label>
        <span>{{ t('requestExecution.fields.approval_number') }}</span>
        <input v-model="form.approval_number" type="text">
      </label>
      <label>
        <span>{{ t('requestExecution.fields.approval_date') }}</span>
        <input v-model="form.approval_date" type="date">
      </label>
    </div>

    <label class="wide">
      <span>{{ t('requestExecution.fields.action_taken') }} *</span>
      <textarea v-model="form.action_taken" rows="2" required />
    </label>

    <label v-if="act.has_financial_impact" class="wide">
      <span>{{ t('requestExecution.fields.financial_effect_note') }}</span>
      <textarea v-model="form.financial_effect_note" rows="2" />
    </label>

    <h4>{{ t('requestExecution.evidenceTitle') }}</h4>
    <p class="hint">{{ t('requestExecution.evidenceNote') }}</p>
    <p v-if="!request.attachments?.length" class="alert warning">{{ t('requestExecution.noAttachments') }}</p>
    <ul v-else class="checklist">
      <li v-for="attachment in request.attachments" :key="attachment.id">
        <span class="question">{{ attachment.original_name }}</span>
        <select v-model="form.evidence[attachment.id]">
          <option value="">{{ t('requestExecution.notEvidence') }}</option>
          <option v-for="type in EVIDENCE_TYPES" :key="type" :value="type">
            {{ t(`requestExecution.evidenceTypes.${type}`) }}
          </option>
        </select>
      </li>
    </ul>

    <h4>{{ t('requestExecution.checklistTitle') }}</h4>
    <p class="hint">{{ t('requestExecution.checklistNote') }}</p>
    <ul class="checklist">
      <li v-for="check in EXECUTOR_CHECKS" :key="check">
        <span class="question">
          {{ t(`requestExecution.checks.${check}`) }}
          <em v-if="check === 'financial_effect_referred' && act.has_financial_impact">{{ t('requestExecution.financialBinds') }}</em>
          <em v-if="check === 'employee_file_updated'">
            {{ SERVICE_FILE_ITEMS.map((item) => t(`requestExecution.serviceFile.${item}`)).join(' · ') }}
          </em>
        </span>
        <select v-model="form.checklist[check]">
          <option value="yes">{{ t('requestExecution.answers.yes') }}</option>
          <option value="no">{{ t('requestExecution.answers.no') }}</option>
          <option value="not_applicable">{{ t('requestExecution.answers.not_applicable') }}</option>
        </select>
      </li>
    </ul>
  </div>
</template>
