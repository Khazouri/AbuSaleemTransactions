<script setup>
// Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد), and since Stage 98 HR's
// تجهيز الملف الوظيفي too: rows 3 and 4 of Appendix 6 are two parties asking
// the same question of the same file at two moments. The four props
// endpoint/attestationField/grant/copy are what differs, and all default to
// Stage 78's values.
//
// Each row is a file actually attached (user decision 2026-10-09). The
// reviewer opens the file's full preview, the server records that open, the
// row's «تمت المعاينة» box ticks itself, and only then can it be judged
// approved or not approved. One button at the foot submits the whole card.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { documentLabel } from '../lib/requiredDocuments'
import AttachmentLink from './AttachmentLink.vue'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // The request's own attachments, and the server's list of which of them are
  // under review ({ id, previewed } — a rejected file that a newer upload of
  // the same document replaced is left out).
  attachments: { type: Array, default: () => [] },
  review: { type: Array, default: () => [] },
  // The stored record, once one exists.
  record: { type: Object, default: null },
  // Why the gated hop would be refused right now; null means the gate passes.
  refusal: { type: String, default: null },
  // Stage 84 — whose attestation the hop rests on, and when.
  recordedBy: { type: Object, default: null },
  recordedAt: { type: String, default: null },
  endpoint: { type: String, default: 'intake-gate' },
  attestationField: { type: String, default: 'facts_verified' },
  grant: { type: String, default: 'notes_attachments.edit' },
  copy: { type: String, default: 'intake' },
  // The gates tab shows the card only; its form lives in the wizard's Checks step.
  readonly: { type: Boolean, default: false },
  // HR's card only: the grade data the employee stated at intake, which HR
  // confirms against the organisation's records before registering.
  grade: { type: Object, default: null },
  // HR's card only: the «مطلوب» rows no attached file answers yet.
  missing: { type: Array, default: () => [] },
})

const emit = defineEmits(['updated'])

const { t, locale } = useI18n()

const VERDICTS = ['approved', 'not_approved']

const saving = ref(false)
const error = ref('')

const verdicts = reactive({})
const previewed = reactive({})
const attested = ref(false)
const gradeVerified = ref(false)

// What HR confirmed is frozen on the record; before that, what the employee stated.
const gradeShown = computed(() => props.record?.grade ?? props.grade)

// Every copy key this panel reads is namespaced by `copy`, so the two cards
// cannot share a sentence by accident.
const c = (key, params) => t(`controlGates.${props.copy}.${key}`, params ?? {})

const files = computed(() => {
  const byId = new Map(props.attachments.map((attachment) => [attachment.id, attachment]))
  return props.review.map((row) => byId.get(row.id)).filter(Boolean)
})

const recordedAtLabel = computed(() => (props.recordedAt
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(props.recordedAt))
  : ''))

// A card recorded before 2026-10-09 judged Appendix 57's rows, not files, so
// its items carry a label and an answer instead of a name and a verdict.
function itemLabel(item) {
  return item.name ?? (locale.value === 'ar' ? item.label_ar : item.label_en)
}
function itemAnswer(item) {
  return item.verdict ? t(`controlGates.review.${item.verdict}`) : c(`answers.${item.answer}`)
}

// Seed from what the server knows, so re-opening the wizard shows the
// previous verdicts rather than a blank card. Read from `items`, not
// `documents`: a JSON resource re-indexes an array whose keys are all numeric,
// so `documents` (keyed by attachment id) arrives as a plain list.
function seed() {
  const stored = Object.fromEntries((props.record?.items ?? [])
    .filter((item) => item.attachment_id)
    .map((item) => [item.attachment_id, item.verdict]))
  for (const row of props.review) {
    previewed[row.id] = row.previewed
    verdicts[row.id] = stored[row.id] ?? ''
  }
  attested.value = props.record?.[props.attestationField] === true
  gradeVerified.value = props.record?.grade_verified === true
}
watch(() => [props.review, props.record], seed, { immediate: true })

