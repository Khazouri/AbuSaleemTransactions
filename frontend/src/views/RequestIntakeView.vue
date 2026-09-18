<script setup>
/** Stage 13 — single-submit request intake, including private attachments. */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'
// Stage 72 — [D] Appendix 57's grouped document matrix, shared with the
// request workspace so both screens read the same list the same way.
import { documentCondition, documentLabel } from '../lib/requiredDocuments'
// Stage 83 — [D] Appendix 16 classifies a new request raised after an
// earlier one on the same subject; the two it routes elsewhere are greyed out.
import { PRIOR_RELATIONS } from '../lib/lifecycle'
// [D] Appendix 57 — a submitter names which of the chosen type's recommended
// documents each file provides. The options, and the key each one is stored
// under, come from the server (see RequestType::documentOptions()); the
// Appendix 14 folder is derived from the answer rather than asked separately.
import { DOCUMENT_GROUPS } from '../lib/requiredDocuments'

const { t, locale } = useI18n()
const auth = useAuthStore()
const options = ref({ departments: [], types: [] })
const form = ref(blankForm())
const files = ref([])
const loadingOptions = ref(false)
const submitting = ref(false)
const errors = ref({})
const error = ref('')
const created = ref(null)
// Stage 83 — Appendix 16's own search, run before the form is submitted so
// the employee is shown the open file rather than a refusal after the fact.
const duplicate = ref(null)
const duplicateChecking = ref(false)

// Stage 88 — [G]'s sub-step 5: the form is read back before it is sent, and
// submission happens from there rather than from the form itself.
const step = ref('form')

/*
 * Stage 88 — a draft, so an intake can be put down and picked up.
 *
 * Everything below was held in plain refs, so a refresh lost every typed field
 * and every chosen file. Fields are autosaved and files upload as they are
 * chosen — a browser cannot reconstruct a File across a refresh, which is also
 * why the review step can only show a draft's files back once they are on the
 * server.
 *
 * Gated on `request_intake.edit`, which R03/R04/R06 do not hold. So this is an
 * ADDED capability, not a new requirement: without it the screen keeps its
 * original in-memory behaviour and nobody loses the ability to file.
 */
const canDraft = computed(() => auth.can('request_intake', 'edit'))
const draftId = ref(null)
const drafts = ref([])
const draftSaving = ref(false)
const draftError = ref('')
const resuming = ref(false)
let saveTimer = null

const acceptedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']
const maxBytes = 20 * 1024 * 1024
// Mirrors StoreRequest's own `attachments` cap; see the note in chooseFiles().
const maxFiles = 30
const isBusy = computed(() => loadingOptions.value || submitting.value || resuming.value)
// A document answer is required per attachment, so an unanswered row blocks
// the whole submission rather than being dropped from it.
const unclassifiedFiles = computed(() => files.value.some((attachment) => !attachment.required_document_key))
const selectedType = computed(() => options.value.types.find(
  (type) => String(type.id) === String(form.value.request_type_id),
))
const selectedDepartment = computed(() => options.value.departments.find(
  (department) => String(department.id) === String(form.value.department_id),
))
// The same matrix as the checklist above, but keyed — one <optgroup> per
// Appendix 57 group, in the appendix's own order.
const documentOptionGroups = computed(() => DOCUMENT_GROUPS
  .map((group) => ({
    group,
    items: (selectedType.value?.document_options ?? []).filter((doc) => doc.group === group),
  }))
  .filter((section) => section.items.length > 0))

// Which recommended documents the attached files already cover, so the
// checklist stops being a list to read and becomes a list to satisfy.
const coveredDocumentKeys = computed(
  () => new Set(files.value.map((attachment) => attachment.required_document_key).filter(Boolean)),
)

// Stage 85 — [D] Appendix 57 binds now. A row the appendix states without an
// inline qualifier must be covered by one of this submission's files; a row
// that carries one ("بحسب الموضوع"، "عند الحاجة") stays optional. Mirrors the
// server's own rule (DocumentCompletenessService) so the form refuses what the
// endpoint would refuse, rather than posting into a 422 — the server stays the
// enforcement either way.
function isMandatory(doc) {
  return !doc.condition
}

