<script setup>
/**
 * Reusable private-file picker and uploader — Stage 12.
 * Defaults to a request's own attachment endpoint; pass `uploadUrl` to
 * target a different parent (e.g. Stage 59's `/appeals/{id}/attachments`)
 * without duplicating this component.
 *
 * Stage 91 — a request's document is classified by the same question intake
 * asks: which of its type's [D] Appendix 57 rows it answers. Appendix 14's
 * folder is derived from that answer, so it is asked only for مستند آخر, the
 * one answer that leaves a folder genuinely unstated. The server is the
 * enforcement either way; this form only decides what is offered.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import FileDropZone from './FileDropZone.vue'
import { FILE_SECTIONS } from '../lib/fileSections'
import { DOCUMENT_GROUPS, documentCondition, documentLabel } from '../lib/requiredDocuments'

const props = defineProps({
  requestId: { type: [Number, String], default: null },
  uploadUrl: { type: String, default: null },
  // Stage 80 — [D] Appendix 14 classifies a *request*'s documents into
  // thirteen fixed folders. An appeal's attachments live on their own
  // parent, which the appendix already treats as folder 12, so that caller
  // switches this off rather than being asked a question with one answer.
  // Stage 91 — it now switches off the Appendix 57 question too: neither
  // classification describes an appeal's own file.
  requireSection: { type: Boolean, default: true },
})

const targetUrl = computed(() => props.uploadUrl ?? `/requests/${props.requestId}/attachments`)

const emit = defineEmits(['uploaded'])
const { t, locale } = useI18n()
// One row per picked file, each answering its own classification, because
// several documents dropped together are rarely all the same Appendix 57 row.
const rows = ref([])
const documentOptions = ref([])
const outstanding = ref([])
const error = ref('')
const uploading = ref(false)
const progress = ref(0)

const maxBytes = 20 * 1024 * 1024
const acceptedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']

function validate(candidate) {
  const extension = candidate.name.split('.').pop()?.toLowerCase()
  if (!acceptedExtensions.includes(extension)) return t('attachments.invalidType')
  if (candidate.size > maxBytes) return t('attachments.fileTooLarge')
  return ''
}

function isPreviewable(candidate) {
  return candidate.type === 'application/pdf' || candidate.type.startsWith('image/')
}

function choose(files) {
  error.value = ''
  const rejected = []

  for (const file of files) {
    const reason = validate(file)
    if (reason) {
      rejected.push(`${file.name}: ${reason}`)
      continue
    }
    rows.value.push({
      file,
      label: '',
      fileSection: '',
      documentKey: '',
      error: '',
      previewUrl: isPreviewable(file) ? URL.createObjectURL(file) : '',
    })
  }

  if (rejected.length) error.value = rejected.join(' — ')
}

function removeRow(row) {
  if (row.previewUrl) URL.revokeObjectURL(row.previewUrl)
  rows.value = rows.value.filter((candidate) => candidate !== row)
}

// Stage 91 — the Appendix 57 question only means something for a request's
// own file, so the appeals caller (which passes its own uploadUrl and turns
// the classification off) never asks for it.
const classifiesDocument = computed(() => props.requireSection && !props.uploadUrl && !!props.requestId)

// One <optgroup> per Appendix 57 group, in the appendix's own order — the same
// shape the intake picker renders, so a submitter meets one list either way.
const documentOptionGroups = computed(() => DOCUMENT_GROUPS
  .map((group) => ({ group, items: documentOptions.value.filter((doc) => doc.group === group) }))
  .filter((section) => section.items.length > 0))

// مستند آخر is the one answer that leaves Appendix 14's folder unstated, which
// is exactly when it is still a real question.
function needsFileSection(row) {
  return classifiesDocument.value && row.documentKey === 'other'
}

const ready = computed(() => rows.value.length > 0 && rows.value.every((row) =>
  (!classifiesDocument.value || row.documentKey) && (!needsFileSection(row) || row.fileSection)))

function docLabel(doc) {
  const condition = documentCondition(doc, locale.value)

  return condition ? `${documentLabel(doc, locale.value)} (${condition})` : documentLabel(doc, locale.value)
}

/**
 * The matrix to ask about, plus which mandatory rows this file still leaves
 * uncovered — an استكمال upload is only actionable if the uploader is told
 * what is missing. Refetched after every upload, so the outstanding list
 * shrinks as the gap closes. A failure is left silent on purpose: the server
 * refuses an unanswered upload regardless, so a broken lookup must not also
 * break an upload the user can still complete.
 */
async function loadDocumentOptions() {
  if (!classifiesDocument.value) return

  try {
    const { data } = await api.get(`/requests/${props.requestId}/document-options`)
    documentOptions.value = data.data.document_options ?? []
    outstanding.value = data.data.outstanding ?? []
  } catch {
    documentOptions.value = []
    outstanding.value = []
  }
}

// Watched rather than mounted-once: the request workspace is a routed screen,
// so moving between two requests can reuse this component, and a stale matrix
// would offer keys the new request's type does not accept.
watch(() => props.requestId, loadDocumentOptions, { immediate: true })
onBeforeUnmount(() => rows.value.forEach((row) => row.previewUrl && URL.revokeObjectURL(row.previewUrl)))

function uploadError(requestError) {
  return requestError.response?.data?.errors?.file?.[0]
    ?? requestError.response?.data?.errors?.required_document_key?.[0]
    ?? requestError.response?.data?.errors?.file_section?.[0]
    ?? requestError.response?.data?.message
    ?? t('attachments.uploadFailed')
}