const canSubmit = computed(() => files.value.every((file) => previewed[file.id] && verdicts[file.id]))

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
    const { data } = await api.patch(`/requests/${props.requestId}/${props.endpoint}`, {
      documents: Object.fromEntries(files.value.map((file) => [file.id, verdicts[file.id]])),
      [props.attestationField]: attested.value,
      ...(props.grade ? { grade_verified: gradeVerified.value } : {}),
    })
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
    <p v-if="refusal" class="alert warning">{{ refusal }}</p>
    <p v-else class="alert success">{{ c('passed') }}</p>

    <!-- Stage 84 — whose attestation this is. [D] Appendix 19 makes the
         author part of the record: the gate is only meaningful if a reader can
         see it was not signed by the file's own submitter. -->
    <p v-if="recordedBy" class="recorded-by">
      {{ c('recordedBy', { name: recordedBy.name, at: recordedAtLabel }) }}
    </p>

    <ul v-if="readonly && record?.items?.length" class="checklist">
      <li v-for="item in record.items" :key="item.attachment_id ?? item.key">
        <span dir="auto">{{ itemLabel(item) }}</span>
        <span :class="['answer', item.verdict ?? item.answer]">{{ itemAnswer(item) }}</span>
      </li>
    </ul>

    <dl v-if="grade && gradeShown" class="grade-data">
      <dt>{{ c('gradeData') }}</dt>
      <dd>
        {{ t('intake.decisionGrade') }}: {{ gradeShown.decision_grade ?? '—' }}
        · {{ t('intake.entitledGrade') }}: {{ gradeShown.entitled_grade ?? '—' }}
        · {{ t('intake.gradeDate') }}: {{ gradeShown.grade_date ?? '—' }}
      </dd>
      <dd v-if="record" :class="['answer', record.grade_verified ? 'present' : 'missing']">
        {{ record.grade_verified ? c('gradeConfirmed') : c('gradeUnconfirmed') }}
      </dd>
    </dl>

    <div v-if="missing.length" class="missing">
      <p class="hint">{{ t('controlGates.review.missingRequired') }}</p>
      <ul class="checklist">
        <li v-for="doc in missing" :key="doc.key">
          <span dir="auto">{{ documentLabel(doc, locale) }}</span>
          <span class="answer missing">{{ t('controlGates.review.notAttached') }}</span>
        </li>
      </ul>
    </div>

    <form v-if="!readonly" v-can="grant" class="gate-form" @submit.prevent="submit">
      <p class="hint">{{ c('formNote') }}</p>
      <p v-if="!files.length" class="hint">{{ t('controlGates.review.noFiles') }}</p>
      <template v-else>
        <p class="hint">{{ t('controlGates.review.previewFirst') }}</p>
        <ul class="review-list">
          <li v-for="file in files" :key="file.id">
            <AttachmentLink :attachment="file" @previewed="previewed[file.id] = true" />
            <label class="inline">
              <input type="checkbox" :checked="previewed[file.id]" disabled />
              <span>{{ t('controlGates.review.previewed') }}</span>
            </label>
            <fieldset class="verdicts" :disabled="!previewed[file.id]">
              <legend class="sr-only">{{ file.original_name }}</legend>
              <label v-for="verdict in VERDICTS" :key="verdict" class="inline">
                <input v-model="verdicts[file.id]" type="radio" :name="`verdict-${copy}-${file.id}`" :value="verdict" />
                <span>{{ t(`controlGates.review.${verdict}`) }}</span>
              </label>
            </fieldset>
          </li>
        </ul>
        <p class="hint">{{ t('controlGates.review.replaced') }}</p>
      </template>

      <label class="inline">
        <input v-model="attested" type="checkbox" />
        <span>{{ c('attestation') }}</span>
      </label>

      <label v-if="grade" class="inline">
        <input v-model="gradeVerified" type="checkbox" />
        <span>{{ c('gradeAttestation') }}</span>
      </label>

      <p v-if="error" class="alert warning">{{ error }}</p>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !canSubmit">
          {{ saving ? t('controlGates.saving') : t('controlGates.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