const uncoveredMandatory = computed(() => (selectedType.value?.document_options ?? [])
  .filter((doc) => isMandatory(doc) && !coveredDocumentKeys.value.has(doc.key)))

// What the form itself would refuse, so the review step can be reached only
// from a state the server would accept — and so "why is this disabled?" is
// answered on the row that caused it.
const formIncomplete = computed(() => !form.value.title
  || !form.value.department_id
  || !form.value.request_type_id
  || (selectedType.value?.decision_grade_threshold != null && form.value.decision_grade === '')
  || unclassifiedFiles.value
  || uncoveredMandatory.value.length > 0)

function blankForm() {
  return { title: '', description: '', department_id: '', request_type_id: '', decision_grade: '', prior_relation: '' }
}

/** Appendix 16 searches "برقم الموظف وموضوع المعاملة" — the type is the subject. */
async function checkDuplicates() {
  duplicate.value = null
  form.value.prior_relation = ''
  // A document key belongs to one type's matrix, so a type change makes every
  // answer meaningless — clearing beats silently posting a key the server
  // will (correctly) refuse as belonging to another type.
  files.value.forEach((attachment) => {
    attachment.required_document_key = ''
    // A draft's answers live on the server, so clearing them has to reach it
    // too, or a resumed draft would come back holding keys the form has
    // already discarded.
    saveAttachment(attachment)
  })
  if (!form.value.request_type_id) return
  duplicateChecking.value = true
  try {
    const { data } = await api.get('/requests/duplicate-check', {
      params: { request_type_id: form.value.request_type_id },
    })
    duplicate.value = data.data
  } catch {
    // A failed lookup must not block intake: the server refuses a genuine
    // duplicate on its own, so this panel is a courtesy, not the gate.
    duplicate.value = null
  } finally {
    duplicateChecking.value = false
  }
}

function name(item) {
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}

function docLabel(doc) {
  return documentLabel(doc, locale.value)
}

function docCondition(doc) {
  return documentCondition(doc, locale.value)
}

/** The Appendix 57 row an attached file declares, for the review step. */
function declaredDocument(attachment) {
  if (!attachment.required_document_key) return t('attachments.chooseDocumentType')
  if (attachment.required_document_key === 'other') return t('attachments.otherDocument')
  const doc = (selectedType.value?.document_options ?? [])
    .find((entry) => entry.key === attachment.required_document_key)

  return doc ? docLabel(doc) : attachment.required_document_key
}

// ---------------------------------------------------------------- drafts

/** Create the draft on demand, so an untouched form leaves nothing behind. */
async function ensureDraft() {
  if (!canDraft.value) return null
  if (draftId.value) return draftId.value

  const { data } = await api.post('/requests/drafts', draftPayload())
  draftId.value = data.data.id

  return draftId.value
}

function draftPayload() {
  return {
    title: form.value.title || null,
    description: form.value.description || null,
    department_id: form.value.department_id || null,
    request_type_id: form.value.request_type_id || null,
    decision_grade: form.value.decision_grade === '' ? null : form.value.decision_grade,
    prior_relation: form.value.prior_relation || null,
  }
}

/**
 * Autosave, debounced.
 *
 * A refresh is not a deliberate "put down", so there is no save button to
 * forget to press. Nothing is created until something has actually been typed:
 * opening the screen and leaving should not litter the resume list.
 */
function scheduleSave() {
  if (!canDraft.value || created.value || resuming.value) return
  const touched = Object.values(draftPayload()).some((value) => value !== null && value !== '')
  if (!touched && !draftId.value) return

  clearTimeout(saveTimer)
  saveTimer = setTimeout(saveDraft, 800)
}

async function saveDraft() {
  draftSaving.value = true
  draftError.value = ''
  try {
    const id = await ensureDraft()
    if (id) await api.put(`/requests/drafts/${id}`, draftPayload())
  } catch {
    // Deliberately soft: a failed autosave must not block an intake the
    // employee can still complete and submit in this sitting.
    draftError.value = t('intake.draft.saveFailed')
  } finally {
    draftSaving.value = false
  }
}

