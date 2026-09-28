<script setup>
/**
 * Stage 54 — [D] Art. 45's six-question jurisdiction test, answered once at
 * requirements_check. Lifted out of the request page so the decision wizard's
 * Checks step and the page's البوابات tab render one form, not two copies.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  request: { type: Object, required: true },
  // Decision wizard — sub-project 3. The gates tab shows the card only; its
  // form lives in the wizard's Checks step.
  readonly: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])

const { t, locale } = useI18n()
const auth = useAuthStore()
const saving = ref(false)
const error = ref('')
const form = ref(fromRecord(props.request.jurisdiction_test))

const YES_NO = ['has_legal_basis', 'employee_covered', 'within_municipal_jurisdiction', 'requires_central_approval']

function fromRecord(existing) {
  if (!existing) {
    return {
      has_legal_basis: '',
      employee_covered: '',
      within_municipal_jurisdiction: '',
      committee_decides: '',
      final_approval_authority: '',
      requires_central_approval: '',
    }
  }
  const answer = (value) => (value ? 'yes' : 'no')
  return {
    has_legal_basis: answer(existing.has_legal_basis),
    employee_covered: answer(existing.employee_covered),
    within_municipal_jurisdiction: answer(existing.within_municipal_jurisdiction),
    committee_decides: answer(existing.committee_decides),
    final_approval_authority: existing.final_approval_authority || '',
    requires_central_approval: answer(existing.requires_central_approval),
  }
}

// Keyed on content, not identity: every other gate on the page swaps in a
// fresh request object, which must not wipe answers typed but not yet saved.
watch(() => JSON.stringify(props.request.jurisdiction_test), () => {
  form.value = fromRecord(props.request.jurisdiction_test)
})

const dateTime = (value) => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))

async function save() {
  if (saving.value) return
  saving.value = true
  error.value = ''
  try {
    const payload = { final_approval_authority: form.value.final_approval_authority.trim() }
    for (const key of [...YES_NO, 'committee_decides']) payload[key] = form.value[key] === 'yes'
    const { data } = await api.patch(`/requests/${props.request.id}/jurisdiction-test`, payload)
    emit('updated', data.data)
  } catch (requestError) {
    error.value = requestError.response?.data?.errors?.final_approval_authority?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.jurisdictionTest.saveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="jurisdiction-test">
    <p class="hint">{{ t('requestDetail.jurisdictionTest.hint') }}</p>
    <p v-if="request.jurisdiction_test" class="state">{{ t('requestDetail.jurisdictionTest.recorded') }}</p>
    <p v-else class="field-error">{{ t('requestDetail.jurisdictionTest.notRecorded') }}</p>
    <!-- Stage 84 — whose answers these are. -->
    <p v-if="request.control_gates?.intake?.jurisdiction_test?.recorded_by" class="hint">
      {{ t('requestDetail.jurisdictionTest.recordedBy', {
        name: request.control_gates.intake.jurisdiction_test.recorded_by.name,
        at: dateTime(request.control_gates.intake.jurisdiction_test.recorded_at),
      }) }}
    </p>
    <!-- Read-only without the save grant: since Stage 101 R12 can open a file at
         requirements_check, and editable fields with no save button lost its input on refresh. -->
    <fieldset :disabled="readonly || saving || !auth.can('notes_attachments', 'edit')">
      <div class="grid">
        <label v-for="(key, index) in ['has_legal_basis', 'employee_covered', 'within_municipal_jurisdiction']" :key="key">
          {{ t(`requestDetail.jurisdictionTest.q${index + 1}`) }}
          <select v-model="form[key]">
            <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
            <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
            <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
          </select>
        </label>
        <label>
          {{ t('requestDetail.jurisdictionTest.q4') }}
          <select v-model="form.committee_decides">
            <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
            <option value="yes">{{ t('requestDetail.jurisdictionTest.binding') }}</option>
            <option value="no">{{ t('requestDetail.jurisdictionTest.advisory') }}</option>
          </select>
        </label>
        <label class="wide">
          {{ t('requestDetail.jurisdictionTest.q5') }}
          <input v-model="form.final_approval_authority" type="text" maxlength="255" />
        </label>
        <label>
          {{ t('requestDetail.jurisdictionTest.q6') }}
          <select v-model="form.requires_central_approval">
            <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
            <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
            <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
          </select>
        </label>
      </div>
    </fieldset>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <button v-if="!readonly" v-can="'notes_attachments.edit'" class="ghost" type="button" :disabled="saving" @click="save">
      {{ saving ? t('requestDetail.jurisdictionTest.saving') : t('requestDetail.jurisdictionTest.save') }}
    </button>
  </div>
</template>

<style scoped>
.jurisdiction-test > p { margin: 0 0 var(--space-3); }
fieldset { padding: 0; margin: 0; border: 0; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
label { display: grid; gap: 0.3rem; color: var(--color-black-700); font-size: var(--text-sm); }
select, input { padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; }
button { margin-top: var(--space-3); }
@media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
</style>