/**
 * The endpoint takes one file per call, so the rows go one at a time. A row
 * that uploads leaves the list; a refused one stays with its own reason, so
 * one bad scan never costs the files picked with it. `uploaded` fires only
 * once the list is empty: both callers close on it, which mid-batch would
 * hide the rows still waiting and the reasons the server gave.
 */
async function upload() {
  if (!ready.value) return

  uploading.value = true
  const uploaded = []

  for (const [index, row] of [...rows.value].entries()) {
    progress.value = Math.round((index / rows.value.length) * 100)
    row.error = ''
    const form = new FormData()
    form.append('file', row.file)
    if (row.label.trim()) form.append('label', row.label.trim())
    if (classifiesDocument.value) form.append('required_document_key', row.documentKey)
    // Only مستند آخر carries a folder: a named row's own folder is derived
    // server-side, and sending one would be an answer that cannot be honoured.
    if (needsFileSection(row)) form.append('file_section', row.fileSection)

    try {
      const { data } = await api.post(targetUrl.value, form)
      uploaded.push(data.data)
      removeRow(row)
    } catch (requestError) {
      row.error = uploadError(requestError)
    }
  }

  uploading.value = false
  progress.value = 0
  await loadDocumentOptions()
  if (!rows.value.length) emit('uploaded', uploaded)
}
</script>

<template>
  <form class="file-upload" @submit.prevent="upload">
    <FileDropZone accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" :disabled="uploading" @files="choose" />
    <p class="hint">{{ t('attachments.acceptedHint') }}</p>

    <!-- What this file still owes, so completing نواقص is actionable. -->
    <p v-if="classifiesDocument && outstanding.length" class="hint outstanding">
      {{ t('attachments.outstanding') }}
      {{ outstanding.map((doc) => documentLabel(doc, locale)).join('، ') }}
    </p>

    <article v-for="(row, index) in rows" :key="`${row.file.name}-${index}`" class="row">
      <div class="row-head">
        <!-- A new tab, not an inline frame: several frames would bury the form. -->
        <a v-if="row.previewUrl" class="name ltr" :href="row.previewUrl" target="_blank" rel="noopener">{{ row.file.name }}</a>
        <span v-else class="name ltr">{{ row.file.name }}</span>
        <button class="ghost" type="button" :disabled="uploading" @click="removeRow(row)">{{ t('intake.removeFile') }}</button>
      </div>

      <!--
        Stage 91 — the same question intake asks, so a document supplied during
        استكمال النواقص names the [D] Appendix 57 row it would have named at
        intake, and the officer's completeness gate can read it.
      -->
      <label v-if="classifiesDocument">
        {{ t('attachments.documentType') }}
        <select v-model="row.documentKey" :disabled="uploading">
          <option value="" disabled>{{ t('attachments.chooseDocumentType') }}</option>
          <optgroup
            v-for="section in documentOptionGroups"
            :key="section.group"
            :label="t(`intake.requiredDocuments.groups.${section.group}`)"
          >
            <option v-for="doc in section.items" :key="doc.key" :value="doc.key">{{ docLabel(doc) }}</option>
          </optgroup>
          <option value="other">{{ t('attachments.otherDocument') }}</option>
        </select>
      </label>

      <!-- Only مستند آخر leaves Appendix 14's folder unanswered. -->
      <label v-if="needsFileSection(row)">
        {{ t('attachments.fileSection') }}
        <select v-model="row.fileSection" :disabled="uploading">
          <option value="" disabled>{{ t('attachments.chooseFileSection') }}</option>
          <option v-for="section in FILE_SECTIONS" :key="section" :value="section">
            {{ t(`fileSections.${section}`) }}
          </option>
        </select>
      </label>
      <p v-if="needsFileSection(row)" class="hint">{{ t('attachments.fileSectionHint') }}</p>

      <label>
        {{ t('attachments.label') }}
        <input v-model="row.label" type="text" maxlength="255" :disabled="uploading" />
      </label>
      <p v-if="row.error" class="error" role="alert">{{ row.error }}</p>
    </article>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-if="uploading" class="progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
      <span :style="{ inlineSize: `${progress}%` }" />
    </div>
    <button class="primary" type="submit" :disabled="uploading || !ready">
      {{ uploading ? t('attachments.uploading') : t('attachments.upload') }}
    </button>
  </form>
</template>

<style scoped>
.file-upload { display: grid; gap: var(--space-3); }
.file-upload label { display: grid; gap: .3rem; color: var(--color-black-700); font-size: var(--text-base); }
.file-upload input, .file-upload select { min-inline-size: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: inherit; font: inherit; }
.hint { margin: -.4rem 0 0; color: var(--color-muted); font-size: var(--text-sm); }
.outstanding { color: var(--color-warning-fg); }
.row { display: grid; gap: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.row-head { display: flex; gap: var(--space-2); align-items: center; justify-content: space-between; }
.name { min-inline-size: 0; overflow-wrap: anywhere; font-weight: 600; }
.error { margin: 0; color: var(--color-danger-fg); font-size: var(--text-sm); }
.primary { justify-self: start; }
.progress { block-size: .4rem; overflow: hidden; border-radius: var(--radius-full); background: var(--color-border); }
.progress span { display: block; block-size: 100%; background: var(--color-primary); transition: inline-size .15s ease; }
</style>
