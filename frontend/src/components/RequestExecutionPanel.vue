<script setup>
// Stage 76 — [D] النموذج 17's أمر تنفيذ قرار وظيفي plus Appendix 70's
// دليل التنفيذ. The evidence picker is the point of the screen: "تم التنفيذ"
// cannot be submitted as a bare claim, so at least one of the request's own
// documents has to be nominated and typed before the button enables.
//
// Stage 92 — the button rides meeting_outputs.approve, not .edit: [F] step
// 10's الجهة المنفذة is who this record names, so R12 (HR, the executing body
// named most often) holds this tier alongside R02/R03.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { EVIDENCE_TYPES, EXECUTOR_CHECKS, SERVICE_FILE_ITEMS } from '../lib/requestExecution'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  agendaItemId: { type: [Number, String], required: true },
  // The request's own documents — Appendix 70's evidence is chosen from these,
  // never uploaded blind, so a nominated file always belongs to this file.
  attachments: { type: Array, default: () => [] },
  // Art. 97 — when true, the financial-referral answer binds server-side.
  hasFinancialImpact: { type: Boolean, default: false },
})

const emit = defineEmits(['executed'])

const { t } = useI18n()

const open = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({
  executing_body: '',
  action_taken: '',
  effective_date: '',
  approving_body: '',
  approval_number: '',
  approval_date: '',
  financial_effect_note: '',
})

// Tri-state, not a checkbox: not every executed decision issues an
// administrative قرار or touches an organisational unit, so "لا ينطبق" has to
// be sayable — Stage 75's own reasoning for Appendix 47.
const checklist = reactive(Object.fromEntries(EXECUTOR_CHECKS.map((key) => [key, 'yes'])))

// attachment id -> Appendix 70 kind; an entry means "this document is دليل التنفيذ".
const evidence = reactive({})

const evidenceEntries = computed(() =>
  Object.entries(evidence)
    .filter(([, type]) => type)
    .map(([id, type]) => ({ attachment_id: Number(id), evidence_type: type })),
)

const canSubmit = computed(
  () =>
    form.executing_body.trim() !== '' &&
    form.action_taken.trim() !== '' &&
    form.effective_date !== '' &&
    form.approving_body.trim() !== '' &&
    evidenceEntries.value.length > 0,
)

watch(open, (isOpen) => {
  if (!isOpen) error.value = ''
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.post(
      `/meetings/${props.meetingId}/outputs/${props.agendaItemId}/execute`,
      { ...form, checklist: { ...checklist }, evidence: evidenceEntries.value },
    )
    open.value = false
    emit('executed', data.data)
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' — ')
      : (requestError.response?.data?.message ?? t('requestExecution.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <button
      v-if="!open"
      v-can="'meeting_outputs.approve'"
      class="btn btn-sm primary"
      type="button"
      @click="open = true"
    >
      {{ t('requestExecution.action') }}
    </button>

    <form v-if="open" class="gate-form" @submit.prevent="submit">
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

      <label v-if="hasFinancialImpact" class="wide">
        <span>{{ t('requestExecution.fields.financial_effect_note') }}</span>
        <textarea v-model="form.financial_effect_note" rows="2" />
      </label>

      <h4>{{ t('requestExecution.evidenceTitle') }}</h4>
      <p class="hint">{{ t('requestExecution.evidenceNote') }}</p>
      <p v-if="attachments.length === 0" class="alert warning">
        {{ t('requestExecution.noAttachments') }}
      </p>
      <ul v-else class="checklist">
        <li v-for="attachment in attachments" :key="attachment.id">
          <span class="question">{{ attachment.original_name }}</span>
          <select v-model="evidence[attachment.id]">
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
            <em v-if="check === 'financial_effect_referred' && hasFinancialImpact">
              {{ t('requestExecution.financialBinds') }}
            </em>
            <!-- Appendix 52 says what "the employee file was updated" has to
                 cover; the file itself lives outside this application. -->
            <em v-if="check === 'employee_file_updated'">
              {{ SERVICE_FILE_ITEMS.map((item) => t(`requestExecution.serviceFile.${item}`)).join(' · ') }}
            </em>
          </span>
          <select v-model="checklist[check]">
            <option value="yes">{{ t('requestExecution.answers.yes') }}</option>
            <option value="no">{{ t('requestExecution.answers.no') }}</option>
            <option value="not_applicable">{{ t('requestExecution.answers.not_applicable') }}</option>
          </select>
        </li>
      </ul>

      <p v-if="error" class="alert warning">{{ error }}</p>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !canSubmit">
          {{ saving ? t('requestExecution.saving') : t('requestExecution.confirm') }}
        </button>
        <button class="ghost" type="button" @click="open = false">{{ t('requestExecution.cancel') }}</button>
      </div>
    </form>
  </div>
</template>

