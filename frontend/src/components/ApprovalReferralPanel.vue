<script setup>
// Stage 80 — [D] Art. 30's سجل الإحالات للاعتماد, i.e. Art. 98's register 7.
//
// Two forms in one panel because the article names an outward moment and an
// inward one that nobody can answer at the same time: record the referral when
// the file leaves, then record what the approving body answered. Same shape as
// Stage 77's ApprovalReturnPanel, which the same مقرر uses on the same screen.
//
// This is a register entry, not a gate: nothing here blocks the approval
// transition, because Art. 30 says "ويسجل", not "ولا يحال قبل".
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { REFERRAL_OUTCOMES } from '../lib/approvalReferral'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // Art. 30's own refusal, computed server-side; null means recordable.
  refusal: { type: String, default: null },
  // The referral still awaiting the approving body's answer, if any.
  openReferral: { type: Object, default: null },
})

const emit = defineEmits(['updated'])

const { t } = useI18n()

const open = ref(false)
const answering = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({
  referred_at: '',
  letter_number: '',
  referred_to_body: '',
})

const result = reactive({
  result_outcome: 'approved',
  result_received_at: '',
  approval_decision_number: '',
  result_note: '',
})

watch([open, answering], () => {
  error.value = ''
})

const canSubmit = computed(
  () => form.referred_at !== ''
    && form.letter_number.trim() !== ''
    && form.referred_to_body.trim() !== '',
)

// رقم قرار الاعتماد binds only on an approval — a file the body sent back has
// no اعتماد number, so the form must not demand one for it either.
const canSubmitResult = computed(
  () => result.result_received_at !== ''
    && (result.result_outcome !== 'approved' || result.approval_decision_number.trim() !== ''),
)

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : requestError.response?.data?.message ?? t('approvalReferral.error')
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/requests/${props.requestId}/approval-referrals`, { ...form })
    open.value = false
    form.referred_at = ''
    form.letter_number = ''
    form.referred_to_body = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}

async function submitResult() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(
      `/requests/${props.requestId}/approval-referrals/${props.openReferral.id}/result`,
      { ...result },
    )
    answering.value = false
    result.result_received_at = ''
    result.approval_decision_number = ''
    result.result_note = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <!-- Art. 30's inward half: an outstanding referral is answered first. -->
    <template v-if="openReferral">
      <p class="alert info">
        {{ t('approvalReferral.pendingResult', {
          letter: openReferral.letter_number,
          body: openReferral.referred_to_body,
        }) }}
      </p>

      <button
        v-if="!answering"
        v-can="'meeting_outputs.edit'"
        class="btn btn-sm primary"
        type="button"
        @click="answering = true"
      >
        {{ t('approvalReferral.resultAction') }}
      </button>

      <form v-else class="gate-form" @submit.prevent="submitResult">
        <div class="field-grid">
          <label>
            <span>{{ t('approvalReferral.fields.result_outcome') }} *</span>
            <select v-model="result.result_outcome">
              <option v-for="outcome in REFERRAL_OUTCOMES" :key="outcome" :value="outcome">
                {{ t(`approvalReferral.outcomes.${outcome}`) }}
              </option>
            </select>
          </label>
          <label>
            <span>{{ t('approvalReferral.fields.result_received_at') }} *</span>
            <input v-model="result.result_received_at" type="date" required>
          </label>
          <label>
            <span>
              {{ t('approvalReferral.fields.approval_decision_number') }}
              <template v-if="result.result_outcome === 'approved'"> *</template>
            </span>
            <input v-model="result.approval_decision_number" type="text">
          </label>
        </div>

        <label>
          <span>{{ t('approvalReferral.fields.result_note') }}</span>
          <textarea v-model="result.result_note" rows="3" />
        </label>

        <p v-if="error" class="alert warning">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving || !canSubmitResult">
            {{ saving ? t('approvalReferral.saving') : t('approvalReferral.confirmResult') }}
          </button>
          <button class="ghost" type="button" @click="answering = false">{{ t('approvalReferral.cancel') }}</button>
        </div>
      </form>
    </template>

    <template v-else>
      <p v-if="refusal" class="alert info">{{ refusal }}</p>

      <button
        v-else-if="!open"
        v-can="'meeting_outputs.edit'"
        class="btn btn-sm primary"
        type="button"
        @click="open = true"
      >
        {{ t('approvalReferral.action') }}
      </button>

      <form v-if="open" class="gate-form" @submit.prevent="submit">
        <p class="hint">{{ t('approvalReferral.formNote') }}</p>

        <div class="field-grid">
          <label>
            <span>{{ t('approvalReferral.fields.referred_at') }} *</span>
            <input v-model="form.referred_at" type="date" required>
          </label>
          <label>
            <span>{{ t('approvalReferral.fields.letter_number') }} *</span>
            <input v-model="form.letter_number" type="text" required>
          </label>
          <label>
            <span>{{ t('approvalReferral.fields.referred_to_body') }} *</span>
            <input v-model="form.referred_to_body" type="text" required>
          </label>
        </div>

        <p v-if="error" class="alert warning">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving || !canSubmit">
            {{ saving ? t('approvalReferral.saving') : t('approvalReferral.confirm') }}
          </button>
          <button class="ghost" type="button" @click="open = false">{{ t('approvalReferral.cancel') }}</button>
        </div>
      </form>
    </template>
  </div>
</template>

