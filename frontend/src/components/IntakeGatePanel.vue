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
  // { key: { ar, en, conditional, covered } }, straight from the type's own
  // matrix. Stage 85 — `covered` means one of the request's own attachments
  // already names this row, so the server derives `present` for it and
  // ignores whatever is sent; the form shows it read-only rather than as an
  // input that cannot change anything.
  requiredDocuments: { type: Object, default: () => ({}) },
  // The stored record, once one exists.
  record: { type: Object, default: null },
  // Why the قيد would be refused right now; null means the gate passes.
  refusal: { type: String, default: null },
  // Stage 84 — who answered it and when. The payload has carried these since
  // Stage 78 and nothing rendered them, so a reader could not tell whose
  // attestation the قيد was resting on.
  recordedBy: { type: Object, default: null },
  recordedAt: { type: String, default: null },
})

const emit = defineEmits(['updated'])

const { t, locale } = useI18n()

const open = ref(false)
const saving = ref(false)
const error = ref('')

const answers = reactive({})
const factsVerified = ref(false)

const documents = computed(() => Object.entries(props.requiredDocuments))

// Stage 85 — the officer is asked only about rows no file answers. After the
// submission rule those are the conditional ones; a file created before it can
// still leave a mandatory row here.
const openDocuments = computed(() => documents.value.filter(([, document]) => !document.covered))
const coveredDocuments = computed(() => documents.value.filter(([, document]) => document.covered))

function label(document) {
  return (locale.value === 'ar' ? document.ar : document.en) || document.ar || document.en
}

const recordedAtLabel = computed(() => (props.recordedAt
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(props.recordedAt))
  : ''))

// Seed the form from whatever is already recorded, so re-opening it shows the
// previous answers rather than a blank slate the officer has to redo.
function seed() {
  const stored = props.record?.documents ?? {}
  for (const [key, document] of documents.value) {
    answers[key] = document.covered ? 'present' : (stored[key] ?? '')
  }
  factsVerified.value = props.record?.facts_verified === true
}

watch(open, (isOpen) => {
  error.value = ''
  if (isOpen) seed()
})

const canSubmit = computed(() => openDocuments.value.every(([key]) => answers[key] !== ''))

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

    <!-- Stage 84 — whose attestation this is. [D] Appendix 19 makes the
         author part of the record, not a detail: the gate is only meaningful
         if a reader can see it was not signed by the file's own submitter. -->
    <p v-if="recordedBy" class="recorded-by">
      {{ t('controlGates.intake.recordedBy', { name: recordedBy.name, at: recordedAtLabel }) }}
    </p>

    <!-- The recorded card, so a later reader sees Appendix 57's own list
         rather than only whether it passed. -->
    <ul v-if="record?.items?.length" class="recorded">
      <li v-for="item in record.items" :key="item.key">
        <span>{{ locale === 'ar' ? item.label_ar : item.label_en }}</span>
        <!-- Stage 85 — an attested `present` and a proven one read
             differently, because they are different claims. -->
        <span :class="['answer', item.answer]">
          {{ item.covered ? t('controlGates.intake.derived') : t(`controlGates.intake.answers.${item.answer}`) }}
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

      <!-- Stage 85 — rows the submitter's own files already answer. Shown so
           the officer can see the whole matrix, never as inputs: the server
           derives these and overrides whatever is sent. -->
      <template v-if="coveredDocuments.length">
        <p class="hint">{{ t('controlGates.intake.derivedHint') }}</p>
        <ul class="derived">
          <li v-for="[key, document] in coveredDocuments" :key="key">
            <span>{{ label(document) }}</span>
            <span class="answer present">{{ t('controlGates.intake.derived') }}</span>
          </li>
        </ul>
      </template>

      <label v-for="[key, document] in openDocuments" :key="key">
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

.derived,
.recorded {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.derived li,
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

.recorded-by {
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
