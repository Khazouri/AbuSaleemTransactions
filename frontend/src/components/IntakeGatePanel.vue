<script setup>
// Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), answered per document.
//
// The document list is not hard-coded here: it comes from the server, which
// reads it from the request type's own Appendix 57 matrix (Stage 72). Each
// entry carries whether the source qualified it with a condition, and that is
// what decides whether "لا ينطبق" may be offered at all — the server refuses
// a waiver on an unconditional item, so the picker must not present one.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { intakeAnswersFor } from '../lib/controlGates'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // { key: { ar, en, conditional } }, straight from the type's own matrix.
  requiredDocuments: { type: Object, default: () => ({}) },
  // The stored record, once one exists.
  record: { type: Object, default: null },
  // Why the قيد would be refused right now; null means the gate passes.
  refusal: { type: String, default: null },
})

const emit = defineEmits(['updated'])

const { t, locale } = useI18n()

const open = ref(false)
const saving = ref(false)
const error = ref('')

const answers = reactive({})
const factsVerified = ref(false)

const documents = computed(() => Object.entries(props.requiredDocuments))

function label(document) {
  return (locale.value === 'ar' ? document.ar : document.en) || document.ar || document.en
}

// Seed the form from whatever is already recorded, so re-opening it shows the
// previous answers rather than a blank slate the officer has to redo.
function seed() {
  const stored = props.record?.documents ?? {}
  for (const [key] of documents.value) {
    answers[key] = stored[key] ?? ''
  }
  factsVerified.value = props.record?.facts_verified === true
}

watch(open, (isOpen) => {
  error.value = ''
  if (isOpen) seed()
})

const canSubmit = computed(() => documents.value.every(([key]) => answers[key] !== ''))

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : (requestError.response?.data?.message ?? t('controlGates.error'))
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/intake-gate`, {
      documents: { ...answers },
      facts_verified: factsVerified.value,
    })
    open.value = false
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
    <p v-if="refusal" class="alert">{{ refusal }}</p>
    <p v-else class="ok">{{ t('controlGates.intake.passed') }}</p>

    <!-- The recorded card, so a later reader sees Appendix 57's own list
         rather than only whether it passed. -->
    <ul v-if="record?.items?.length" class="recorded">
      <li v-for="item in record.items" :key="item.key">
        <span>{{ locale === 'ar' ? item.label_ar : item.label_en }}</span>
        <span :class="['answer', item.answer]">
          {{ t(`controlGates.intake.answers.${item.answer}`) }}
        </span>
      </li>
    </ul>

    <button
      v-if="!open"
      v-can="'notes_attachments.edit'"
      class="primary compact"
      type="button"
      @click="open = true"
    >
      {{ record ? t('controlGates.intake.reviseAction') : t('controlGates.intake.action') }}
    </button>

    <form v-else class="gate-form" @submit.prevent="submit">
      <p class="hint">{{ t('controlGates.intake.formNote') }}</p>

      <p v-if="!documents.length" class="hint">{{ t('controlGates.intake.noDocuments') }}</p>

      <label v-for="[key, document] in documents" :key="key">
        <span>
          {{ label(document) }}
          <em v-if="document.conditional">{{ t('controlGates.intake.conditional') }}</em>
        </span>
        <select v-model="answers[key]" required>
          <option value="" disabled>{{ t('controlGates.intake.choose') }}</option>
          <option v-for="answer in intakeAnswersFor(document)" :key="answer" :value="answer">
            {{ t(`controlGates.intake.answers.${answer}`) }}
          </option>
        </select>
      </label>

      <label class="inline">
        <input v-model="factsVerified" type="checkbox" />
        <span>{{ t('controlGates.intake.factsVerified') }}</span>
      </label>

      <p v-if="error" class="alert">{{ error }}</p>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !canSubmit">
          {{ saving ? t('controlGates.saving') : t('controlGates.save') }}
        </button>
        <button type="button" @click="open = false">{{ t('controlGates.cancel') }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.gate-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.gate-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background: var(--color-surface);
}

.gate-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}

.gate-form label.inline {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}

.gate-form label em {
  font-size: 0.75rem;
  color: var(--color-black-500);
}

.gate-form select {
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.35rem;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.recorded {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.recorded li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}

.answer {
  font-weight: 600;
}

.answer.present {
  color: var(--color-success-fg);
}

.answer.missing {
  color: var(--color-danger-fg);
}

.answer.not_applicable {
  color: var(--color-black-500);
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

.ok {
  margin: 0;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-success-border);
  border-radius: 0.35rem;
  background: var(--color-success-bg);
  color: var(--color-success-fg);
  font-size: 0.85rem;
}
</style>
