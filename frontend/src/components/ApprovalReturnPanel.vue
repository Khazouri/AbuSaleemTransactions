<script setup>
// Stage 77 — [D] Art. 94's إجراء إعادة معالجة and Appendix 34's شكلية/موضوعية
// split. Two forms in one panel because the article names two things and
// nobody knows the second at the moment of the first: record the return, then
// record the action taken about it.
//
// Where the file goes next is deliberately not a field — Appendix 34 attaches
// the routing to its own classification ("لا يعدل المقرر القرار من تلقاء نفسه،
// بل يعاد الموضوع إلى اللجنة"), so the server derives it from the recorded
// kind. The panel only says which it will be.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { RETURN_KINDS, reasonsForKind } from '../lib/approvalReturn'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // Appendix 34's refusal, computed server-side; null means recordable.
  refusal: { type: String, default: null },
  // The unresolved round, if the file is sitting on one.
  openReturn: { type: Object, default: null },
})

const emit = defineEmits(['updated'])

const { t } = useI18n()

const open = ref(false)
const resolving = ref(false)
const saving = ref(false)
const error = ref('')

const form = reactive({
  return_kind: 'formal',
  return_reason_code: 'missing_signature',
  return_note: '',
  letter_number: '',
  received_at: '',
})

const resolution = ref('')

const reasonOptions = computed(() => reasonsForKind(form.return_kind))

// Switching the classification must not leave a reason the other kind owns
// selected — the server refuses that combination, so the form should never
// present it.
watch(
  () => form.return_kind,
  () => {
    if (!reasonOptions.value.includes(form.return_reason_code)) {
      form.return_reason_code = reasonOptions.value[0]
    }
  },
)

watch([open, resolving], () => {
  error.value = ''
})

const canSubmit = computed(
  () => form.return_note.trim() !== '' && form.received_at !== '',
)

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : requestError.response?.data?.message ?? t('approvalReturn.error')
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/approval-return`, { ...form })
    open.value = false
    form.return_note = ''
    form.letter_number = ''
    form.received_at = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}

async function submitResolution() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/approval-return/resolve`, {
      resolution_action: resolution.value,
    })
    resolving.value = false
    resolution.value = ''
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="return-panel">
    <!-- Art. 94's second half: an open return is answered before anything else. -->
    <template v-if="openReturn">
      <p class="alert">{{ t('approvalReturn.pendingResolution') }}</p>

      <button
        v-if="!resolving"
        v-can="'meeting_outputs.edit'"
        class="primary compact"
        type="button"
        @click="resolving = true"
      >
        {{ t('approvalReturn.resolveAction') }}
      </button>

      <form v-else class="return-form" @submit.prevent="submitResolution">
        <p class="hint">
          {{
            openReturn.return_kind === 'substantive'
              ? t('approvalReturn.routing.substantive')
              : t('approvalReturn.routing.formal')
          }}
        </p>
        <label>
          <span>{{ t('approvalReturn.fields.resolution_action') }} *</span>
          <textarea v-model="resolution" rows="3" required />
        </label>

        <p v-if="error" class="alert">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving || resolution.trim() === ''">
            {{ saving ? t('approvalReturn.saving') : t('approvalReturn.confirmResolution') }}
          </button>
          <button type="button" @click="resolving = false">{{ t('approvalReturn.cancel') }}</button>
        </div>
      </form>
    </template>

    <template v-else>
      <p v-if="refusal" class="alert">{{ refusal }}</p>

      <button
        v-else-if="!open"
        v-can="'meeting_outputs.edit'"
        class="primary compact"
        type="button"
        @click="open = true"
      >
        {{ t('approvalReturn.action') }}
      </button>

      <form v-if="open" class="return-form" @submit.prevent="submit">
        <p class="hint">{{ t('approvalReturn.formNote') }}</p>

        <div class="field-grid">
          <label>
            <span>{{ t('approvalReturn.fields.return_kind') }} *</span>
            <select v-model="form.return_kind">
              <option v-for="kind in RETURN_KINDS" :key="kind" :value="kind">
                {{ t(`approvalReturn.kinds.${kind}`) }}
              </option>
            </select>
          </label>
          <label>
            <span>{{ t('approvalReturn.fields.return_reason_code') }} *</span>
            <select v-model="form.return_reason_code">
              <option v-for="code in reasonOptions" :key="code" :value="code">
                {{ t(`approvalReturn.reasons.${code}`) }}
              </option>
            </select>
          </label>
          <label>
            <span>{{ t('approvalReturn.fields.received_at') }} *</span>
            <input v-model="form.received_at" type="date" required>
          </label>
          <label>
            <span>{{ t('approvalReturn.fields.letter_number') }}</span>
            <input v-model="form.letter_number" type="text">
          </label>
        </div>

        <label>
          <span>{{ t('approvalReturn.fields.return_note') }} *</span>
          <textarea v-model="form.return_note" rows="3" required />
        </label>

        <p class="hint">
          {{
            form.return_kind === 'substantive'
              ? t('approvalReturn.routing.substantive')
              : t('approvalReturn.routing.formal')
          }}
        </p>

        <p v-if="error" class="alert">{{ error }}</p>

        <div class="actions">
          <button class="primary" type="submit" :disabled="saving || !canSubmit">
            {{ saving ? t('approvalReturn.saving') : t('approvalReturn.confirm') }}
          </button>
          <button type="button" @click="open = false">{{ t('approvalReturn.cancel') }}</button>
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.return-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.return-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background: var(--color-surface);
}

.field-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.return-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}

.return-form input,
.return-form select,
.return-form textarea {
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.hint {
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-black-500);
}

.actions {
  display: flex;
  gap: 0.5rem;
}

.alert {
  margin: 0;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-warning-border);
  border-radius: 0.35rem;
  background: var(--color-warning-bg);
  color: var(--color-warning-fg);
  font-size: 0.85rem;
}
</style>