async function loadDrafts() {
  if (!canDraft.value) return
  try {
    const { data } = await api.get('/requests/drafts')
    drafts.value = (data.data ?? []).filter((draft) => draft.id !== draftId.value)
  } catch {
    drafts.value = []
  }
}

function draftTitle(draft) {
  return draft.payload?.title || t('intake.draft.untitled')
}

async function resumeDraft(id) {
  resuming.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/requests/drafts/${id}`)
    const draft = data.data
    draftId.value = draft.id
    form.value = { ...blankForm(), ...compactPayload(draft.payload ?? {}) }
    files.value = (draft.attachments ?? []).map(toDraftRow)
    drafts.value = drafts.value.filter((entry) => entry.id !== id)
    step.value = 'form'
    if (form.value.request_type_id) await checkDuplicates()
  } catch {
    error.value = t('intake.draft.resumeFailed')
  } finally {
    resuming.value = false
  }
}

/** Nulls come back from the server; the form's own empty value is ''. */
function compactPayload(payload) {
  return Object.fromEntries(
    Object.entries(payload).map(([key, value]) => [key, value ?? '']),
  )
}

function toDraftRow(attachment) {
  return {
    id: attachment.id,
    file: null,
    name: attachment.original_name,
    size_bytes: attachment.size_bytes,
    preview_url: attachment.preview_url,
    label: attachment.label ?? '',
    required_document_key: attachment.required_document_key ?? '',
  }
}

async function discardDraft() {
  if (!draftId.value) return
  const id = draftId.value
  clearTimeout(saveTimer)
  draftId.value = null
  try {
    await api.delete(`/requests/drafts/${id}`)
  } catch {
    draftError.value = t('intake.draft.discardFailed')
  }
  form.value = blankForm()
  files.value = []
  duplicate.value = null
  step.value = 'form'
  await loadDrafts()
}

/** Persist one already-uploaded file's two answers; a no-op in memory. */
async function saveAttachment(attachment) {
  if (!attachment.id || !draftId.value) return
  try {
    await api.patch(`/requests/drafts/${draftId.value}/attachments/${attachment.id}`, {
      label: attachment.label || null,
      required_document_key: attachment.required_document_key || null,
    })
  } catch {
    draftError.value = t('intake.draft.saveFailed')
  }
}

// ------------------------------------------------------------ attachments

async function chooseFiles(event) {
  const selected = Array.from(event.target.files ?? [])
  const invalid = selected.find((file) => {
    const extension = file.name.split('.').pop()?.toLowerCase()
    return !acceptedExtensions.includes(extension) || file.size > maxBytes
  })

  if (invalid) {
    error.value = t(!acceptedExtensions.includes(invalid.name.split('.').pop()?.toLowerCase())
      ? 'attachments.invalidType'
      : 'attachments.fileTooLarge')
    return
  }

  // Stage 85 raised the server's cap from 10 to 30: TRNS alone states thirteen
  // documents unconditionally, so the old cap would have refused a submission
  // for documents it also refused permission to attach.
  if (files.value.length + selected.length > maxFiles) {
    error.value = t('intake.tooManyFiles')
    return
  }

  error.value = ''
  event.target.value = ''

  if (!canDraft.value) {
    // Wrap only what was just picked: re-wrapping the whole list would nest
    // each existing row inside a second wrapper and drop its label and its
    // classification, so a second selection would silently break the first.
    files.value = [...files.value, ...selected.map((file) => ({
      id: null,
      file,
      name: file.name,
      size_bytes: file.size,
      preview_url: null,
      label: '',
      required_document_key: '',
    }))]

    return
  }

  // Uploaded as they are chosen, because that is what makes "every chosen
  // file" survive a refresh — the half of this stage a client-side draft
  // could never deliver.
  draftSaving.value = true
  try {
    const id = await ensureDraft()

    for (const file of selected) {
      const payload = new FormData()
      payload.append('file', file)
      const { data } = await api.post(`/requests/drafts/${id}/attachments`, payload)
      files.value = [...files.value, toDraftRow(data.data)]
    }
  } catch (uploadError) {
    error.value = uploadError.response?.data?.message ?? t('intake.draft.uploadFailed')
  } finally {
    draftSaving.value = false
  }
}

async function removeFile(index) {
  const [removed] = files.value.splice(index, 1)
  if (!removed?.id || !draftId.value) return
  try {
    await api.delete(`/requests/drafts/${draftId.value}/attachments/${removed.id}`)
  } catch {
    draftError.value = t('intake.draft.removeFailed')
  }
}

async function loadOptions() {
  loadingOptions.value = true
  error.value = ''
  try {
    const { data } = await api.get('/requests/intake-options')
    options.value = data.data ?? options.value
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('intake.loadFailed')
  } finally {
    loadingOptions.value = false
  }
}

// ------------------------------------------------------------- submission

function review() {
  errors.value = {}
  error.value = ''
  step.value = 'review'
}

async function submit() {
  submitting.value = true
  errors.value = {}
  error.value = ''
  // A pending autosave would otherwise land after the draft is deleted.
  clearTimeout(saveTimer)

  try {
    // One endpoint either way. A draft supplies the FILES ONLY — every field
    // still travels in this payload and is validated there, so what is filed
    // is what the review step just showed, never a stale draft.
    const { data } = draftId.value
      ? await api.post('/requests', { ...draftPayload(), draft_id: draftId.value })
      : await api.post('/requests', inlinePayload())

    created.value = data.data
    draftId.value = null
    await loadDrafts()
  } catch (requestError) {
    errors.value = requestError.response?.data?.errors ?? {}
    error.value = requestError.response?.data?.message ?? t('intake.submitFailed')
    // Back to the form: every message this endpoint returns is about a field
    // that lives there, so leaving the reader on the review step would show
    // them an error with nothing to correct.
    step.value = 'form'
  } finally {
    submitting.value = false
  }
}

function inlinePayload() {
  const payload = new FormData()
  payload.append('title', form.value.title)
  payload.append('description', form.value.description)
  payload.append('department_id', form.value.department_id)
  payload.append('request_type_id', form.value.request_type_id)
  if (form.value.decision_grade !== '') payload.append('decision_grade', form.value.decision_grade)
  if (form.value.prior_relation !== '') payload.append('prior_relation', form.value.prior_relation)
  files.value.forEach(({ file, label, required_document_key: documentKey }, index) => {
    payload.append(`attachments[${index}][file]`, file)
    if (label.trim()) payload.append(`attachments[${index}][label]`, label.trim())
    payload.append(`attachments[${index}][required_document_key]`, documentKey)
  })

  return payload
}

function startAnother() {
  form.value = blankForm()
  files.value = []
  errors.value = {}
  error.value = ''
  created.value = null
  duplicate.value = null
  draftId.value = null
  step.value = 'form'
  loadDrafts()
}

watch(form, scheduleSave, { deep: true })

onMounted(async () => {
  await loadOptions()
  await loadDrafts()
})
</script>

<template>
  <section class="intake">
    <div class="heading">
      <div>
        <h2>{{ t('intake.title') }}</h2>
        <p>{{ t('intake.subtitle') }}</p>
      </div>
    </div>

    <section v-if="created" class="card success" role="status">
      <h3>{{ t('intake.successTitle') }}</h3>
      <p>{{ t('intake.successBody') }}</p>
      <strong class="reference ltr">{{ created.intake_receipt_number }}</strong>
      <!-- [D] Art. 15: handing a request to the direct manager is explicitly
           not a قيد, so the screen says so rather than letting the receipt
           read as the committee reference it is not. The copy also promises
           the notice the submitter gets when the receiving body registers the
           file and this number is superseded — see
           RequestReferenceAssignedNotification. -->
      <p class="receipt-notice">{{ t("intake.receiptNotice") }}</p>
      <div class="actions">
        <button class="primary" type="button" @click="startAnother">{{ t('intake.createAnother') }}</button>
        <RouterLink class="ghost link-button" :to="{ name: 'requests' }">{{ t('intake.viewQueue') }}</RouterLink>
      </div>
    </section>

    <template v-else>
      <!-- Stage 88 — the drafts this employee can pick back up. Shown above a
           blank form rather than resumed automatically: silently reopening a
           half-filled intake somebody had set aside is worse than offering it. -->
      <section v-if="canDraft && drafts.length" class="card drafts">
        <h3>{{ t('intake.draft.resumeTitle') }}</h3>
        <ul>
          <li v-for="draft in drafts" :key="draft.id">
            <span class="draft-title">{{ draftTitle(draft) }}</span>
            <span class="draft-meta">
              {{ t('intake.draft.fileCount', { count: draft.attachments_count ?? 0 }) }}
              · {{ new Date(draft.updated_at).toLocaleString(locale === 'ar' ? 'ar-LY' : 'en-GB') }}
            </span>
            <button class="ghost" type="button" :disabled="isBusy" @click="resumeDraft(draft.id)">
              {{ t('intake.draft.resume') }}
            </button>
          </li>
        </ul>
      </section>

      <form v-show="step === 'form'" class="card form" @submit.prevent="review">
        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-if="loadingOptions || resuming" class="state">{{ t('common.loading') }}</p>

        <!-- Deliberately quiet: an autosave notice that shouts would be on
             screen for most of the time the form is being filled. -->
        <p v-if="canDraft" class="draft-state">
          <span v-if="draftSaving">{{ t('intake.draft.saving') }}</span>
          <span v-else-if="draftId">{{ t('intake.draft.saved') }}</span>
          <span v-else>{{ t('intake.draft.autosaveHint') }}</span>
          <button v-if="draftId" class="linkish" type="button" @click="discardDraft">
            {{ t('intake.draft.discard') }}
          </button>
        </p>
        <p v-if="draftError" class="alert" role="alert">{{ draftError }}</p>

        <fieldset :disabled="isBusy">
          <legend>{{ t('intake.basicData') }}</legend>
          <div class="grid">
            <label class="wide">
              {{ t('intake.subject') }}
              <input v-model="form.title" type="text" maxlength="255" required />
              <small v-if="errors.title">{{ errors.title[0] }}</small>
            </label>
            <label>
              {{ t('requests.department') }}
              <select v-model="form.department_id" required>
                <option disabled value="">{{ t('intake.chooseDepartment') }}</option>
                <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ name(department) }}</option>
              </select>
              <small v-if="errors.department_id">{{ errors.department_id[0] }}</small>
            </label>
            <label>
              {{ t('requests.type') }}
              <select v-model="form.request_type_id" required @change="checkDuplicates">
                <option disabled value="">{{ t('intake.chooseType') }}</option>
                <option v-for="type in options.types" :key="type.id" :value="type.id">{{ name(type) }}</option>
              </select>
              <small v-if="errors.request_type_id">{{ errors.request_type_id[0] }}</small>
            </label>
            <!-- Stage 83 — [D] Appendix 16. An open file on the same subject is
                 shown before the form is filled: "لا تنشأ معاملة جديدة، بل تلحق
                 المستندات بالمعاملة القائمة". -->
            <div v-if="duplicate?.open_prior" class="wide alert" role="alert">
              {{ t('lifecycle.duplicate.openPrior', { title: duplicate.open_prior.title }) }}
            </div>
            <label v-else-if="duplicate?.prior_requests?.length" class="wide">
              {{ t('lifecycle.duplicate.classify') }}
              <select v-model="form.prior_relation" required>
                <option disabled value="">{{ t('lifecycle.duplicate.choose') }}</option>
                <option
                  v-for="relation in PRIOR_RELATIONS"
                  :key="relation"
                  :value="relation"
                  :disabled="duplicate.relations.find((entry) => entry.code === relation)?.redirected"
                >
                  {{ t(`lifecycle.duplicate.relations.${relation}`) }}
                </option>
              </select>
              <small>{{ t('lifecycle.duplicate.redirectedNote') }}</small>
            </label>
            <p v-else-if="duplicateChecking" class="wide state">{{ t('common.loading') }}</p>
            <label>
              {{ t('intake.decisionGrade') }}
              <input
                v-model="form.decision_grade"
                type="number"
                min="1"
                max="100"
                :required="selectedType?.decision_grade_threshold != null"
              />
              <small class="field-hint">
                {{ t('intake.decisionGradeHint', { threshold: selectedType?.decision_grade_threshold ?? '—' }) }}
              </small>
              <small v-if="errors.decision_grade">{{ errors.decision_grade[0] }}</small>
            </label>
            <label class="wide">
              {{ t('intake.description') }}
              <textarea v-model="form.description" rows="5" />
              <small v-if="errors.description">{{ errors.description[0] }}</small>
            </label>
          </div>
        </fieldset>

        <!--
          Stage 72 — [D] Appendix 57's document matrix for the chosen type, grouped
          by the appendix's own أساسية مشتركة / خاصة بالنوع split.

          Stage 85 — no longer merely informational: a row the appendix states
          without an inline qualifier has to be covered by one of the attached
          files before the form will submit, and the server refuses it either way.
        -->
        <fieldset v-if="documentOptionGroups.length" class="checklist" :disabled="isBusy">
          <legend>{{ t('intake.requiredDocuments.title') }}</legend>
          <p class="hint">{{ t('intake.requiredDocuments.hint') }}</p>
          <p class="hint">{{ t('intake.requiredDocuments.mandatoryHint') }}</p>
          <div v-for="section in documentOptionGroups" :key="section.group" class="doc-group">
            <h3>{{ t(`intake.requiredDocuments.groups.${section.group}`) }}</h3>
            <ul>
              <li
                v-for="(doc, index) in section.items"
                :key="index"
                :class="{
                  covered: coveredDocumentKeys.has(doc.key),
                  outstanding: isMandatory(doc) && !coveredDocumentKeys.has(doc.key),
                }"
              >
                <span aria-hidden="true" class="tick">{{ coveredDocumentKeys.has(doc.key) ? '✓' : '•' }}</span>
                {{ docLabel(doc) }}
                <!-- Appendix 57 states this row without a qualifier, so it binds. -->
                <span v-if="isMandatory(doc)" class="doc-required">({{ t('intake.requiredDocuments.mandatory') }})</span>
                <span v-if="docCondition(doc)" class="doc-condition">({{ docCondition(doc) }})</span>
                <span v-if="coveredDocumentKeys.has(doc.key)" class="sr-only">{{ t('intake.requiredDocuments.attached') }}</span>
              </li>
            </ul>
          </div>
        </fieldset>

        <fieldset :disabled="isBusy">
          <legend>{{ t('attachments.title') }}</legend>
          <p class="hint">{{ t('attachments.acceptedHint') }}</p>
          <input class="file-input" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="chooseFiles" />
          <small v-if="errors.attachments">{{ errors.attachments[0] }}</small>
          <p class="hint">{{ t('attachments.documentTypeHint') }}</p>

          <div v-if="files.length" class="files">
            <article v-for="(attachment, index) in files" :key="attachment.id ?? `${attachment.name}-${index}`" class="file-row">
              <span class="file-name ltr">{{ attachment.name }}</span>
              <select
                v-model="attachment.required_document_key"
                :aria-label="t('attachments.documentType')"
                @change="saveAttachment(attachment)"
              >
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
              <input
                v-model="attachment.label"
                type="text"
                :placeholder="t('attachments.label')"
                maxlength="255"
                @blur="saveAttachment(attachment)"
              />
              <button class="ghost" type="button" @click="removeFile(index)">{{ t('intake.removeFile') }}</button>
              <small v-if="errors[`attachments.${index}.required_document_key`]" class="row-error">
                {{ errors[`attachments.${index}.required_document_key`][0] }}
              </small>
            </article>
          </div>
        </fieldset>

        <p v-if="unclassifiedFiles" class="hint">{{ t('intake.classifyFiles') }}</p>
        <!-- Stage 85 — the outstanding rows by name, so "why can I not submit?"
             is answered on the form rather than by a server refusal. -->
        <p v-if="selectedType && uncoveredMandatory.length" class="alert" role="alert">
          {{ t('intake.requiredDocuments.uncovered') }}
          <span class="outstanding-list">{{ uncoveredMandatory.map(docLabel).join('، ') }}</span>
        </p>
        <div class="actions">
          <!-- [G] sub-step 5 — this no longer submits. The intake is read back
               first, and sent from there. -->
          <button
            v-can="'request_intake.add'"
            class="primary"
            type="submit"
            :disabled="isBusy || formIncomplete"
          >{{ t('intake.review.open') }}</button>
        </div>
      </form>

      <!-- Stage 88 — [G]'s «مراجعة البيانات قبل الإرسال»: every entered value
           read back, and every attached file beside the Appendix 57 row it
           declares. Kept mounted alongside the form (v-show above) so stepping
           back does not re-create the component and lose an in-memory File. -->
      <section v-if="step === 'review'" class="card review">
        <h3>{{ t('intake.review.title') }}</h3>
        <p class="hint">{{ t('intake.review.hint') }}</p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <dl class="review-grid">
          <div><dt>{{ t('intake.subject') }}</dt><dd>{{ form.title }}</dd></div>
          <div><dt>{{ t('requests.department') }}</dt><dd>{{ selectedDepartment ? name(selectedDepartment) : '—' }}</dd></div>
          <div><dt>{{ t('requests.type') }}</dt><dd>{{ selectedType ? name(selectedType) : '—' }}</dd></div>
          <div><dt>{{ t('intake.decisionGrade') }}</dt><dd>{{ form.decision_grade || '—' }}</dd></div>
          <div v-if="form.prior_relation">
            <dt>{{ t('lifecycle.duplicate.classify') }}</dt>
            <dd>{{ t(`lifecycle.duplicate.relations.${form.prior_relation}`) }}</dd>
          </div>
          <div class="wide"><dt>{{ t('intake.description') }}</dt><dd class="prewrap">{{ form.description || '—' }}</dd></div>
        </dl>

        <h4>{{ t('attachments.title') }}</h4>
        <p v-if="!files.length" class="state">{{ t('intake.review.noFiles') }}</p>
        <ul v-else class="review-files">
          <li v-for="(attachment, index) in files" :key="attachment.id ?? `${attachment.name}-${index}`">
            <span class="file-name ltr">{{ attachment.name }}</span>
            <span class="declared">{{ declaredDocument(attachment) }}</span>
            <span v-if="attachment.label" class="declared-label">{{ attachment.label }}</span>
          </li>
        </ul>

        <div class="actions">
          <button class="ghost" type="button" :disabled="submitting" @click="step = 'form'">
            {{ t('intake.review.back') }}
          </button>
          <button
            v-can="'request_intake.add'"
            class="primary"
            type="button"
            :disabled="isBusy || formIncomplete"
            @click="submit"
          >{{ submitting ? t('intake.submitting') : t('intake.submit') }}</button>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.heading { margin-bottom: 1rem; }.heading h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }.heading p { margin: .25rem 0 0; color: var(--color-muted); font-size: .88rem; }
.card { padding: 1.25rem; }.form, .review { max-inline-size: 52rem; }.form fieldset { min-inline-size: 0; padding: 0; margin: 0 0 1.5rem; border: 0; }.form legend { margin-bottom: .85rem; color: var(--color-brand-text); font-weight: 700; }.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }.wide { grid-column: 1 / -1; }
label { display: grid; gap: .35rem; color: var(--color-black-700); font-size: .85rem; }input, select, textarea { min-inline-size: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); font: inherit; }textarea { resize: vertical; }small { color: var(--color-danger-fg); font-size: .78rem; }.field-hint, .hint, .state { color: var(--color-muted); font-size: .78rem; }.hint, .state { margin: 0 0 .75rem; }.file-input { max-inline-size: 100%; }
.checklist ul { display: grid; gap: .35rem; padding-inline-start: 0; margin: 0; color: var(--color-black-700); font-size: .85rem; list-style: none; }
.checklist li.covered { color: var(--color-success-fg); }.checklist li.outstanding { color: var(--color-black-700); font-weight: 600; }.doc-required { color: var(--color-danger-fg); font-size: .75rem; }.outstanding-list { display: block; margin-block-start: .25rem; font-weight: 600; }.checklist .tick { display: inline-block; min-inline-size: 1rem; }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.doc-group + .doc-group { margin-block-start: .85rem; }.doc-group h3 { margin: 0 0 .35rem; color: var(--color-black-700); font-size: .8rem; font-weight: 600; }.doc-condition { color: var(--color-black-500); font-size: .75rem; }
.files { display: grid; gap: .6rem; margin-top: .85rem; }.file-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(9rem, auto) minmax(8rem, 1fr) auto; gap: .5rem; align-items: center; padding: .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }.file-row .row-error { grid-column: 1 / -1; }.file-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .8rem; }.actions { display: flex; gap: .5rem; }.primary, .ghost { padding: .5rem .9rem; border-radius: var(--radius-lg); font-size: .85rem; cursor: pointer; }.primary { border: 0; color: var(--color-on-brand); background: var(--color-brand); }.ghost { border: 1px solid var(--color-border-hover); color: var(--color-black-700); background: var(--color-surface); }.link-button { text-decoration: none; }.primary:disabled, .ghost:disabled, fieldset:disabled { cursor: not-allowed; opacity: .65; }.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }.success { max-inline-size: 38rem; }.success h3 { margin: 0; color: var(--color-brand-text); }.success p { color: var(--color-black-700); }.reference { display: block; margin: 1rem 0; color: var(--color-primary); font-size: 1.15rem; }.receipt-notice { padding: .6rem .7rem; border: 1px solid var(--color-info-border); border-radius: var(--radius-lg); color: var(--color-info-fg); background: var(--color-info-bg); font-size: .8rem; }
.drafts { margin-bottom: 1rem; max-inline-size: 52rem; }.drafts h3 { margin: 0 0 .6rem; color: var(--color-brand-text); font-size: .95rem; }.drafts ul { display: grid; gap: .5rem; padding-inline-start: 0; margin: 0; list-style: none; }.drafts li { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: .5rem; align-items: center; padding: .55rem .65rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }.draft-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--color-black-700); font-size: .85rem; }.draft-meta { color: var(--color-muted); font-size: .75rem; }
.draft-state { display: flex; gap: .5rem; align-items: center; margin: 0 0 1rem; color: var(--color-muted); font-size: .78rem; }.linkish { padding: 0; border: 0; color: var(--color-danger-fg); background: none; font: inherit; text-decoration: underline; cursor: pointer; }
.review h3 { margin: 0 0 .35rem; color: var(--color-brand-text); font-size: 1rem; }.review h4 { margin: 1.25rem 0 .5rem; color: var(--color-black-700); font-size: .85rem; }.review-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin: 0 0 .5rem; }.review-grid dt { color: var(--color-muted); font-size: .75rem; }.review-grid dd { margin: .2rem 0 0; color: var(--color-black-700); font-size: .88rem; }.prewrap { white-space: pre-wrap; }
.review-files { display: grid; gap: .45rem; padding-inline-start: 0; margin: 0 0 1rem; list-style: none; }.review-files li { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: .5rem; align-items: center; padding: .5rem .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }.declared { color: var(--color-brand-text); font-size: .78rem; }.declared-label { color: var(--color-muted); font-size: .75rem; }
@media (max-width: 640px) { .grid, .review-grid { grid-template-columns: 1fr; }.wide { grid-column: auto; }.file-row, .review-files li, .drafts li { grid-template-columns: 1fr; } }
</style>
