<script setup>
// Stage 75 — [D] Art. 37's بطاقة إقفال المعاملة (النموذج 18) and Appendix 47's
// pre-closure audit. Shared by the request workspace and the meeting-outputs
// tracker, because both offer the same closure and neither should carry its
// own copy of a twelve-check form — the AgendaItemDecisionPanel precedent.
//
// Stage 92 — the button rides meeting_outputs.approve, not .edit: closure's
// own executing_body field is [F] step 10's question, so R12 (HR) holds this
// tier alongside R02/R03 while the screen's other, unrelated .edit actions
// stay theirs alone.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { CLOSER_CHECKS } from '../lib/requestClosure'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // Appendix 48's refusal, computed server-side; null means closable.
  refusal: { type: String, default: null },
})

const emit = defineEmits(['closed'])

const { t } = useI18n()

const open = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({
  final_decision_number: '',
  approving_body: '',
  execution_date: '',
  executing_body: '',
  file_storage_location: '',
})

// Tri-state, not a checkbox: Art. 37's four final paths make several of
// Appendix 47's questions genuinely inapplicable (a عدم اختصاص closed before
// any sitting has no محضر to approve), so "لا ينطبق" has to be sayable.
const audit = reactive(Object.fromEntries(CLOSER_CHECKS.map((key) => [key, 'yes'])))

const canSubmit = computed(
  () => form.approving_body.trim() !== '' && form.file_storage_location.trim() !== '',
)

watch(open, (isOpen) => {
  if (!isOpen) error.value = ''
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/close`, {
      ...form,
      audit: { ...audit },
    })
    open.value = false
    emit('closed', data.data)
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' — ')
      : requestError.response?.data?.message ?? t('requestClosure.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <p v-if="refusal" class="alert warning">{{ refusal }}</p>

    <button
      v-else-if="!open"
      v-can="'meeting_outputs.approve'"
      class="btn btn-sm primary"
      type="button"
      @click="open = true"
    >
      {{ t('requestClosure.action') }}
    </button>

    <form v-if="open" class="gate-form" @submit.prevent="submit">
      <p class="hint">{{ t('requestClosure.formNote') }}</p>

      <div class="field-grid">
        <label>
          <span>{{ t('requestClosure.fields.approving_body') }} *</span>
          <input v-model="form.approving_body" type="text" required>
        </label>
        <label>
          <span>{{ t('requestClosure.fields.file_storage_location') }} *</span>
          <input v-model="form.file_storage_location" type="text" required>
        </label>
        <label>
          <span>{{ t('requestClosure.fields.final_decision_number') }}</span>
          <input v-model="form.final_decision_number" type="text">
        </label>
        <label>
          <span>{{ t('requestClosure.fields.execution_date') }}</span>
          <input v-model="form.execution_date" type="date">
        </label>
        <label>
          <span>{{ t('requestClosure.fields.executing_body') }}</span>
          <input v-model="form.executing_body" type="text">
        </label>
      </div>

      <h4>{{ t('requestClosure.auditTitle') }}</h4>
      <p class="hint">{{ t('requestClosure.auditNote') }}</p>
      <ul class="checklist">
        <li v-for="check in CLOSER_CHECKS" :key="check">
          <span class="question">{{ t(`requestClosure.checks.${check}`) }}</span>
          <select v-model="audit[check]">
            <option value="yes">{{ t('requestClosure.answers.yes') }}</option>
            <option value="no">{{ t('requestClosure.answers.no') }}</option>
            <option value="not_applicable">{{ t('requestClosure.answers.not_applicable') }}</option>
          </select>
        </li>
      </ul>

      <p v-if="error" class="alert warning">{{ error }}</p>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !canSubmit">
          {{ saving ? t('requestClosure.saving') : t('requestClosure.confirm') }}
        </button>
        <button class="ghost" type="button" @click="open = false">{{ t('requestClosure.cancel') }}</button>
      </div>
    </form>
  </div>
</template>

