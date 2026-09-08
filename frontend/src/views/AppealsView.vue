<script setup>
/**
 * Appeals (التظلمات) — Stage 58 (foundational screen), Stage 59 (real
 * intake: ownership/decided-status/non-duplication, all enforced server-
 * side in AppealEligibility — the new-facts field below is always shown
 * rather than conditionally revealed, since only the server actually knows
 * whether a prior appeal exists on the chosen request), Stage 60 (the
 * formal-verification gate — AppealController::verify()).
 *
 * The list is scoped server-side to the caller's own appeals unless they're
 * R08 or hold `appeals.edit` (see AppealController::index) — the second
 * bypass is what lets an R02 verifier see appeals filed by other people.
 */
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import FileUpload from '../components/FileUpload.vue'

const { t, locale } = useI18n()

const STATUS_CODES = [
  'submitted', 'formal_verification', 'file_assembly', 'legal_review',
  'committee_presentation', 'notified_closed', 'rejected', 'outside_jurisdiction',
]

const COMPETENT_BODY_OPTIONS = ['committee', 'mayor', 'ministry', 'other_body', 'disciplinary_or_court']

// --- List -------------------------------------------------------------------

const rows = ref([])
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(false)
const loadError = ref(null)
const statusFilter = ref('')

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/appeals', {
      params: { page: requestedPage, status: statusFilter.value || undefined },
    })
    rows.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

watch(statusFilter, () => load(1))

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(value))
}

function statusLabel(status) {
  if (!status) return t('common.none')
  return locale.value === 'ar' ? status.name_ar || status.name_en : status.name_en || status.name_ar
}

// --- Create form --------------------------------------------------------------

const requestSearch = ref('')
const requestResults = ref([])
const requestSearching = ref(false)
const selectedRequest = ref(null)
let searchTimer = null

watch(requestSearch, (value) => {
  clearTimeout(searchTimer)
  if (!value.trim()) {
    requestResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    requestSearching.value = true
    try {
      const { data } = await api.get('/requests', { params: { search: value.trim(), per_page: 5 } })
      requestResults.value = data.data ?? []
    } catch {
      requestResults.value = []
    } finally {
      requestSearching.value = false
    }
  }, 300)
})

function pickRequest(request) {
  selectedRequest.value = request
  requestSearch.value = ''
  requestResults.value = []
}

function clearSelection() {
  selectedRequest.value = null
}

const decisionReference = ref('')
const decisionDate = ref('')
const knownAt = ref('')
const appealReasons = ref('')
const finalRequestText = ref('')
const newFactsDeclaration = ref('')
const creating = ref(false)
const createError = ref(null)
const createMessage = ref(null)

// Stage 59 — once created, offer the supporting-documents step for this
// specific appeal before returning to the plain create form.
const newAppealId = ref(null)

function extractErrorMessage(error, fallback) {
  const errors = error?.response?.data?.errors
  if (errors) {
    const first = Object.values(errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return error?.response?.data?.message ?? fallback
}

function resetCreateForm() {
  selectedRequest.value = null
  decisionReference.value = ''
  decisionDate.value = ''
  knownAt.value = ''
  appealReasons.value = ''
  finalRequestText.value = ''
  newFactsDeclaration.value = ''
}

async function createAppeal() {
  if (!selectedRequest.value) {
    createError.value = t('appeals.create.selectRequestFirst')
    return
  }
  creating.value = true
  createError.value = null
  createMessage.value = null
  try {
    const { data } = await api.post('/appeals', {
      original_request_id: selectedRequest.value.id,
      original_decision_reference: decisionReference.value || null,
      original_decision_date: decisionDate.value || null,
      known_at: knownAt.value,
      appeal_reasons: appealReasons.value,
      final_request: finalRequestText.value,
      new_facts_declaration: newFactsDeclaration.value || null,
    })
    createMessage.value = t('appeals.create.success')
    newAppealId.value = data.data.id
    resetCreateForm()
    await load(1)
  } catch (error) {
    createError.value = extractErrorMessage(error, t('appeals.create.failed'))
  } finally {
    creating.value = false
  }
}

function finishAttachments() {
  newAppealId.value = null
  createMessage.value = null
}

// --- Stage 60 — formal verification ------------------------------------------

const verifyTarget = ref(null)
const verifyForm = ref(blankVerifyForm())
const verifying = ref(false)
const verifyError = ref(null)

function blankVerifyForm() {
  return { appellant_standing: '', valid_target_decision: '', non_duplication: '', reason: '' }
}

function startVerify(row) {
  verifyTarget.value = row
  verifyForm.value = blankVerifyForm()
  verifyError.value = null
}

function cancelVerify() {
  verifyTarget.value = null
  verifyError.value = null
}

async function submitVerify() {
  if (!verifyTarget.value || verifying.value) return
  verifying.value = true
  verifyError.value = null
  try {
    await api.post(`/appeals/${verifyTarget.value.id}/verify`, {
      appellant_standing: verifyForm.value.appellant_standing === 'yes',
      valid_target_decision: verifyForm.value.valid_target_decision === 'yes',
      non_duplication: verifyForm.value.non_duplication === 'yes',
      reason: verifyForm.value.reason || null,
    })
    verifyTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    verifyError.value = extractErrorMessage(error, t('appeals.verify.failed'))
  } finally {
    verifying.value = false
  }
}

function deadlineLabel(deadlineMet) {
  if (deadlineMet === null) return t('appeals.verify.deadlineNotConfigured')
  return deadlineMet ? t('appeals.verify.deadlineMet') : t('appeals.verify.deadlineMissed')
}

// --- Stage 62 — jurisdiction test (Art. 77) -----------------------------------

const jurisdictionTarget = ref(null)
const jurisdictionCompetentBody = ref('')
const jurisdictionSubmitting = ref(false)
const jurisdictionError = ref(null)

function startJurisdictionTest(row) {
  jurisdictionTarget.value = row
  jurisdictionCompetentBody.value = ''
  jurisdictionError.value = null
}

function cancelJurisdictionTest() {
  jurisdictionTarget.value = null
  jurisdictionError.value = null
}

async function submitJurisdictionTest() {
  if (!jurisdictionTarget.value || jurisdictionSubmitting.value) return
  if (!jurisdictionCompetentBody.value) {
    jurisdictionError.value = t('appeals.jurisdiction.selectFirst')
    return
  }
  jurisdictionSubmitting.value = true
  jurisdictionError.value = null
  try {
    await api.patch(`/appeals/${jurisdictionTarget.value.id}/jurisdiction-test`, {
      competent_body: jurisdictionCompetentBody.value,
    })
    jurisdictionTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    jurisdictionError.value = extractErrorMessage(error, t('appeals.jurisdiction.failed'))
  } finally {
    jurisdictionSubmitting.value = false
  }
}

function competentBodyLabel(code) {
  return code ? t('appeals.jurisdiction.options.' + code) : t('common.none')
}

// --- Stage 62 — legal review (Art. 75 point 4) --------------------------------

const legalReviewTarget = ref(null)
const legalReviewForm = ref(blankLegalReviewForm())
const legalReviewSubmitting = ref(false)
const legalReviewError = ref(null)

function blankLegalReviewForm() {
  return {
    factual_error: '',
    legal_text_violation: '',
    new_documents: '',
    formation_or_reasoning_defect: '',
    issued_by_competent_body: '',
  }
}

function startLegalReview(row) {
  legalReviewTarget.value = row
  legalReviewForm.value = blankLegalReviewForm()
  legalReviewError.value = null
}

function cancelLegalReview() {
  legalReviewTarget.value = null
  legalReviewError.value = null
}

async function submitLegalReview() {
  if (!legalReviewTarget.value || legalReviewSubmitting.value) return
  const form = legalReviewForm.value
  const values = Object.values(form)
  if (values.some((value) => value === '')) {
    legalReviewError.value = t('appeals.legalReview.answerAll')
    return
  }
  legalReviewSubmitting.value = true
  legalReviewError.value = null
  try {
    await api.patch(`/appeals/${legalReviewTarget.value.id}/legal-review`, {
      factual_error: form.factual_error === 'yes',
      legal_text_violation: form.legal_text_violation === 'yes',
      new_documents: form.new_documents === 'yes',
      formation_or_reasoning_defect: form.formation_or_reasoning_defect === 'yes',
      issued_by_competent_body: form.issued_by_competent_body === 'yes',
    })
    legalReviewTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    legalReviewError.value = extractErrorMessage(error, t('appeals.legalReview.failed'))
  } finally {
    legalReviewSubmitting.value = false
  }
}

// --- Stage 61 — original file dossier ----------------------------------------
// A read-only, lazily-fetched view of AppealFileCompiler's output. Every row
// currently visible in the list is visible through /appeals/{id}/file too —
// both read the same Appeal::isVisibleTo() predicate server-side — so no
// extra v-can gate is needed on the toggle button itself.

const fileTargetId = ref(null)
const fileData = ref(null)
const fileLoading = ref(false)
const fileError = ref(null)

async function toggleFile(row) {
  if (fileTargetId.value === row.id) {
    fileTargetId.value = null
    fileData.value = null
    fileError.value = null
    return
  }
  fileTargetId.value = row.id
  fileData.value = null
  fileError.value = null
  fileLoading.value = true
  try {
    const { data } = await api.get(`/appeals/${row.id}/file`)
    fileData.value = data.data
  } catch (error) {
    fileError.value = extractErrorMessage(error, t('appeals.file.failed'))
  } finally {
    fileLoading.value = false
  }
}

function bilingual(entity) {
  if (!entity) return t('common.none')
  return locale.value === 'ar' ? (entity.name_ar || entity.name_en) : (entity.name_en || entity.name_ar)
}

function minutesStatusLabel(status) {
  return status ? t(`appeals.file.minutes.status.${status}`) : t('common.none')
}

function formatBytes(bytes) {
  if (!bytes && bytes !== 0) return ''
  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unitIndex = 0
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }
  return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

// The appeal's own documents carry a real preview_url (unlike the read-only
// original-request/memo document listings above), fetched through the
// bearer-aware axios instance — a plain <a href> would hit the private
// stream unauthenticated, matching RequestDetailView.vue's own pattern.
async function openAppealDocument(doc) {
  try {
    const { data } = await api.get(doc.preview_url, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    window.open(url, '_blank', 'noopener')
    window.setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch {
    fileError.value = t('appeals.file.documents.previewFailed')
  }
}

// --- Stage 64 — outcome execution ---------------------------------------------
// A separate, deliberate step from Stage 63's own vote/decision recording —
// see AppealOutcomeExecutor / AppealController::executeOutcome. The redo
// stage picker is only fetched once, lazily, the first time it's needed.

const executionTarget = ref(null)
const executionRedoStage = ref('')
const executionSubmitting = ref(false)
const executionError = ref(null)
const redoStageOptions = ref([])
const redoStagesLoading = ref(false)
const redoStagesError = ref(null)

function startExecution(row) {
  executionTarget.value = row
  executionRedoStage.value = ''
  executionError.value = null
  if (row.committee_decision?.outcome === 'appeal_redo' && redoStageOptions.value.length === 0) {
    loadRedoStageOptions()
  }
}

function cancelExecution() {
  executionTarget.value = null
  executionError.value = null
}

async function loadRedoStageOptions() {
  redoStagesLoading.value = true
  redoStagesError.value = null
  try {
    const { data } = await api.get('/appeals/redo-stage-options')
    redoStageOptions.value = data.data ?? []
  } catch {
    redoStagesError.value = t('appeals.execution.loadStagesFailed')
  } finally {
    redoStagesLoading.value = false
  }
}

async function submitExecution() {
  if (!executionTarget.value || executionSubmitting.value) return
  const isRedo = executionTarget.value.committee_decision?.outcome === 'appeal_redo'
  if (isRedo && !executionRedoStage.value) {
    executionError.value = t('appeals.execution.selectStageFirst')
    return
  }
  executionSubmitting.value = true
  executionError.value = null
  try {
    await api.patch(`/appeals/${executionTarget.value.id}/execute-outcome`, {
      redo_stage_id: isRedo ? executionRedoStage.value : undefined,
    })
    executionTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    executionError.value = extractErrorMessage(error, t('appeals.execution.failed'))
  } finally {
    executionSubmitting.value = false
  }
}

// --- Stage 65 — notification & closure ----------------------------------------
// Closable once the appeal has genuinely concluded: either terminal branch
// (rejected at Stage 60, outside_jurisdiction at Stage 62) or a committee
// decision whose outcome has already been executed (Stage 64). The final
// result itself is derived server-side, never entered here — this form only
// collects the manual closure-record fields [D] Arts. 34–37 ask for.
const APPEAL_DECISION_OUTCOME_CODES = ['appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo']

function isClosable(row) {
  const code = row.status?.code
  if (code === 'rejected' || code === 'outside_jurisdiction') return true
  return code === 'committee_presentation' && !!row.outcome_execution
}

function pendingFinalResultCode(row) {
  const code = row.status?.code
  if (code === 'rejected' || code === 'outside_jurisdiction') return code
  return row.committee_decision?.outcome ?? null
}

function finalResultLabel(code) {
  if (!code) return t('common.none')
  if (APPEAL_DECISION_OUTCOME_CODES.includes(code)) return t('decisions.outcome.' + code)
  return t('appeals.filters.statuses.' + code)
}

const closureTarget = ref(null)
const closureForm = ref(blankClosureForm())
const closureSubmitting = ref(false)
const closureError = ref(null)

function blankClosureForm() {
  return { final_decision_number: '', approving_body: '', execution_date: '', executing_body: '', file_storage_location: '' }
}

function startClosure(row) {
  closureTarget.value = row
  closureForm.value = blankClosureForm()
  closureError.value = null
}

function cancelClosure() {
  closureTarget.value = null
  closureError.value = null
}

async function submitClosure() {
  if (!closureTarget.value || closureSubmitting.value) return
  closureSubmitting.value = true
  closureError.value = null
  try {
    await api.patch(`/appeals/${closureTarget.value.id}/close`, {
      final_decision_number: closureForm.value.final_decision_number || null,
      approving_body: closureForm.value.approving_body,
      execution_date: closureForm.value.execution_date || null,
      executing_body: closureForm.value.executing_body || null,
      file_storage_location: closureForm.value.file_storage_location,
    })
    closureTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    closureError.value = extractErrorMessage(error, t('appeals.closure.failed'))
  } finally {
    closureSubmitting.value = false
  }
}

// --- Stage 66 — enumerated-reason-only reopen -----------------------------
// A closed appeal (notified_closed) only. The reason list is the same
// six ReopenReasonCatalog codes the request-side reopen action uses —
// see requestDetail.reopen in RequestDetailView.vue.
const REOPEN_REASON_CODES = [
  'new_document', 'external_reply_received', 'material_error_correction',
  'legal_status_change', 'returned_by_approving_body', 'competent_authority_restudy',
]

const reopenTarget = ref(null)
const reopenReasonCode = ref('')
const reopenNote = ref('')
const reopenSubmitting = ref(false)
const reopenError = ref(null)

function startReopen(row) {
  reopenTarget.value = row
  reopenReasonCode.value = ''
  reopenNote.value = ''
  reopenError.value = null
}

function cancelReopen() {
  reopenTarget.value = null
  reopenError.value = null
}

async function submitReopen() {
  if (!reopenTarget.value || reopenSubmitting.value) return
  if (!reopenReasonCode.value) {
    reopenError.value = t('appeals.reopen.reasonLabel')
    return
  }
  reopenSubmitting.value = true
  reopenError.value = null
  try {
    await api.patch(`/appeals/${reopenTarget.value.id}/reopen`, {
      reason_code: reopenReasonCode.value,
      note: reopenNote.value || null,
    })
    reopenTarget.value = null
    await load(page.value.current_page)
  } catch (error) {
    reopenError.value = extractErrorMessage(error, t('appeals.reopen.failed'))
  } finally {
    reopenSubmitting.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <section class="appeals">
    <div class="heading">
      <div>
        <h2>{{ t('appeals.title') }}</h2>
        <p class="subtitle">{{ t('appeals.subtitle') }}</p>
      </div>
    </div>

    <div v-if="newAppealId" v-can="'appeals.add'" class="card create">
      <h3>{{ t('appeals.create.attachmentsHeading') }}</h3>
      <p v-if="createMessage" class="notice success">{{ createMessage }}</p>
      <FileUpload :upload-url="`/appeals/${newAppealId}/attachments`" :require-section="false" />
      <button class="ghost" type="button" @click="finishAttachments">
        {{ t('appeals.create.finish') }}
      </button>
    </div>

    <div v-else v-can="'appeals.add'" class="card create">
      <h3>{{ t('appeals.create.heading') }}</h3>

      <div v-if="!selectedRequest" class="search-block">
        <label>
          {{ t('appeals.create.searchLabel') }}
          <input
            v-model="requestSearch"
            type="text"
            :placeholder="t('appeals.create.searchPlaceholder')"
          />
        </label>
        <p v-if="requestSearching" class="state">{{ t('common.loading') }}</p>
        <ul v-else-if="requestSearch.trim() && requestResults.length === 0" class="state">
          <li>{{ t('appeals.create.noResults') }}</li>
        </ul>
        <ul v-else-if="requestResults.length" class="results">
          <li v-for="result in requestResults" :key="result.id">
            <button type="button" class="ghost" @click="pickRequest(result)">
              <span class="ref ltr">{{ result.reference_number }}</span>
              <span>{{ result.title }}</span>
            </button>
          </li>
        </ul>
      </div>

      <div v-else class="selected">
        <div>
          <span class="ref ltr">{{ selectedRequest.reference_number }}</span>
          <span>{{ selectedRequest.title }}</span>
        </div>
        <button type="button" class="ghost" @click="clearSelection">{{ t('appeals.create.change') }}</button>
      </div>

      <div class="fields">
        <label>
          {{ t('appeals.create.knownAt') }}
          <input v-model="knownAt" type="date" />
        </label>
        <label>
          {{ t('appeals.create.decisionReference') }}
          <input
            v-model="decisionReference"
            type="text"
            :placeholder="t('appeals.create.decisionReferencePlaceholder')"
          />
        </label>
        <label>
          {{ t('appeals.create.decisionDate') }}
          <input v-model="decisionDate" type="date" />
        </label>
      </div>

      <label class="full">
        {{ t('appeals.create.appealReasons') }}
        <textarea v-model="appealReasons" rows="3"></textarea>
      </label>
      <label class="full">
        {{ t('appeals.create.finalRequest') }}
        <textarea v-model="finalRequestText" rows="2"></textarea>
      </label>
      <label class="full">
        {{ t('appeals.create.newFactsDeclaration') }}
        <textarea v-model="newFactsDeclaration" rows="2" :placeholder="t('appeals.create.newFactsDeclarationHint')"></textarea>
      </label>

      <p v-if="createMessage" class="notice success">{{ createMessage }}</p>
      <p v-if="createError" class="alert">{{ createError }}</p>

      <button class="primary" type="button" :disabled="creating" @click="createAppeal">
        {{ creating ? t('appeals.create.submitting') : t('appeals.create.submit') }}
      </button>
    </div>

    <div v-if="verifyTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.verify.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.verify.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ verifyTarget.original_request?.reference_number }}</span>
          <span>{{ verifyTarget.original_request?.title }}</span>
        </div>
      </div>

      <fieldset :disabled="verifying">
        <div class="fields">
          <label>
            {{ t('appeals.verify.appellantStanding') }}
            <select v-model="verifyForm.appellant_standing">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.verify.validTargetDecision') }}
            <select v-model="verifyForm.valid_target_decision">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.verify.nonDuplication') }}
            <select v-model="verifyForm.non_duplication">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
        </div>
        <label class="full">
          {{ t('appeals.verify.reason') }}
          <textarea v-model="verifyForm.reason" rows="2"></textarea>
        </label>
      </fieldset>

      <p v-if="verifyError" class="alert">{{ verifyError }}</p>

      <button class="primary" type="button" :disabled="verifying" @click="submitVerify">
        {{ verifying ? t('appeals.verify.submitting') : t('appeals.verify.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="verifying" @click="cancelVerify">
        {{ t('appeals.verify.cancel') }}
      </button>
    </div>

    <div v-if="jurisdictionTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.jurisdiction.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.jurisdiction.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ jurisdictionTarget.original_request?.reference_number }}</span>
          <span>{{ jurisdictionTarget.original_request?.title }}</span>
        </div>
      </div>

      <fieldset :disabled="jurisdictionSubmitting">
        <label class="full">
          {{ t('appeals.jurisdiction.question') }}
          <select v-model="jurisdictionCompetentBody">
            <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
            <option v-for="code in COMPETENT_BODY_OPTIONS" :key="code" :value="code">
              {{ t('appeals.jurisdiction.options.' + code) }}
            </option>
          </select>
        </label>
      </fieldset>

      <p v-if="jurisdictionCompetentBody && jurisdictionCompetentBody !== 'committee'" class="notice">
        {{ t('appeals.jurisdiction.terminationWarning') }}
      </p>
      <p v-if="jurisdictionError" class="alert">{{ jurisdictionError }}</p>

      <button class="primary" type="button" :disabled="jurisdictionSubmitting" @click="submitJurisdictionTest">
        {{ jurisdictionSubmitting ? t('appeals.jurisdiction.submitting') : t('appeals.jurisdiction.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="jurisdictionSubmitting" @click="cancelJurisdictionTest">
        {{ t('appeals.verify.cancel') }}
      </button>
    </div>

    <div v-if="legalReviewTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.legalReview.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.legalReview.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ legalReviewTarget.original_request?.reference_number }}</span>
          <span>{{ legalReviewTarget.original_request?.title }}</span>
        </div>
      </div>

      <fieldset :disabled="legalReviewSubmitting">
        <div class="fields">
          <label>
            {{ t('appeals.legalReview.factualError') }}
            <select v-model="legalReviewForm.factual_error">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.legalReview.legalTextViolation') }}
            <select v-model="legalReviewForm.legal_text_violation">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.legalReview.newDocuments') }}
            <select v-model="legalReviewForm.new_documents">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.legalReview.formationOrReasoningDefect') }}
            <select v-model="legalReviewForm.formation_or_reasoning_defect">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
          <label>
            {{ t('appeals.legalReview.issuedByCompetentBody') }}
            <select v-model="legalReviewForm.issued_by_competent_body">
              <option value="" disabled>{{ t('appeals.verify.choose') }}</option>
              <option value="yes">{{ t('appeals.verify.yes') }}</option>
              <option value="no">{{ t('appeals.verify.no') }}</option>
            </select>
          </label>
        </div>
      </fieldset>

      <p v-if="legalReviewError" class="alert">{{ legalReviewError }}</p>

      <button class="primary" type="button" :disabled="legalReviewSubmitting" @click="submitLegalReview">
        {{ legalReviewSubmitting ? t('appeals.legalReview.submitting') : t('appeals.legalReview.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="legalReviewSubmitting" @click="cancelLegalReview">
        {{ t('appeals.verify.cancel') }}
      </button>
    </div>

    <div v-if="executionTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.execution.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.execution.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ executionTarget.original_request?.reference_number }}</span>
          <span>{{ executionTarget.original_request?.title }}</span>
        </div>
      </div>

      <p class="text-block">
        <strong>{{ t('appeals.execution.decidedOutcome') }}:</strong>
        {{ t('decisions.outcome.' + executionTarget.committee_decision?.outcome) }}
      </p>

      <fieldset v-if="executionTarget.committee_decision?.outcome === 'appeal_redo'" :disabled="executionSubmitting">
        <label class="full">
          {{ t('appeals.execution.redoStage') }}
          <select v-model="executionRedoStage">
            <option value="" disabled>{{ t('appeals.execution.chooseStage') }}</option>
            <option v-for="stage in redoStageOptions" :key="stage.id" :value="stage.id">
              {{ locale === 'ar' ? stage.name_ar : stage.name_en }}
            </option>
          </select>
        </label>
        <p class="notice">{{ t('appeals.execution.redoHint') }}</p>
        <p v-if="redoStagesLoading" class="state">{{ t('common.loading') }}</p>
        <p v-if="redoStagesError" class="alert">{{ redoStagesError }}</p>
      </fieldset>

      <p v-if="executionError" class="alert">{{ executionError }}</p>

      <button class="primary" type="button" :disabled="executionSubmitting" @click="submitExecution">
        {{ executionSubmitting ? t('appeals.execution.submitting') : t('appeals.execution.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="executionSubmitting" @click="cancelExecution">
        {{ t('appeals.execution.cancel') }}
      </button>
    </div>

    <div v-if="closureTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.closure.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.closure.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ closureTarget.original_request?.reference_number }}</span>
          <span>{{ closureTarget.original_request?.title }}</span>
        </div>
      </div>

      <p class="text-block">
        <strong>{{ t('appeals.closure.finalResult') }}:</strong>
        {{ finalResultLabel(pendingFinalResultCode(closureTarget)) }}
      </p>

      <fieldset :disabled="closureSubmitting">
        <div class="fields">
          <label>
            {{ t('appeals.closure.finalDecisionNumber') }}
            <input v-model="closureForm.final_decision_number" type="text" />
          </label>
          <label>
            {{ t('appeals.closure.approvingBody') }}
            <input v-model="closureForm.approving_body" type="text" />
          </label>
          <label>
            {{ t('appeals.closure.executionDate') }}
            <input v-model="closureForm.execution_date" type="date" />
          </label>
          <label>
            {{ t('appeals.closure.executingBody') }}
            <input v-model="closureForm.executing_body" type="text" />
          </label>
          <label>
            {{ t('appeals.closure.fileStorageLocation') }}
            <input v-model="closureForm.file_storage_location" type="text" />
          </label>
        </div>
      </fieldset>

      <p v-if="closureError" class="alert">{{ closureError }}</p>

      <button class="primary" type="button" :disabled="closureSubmitting" @click="submitClosure">
        {{ closureSubmitting ? t('appeals.closure.submitting') : t('appeals.closure.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="closureSubmitting" @click="cancelClosure">
        {{ t('appeals.closure.cancel') }}
      </button>
    </div>

    <div v-if="reopenTarget" v-can="'appeals.edit'" class="card create">
      <h3>{{ t('appeals.reopen.heading') }}</h3>
      <p class="subtitle">{{ t('appeals.reopen.hint') }}</p>
      <div class="selected">
        <div>
          <span class="ref ltr">{{ reopenTarget.original_request?.reference_number }}</span>
          <span>{{ reopenTarget.original_request?.title }}</span>
        </div>
      </div>

      <fieldset :disabled="reopenSubmitting">
        <div class="fields">
          <label class="full">
            {{ t('appeals.reopen.reasonLabel') }}
            <select v-model="reopenReasonCode">
              <option value="" disabled>{{ t('appeals.reopen.chooseReason') }}</option>
              <option v-for="code in REOPEN_REASON_CODES" :key="code" :value="code">
                {{ t(`reopenReasons.${code}`) }}
              </option>
            </select>
          </label>
          <label class="full">
            {{ t('appeals.reopen.noteLabel') }}
            <textarea v-model="reopenNote" rows="2" maxlength="5000" />
          </label>
        </div>
      </fieldset>

      <p v-if="reopenError" class="alert">{{ reopenError }}</p>

      <button class="primary" type="button" :disabled="reopenSubmitting" @click="submitReopen">
        {{ reopenSubmitting ? t('appeals.reopen.submitting') : t('appeals.reopen.submit') }}
      </button>
      <button class="ghost" type="button" :disabled="reopenSubmitting" @click="cancelReopen">
        {{ t('appeals.reopen.cancel') }}
      </button>
    </div>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card list">
      <div class="filters">
        <label>
          {{ t('appeals.filters.status') }}
          <select v-model="statusFilter">
            <option value="">{{ t('appeals.filters.all') }}</option>
            <option v-for="code in STATUS_CODES" :key="code" :value="code">
              {{ t(`appeals.filters.statuses.${code}`) }}
            </option>
          </select>
        </label>
      </div>

      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('appeals.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('appeals.columns.request') }}</th>
              <th>{{ t('appeals.columns.decision') }}</th>
              <th>{{ t('appeals.columns.status') }}</th>
              <th>{{ t('appeals.columns.filedAt') }}</th>
              <th>{{ t('appeals.columns.verification') }}</th>
              <th>{{ t('appeals.columns.jurisdiction') }}</th>
              <th>{{ t('appeals.columns.legalReview') }}</th>
              <th>{{ t('appeals.columns.execution') }}</th>
              <th>{{ t('appeals.columns.closure') }}</th>
              <th>{{ t('appeals.columns.reopen') }}</th>
              <th>{{ t('appeals.columns.file') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in rows" :key="row.id">
            <tr>
              <td>
                <span class="ref ltr">{{ row.original_request?.reference_number }}</span>
                <small class="muted">{{ row.original_request?.title }}</small>
              </td>
              <td>{{ row.original_decision_reference || t('common.none') }}</td>
              <td>
                <span v-if="row.status" class="pill" :style="{ borderColor: row.status.color }">
                  {{ statusLabel(row.status) }}
                </span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td class="nowrap">{{ dateTime(row.created_at) }}</td>
              <td>
                <button
                  v-if="row.status?.code === 'submitted'"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startVerify(row)"
                >
                  {{ t('appeals.verify.action') }}
                </button>
                <div v-else-if="row.formal_verification" class="verification-summary">
                  <span class="pill" :class="row.status?.code === 'rejected' ? 'rejected' : 'passed'">
                    {{ row.status?.code === 'rejected' ? t('appeals.verify.resultRejected') : t('appeals.verify.resultPassed') }}
                  </span>
                  <small class="muted">{{ deadlineLabel(row.formal_verification.checks.deadline_met) }}</small>
                  <small v-if="row.formal_verification.reason" class="muted">{{ row.formal_verification.reason }}</small>
                  <small v-if="row.formal_verification.verified_by" class="muted">
                    {{ t('appeals.verify.verifiedBy') }}: {{ row.formal_verification.verified_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.verify.notYet') }}</span>
              </td>
              <td>
                <button
                  v-if="row.status?.code === 'formal_verification'"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startJurisdictionTest(row)"
                >
                  {{ t('appeals.jurisdiction.action') }}
                </button>
                <div v-else-if="row.jurisdiction_test" class="verification-summary">
                  <span class="pill" :class="row.jurisdiction_test.competent_body === 'committee' ? 'passed' : 'rejected'">
                    {{ competentBodyLabel(row.jurisdiction_test.competent_body) }}
                  </span>
                  <small v-if="row.jurisdiction_test.tested_by" class="muted">
                    {{ row.jurisdiction_test.tested_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.jurisdiction.notYet') }}</span>
              </td>
              <td>
                <button
                  v-if="row.status?.code === 'file_assembly'"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startLegalReview(row)"
                >
                  {{ t('appeals.legalReview.action') }}
                </button>
                <div v-else-if="row.legal_review" class="verification-summary">
                  <span class="pill passed">{{ t('appeals.legalReview.recorded') }}</span>
                  <small v-if="row.legal_review.reviewed_by" class="muted">
                    {{ row.legal_review.reviewed_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.legalReview.notYet') }}</span>
              </td>
              <td>
                <button
                  v-if="row.status?.code === 'committee_presentation' && row.committee_decision && !row.outcome_execution"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startExecution(row)"
                >
                  {{ t('appeals.execution.action') }}
                </button>
                <div v-else-if="row.outcome_execution" class="verification-summary">
                  <span class="pill passed">{{ t('decisions.outcome.' + row.committee_decision?.outcome) }}</span>
                  <small v-if="row.outcome_execution.redo_stage" class="muted">
                    {{ t('appeals.execution.redoStageLabel') }}: {{ locale === 'ar' ? row.outcome_execution.redo_stage.name_ar : row.outcome_execution.redo_stage.name_en }}
                  </small>
                  <small v-if="row.outcome_execution.executed_by" class="muted">
                    {{ t('appeals.execution.executedBy') }}: {{ row.outcome_execution.executed_by.name }}
                  </small>
                </div>
                <span v-else-if="row.status?.code === 'committee_presentation'" class="muted">{{ t('appeals.execution.noDecisionYet') }}</span>
                <span v-else class="muted">{{ t('appeals.execution.notYet') }}</span>
              </td>
              <td>
                <button
                  v-if="isClosable(row) && !row.closure"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startClosure(row)"
                >
                  {{ t('appeals.closure.action') }}
                </button>
                <div v-else-if="row.closure" class="verification-summary">
                  <span class="pill passed">{{ finalResultLabel(row.closure.final_result_code) }}</span>
                  <small class="muted">{{ t('appeals.closure.noticeStatus.' + row.closure.notice_status) }}</small>
                  <small v-if="row.closure.closed_by" class="muted">
                    {{ t('appeals.closure.closedBy') }}: {{ row.closure.closed_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.closure.notConcludedYet') }}</span>
              </td>
              <td>
                <button
                  v-if="row.status?.code === 'notified_closed'"
                  v-can="'appeals.edit'"
                  class="ghost"
                  type="button"
                  @click="startReopen(row)"
                >
                  {{ t('appeals.reopen.action') }}
                </button>
                <div v-else-if="row.reopen" class="verification-summary">
                  <span class="pill passed">{{ t(`reopenReasons.${row.reopen.reason_code}`) }}</span>
                  <small v-if="row.reopen.reopened_by" class="muted">
                    {{ t('appeals.reopen.reopenedBy') }}: {{ row.reopen.reopened_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.reopen.notClosedYet') }}</span>
              </td>
              <td>
                <button class="ghost" type="button" @click="toggleFile(row)">
                  {{ fileTargetId === row.id ? t('appeals.file.hide') : t('appeals.file.view') }}
                </button>
              </td>
            </tr>
            <tr v-if="fileTargetId === row.id">
              <td colspan="11" class="file-panel">
                <p v-if="fileLoading" class="state">{{ t('common.loading') }}</p>
                <p v-else-if="fileError" class="alert">{{ fileError }}</p>
                <div v-else-if="fileData" class="dossier">
                  <section class="dossier-section" v-if="fileData.original_request">
                    <h4>{{ t('appeals.file.originalRequest.heading') }}</h4>
                    <dl class="info-grid">
                      <dt>{{ t('appeals.file.originalRequest.status') }}</dt>
                      <dd>
                        <span v-if="fileData.original_request.status" class="pill" :style="{ borderColor: fileData.original_request.status.color }">
                          {{ bilingual(fileData.original_request.status) }}
                        </span>
                        <span v-else>{{ t('common.none') }}</span>
                      </dd>
                      <dt>{{ t('appeals.file.originalRequest.stage') }}</dt>
                      <dd>{{ bilingual(fileData.original_request.current_stage) }}</dd>
                      <dt>{{ t('appeals.file.originalRequest.type') }}</dt>
                      <dd>{{ bilingual(fileData.original_request.request_type) }}</dd>
                      <dt>{{ t('appeals.file.originalRequest.department') }}</dt>
                      <dd>{{ bilingual(fileData.original_request.department) }}</dd>
                      <dt>{{ t('appeals.file.originalRequest.submittedAt') }}</dt>
                      <dd>{{ dateTime(fileData.original_request.submitted_at) }}</dd>
                      <dt>{{ t('appeals.file.originalRequest.createdBy') }}</dt>
                      <dd>{{ fileData.original_request.created_by?.name || t('common.none') }}</dd>
                    </dl>
                    <p v-if="fileData.original_request.description" class="text-block">{{ fileData.original_request.description }}</p>
                    <div v-if="fileData.original_request.attachments?.length">
                      <h5>{{ t('appeals.file.originalRequest.attachments') }}</h5>
                      <ul class="doc-list">
                        <li v-for="doc in fileData.original_request.attachments" :key="doc.id">
                          {{ doc.original_name }} <small class="muted">({{ formatBytes(doc.size_bytes) }})</small>
                        </li>
                      </ul>
                    </div>
                  </section>

                  <section class="dossier-section">
                    <h4>{{ t('appeals.file.memo.heading') }}</h4>
                    <p v-if="!fileData.presentation_memo" class="state">{{ t('appeals.file.memo.none') }}</p>
                    <template v-else>
                      <dl class="info-grid">
                        <dt>{{ t('appeals.file.memo.workUnit') }}</dt>
                        <dd>{{ bilingual(fileData.presentation_memo.derived.work_unit) }}</dd>
                        <dt>{{ t('appeals.file.memo.referringBody') }}</dt>
                        <dd>{{ bilingual(fileData.presentation_memo.derived.referring_body) }}</dd>
                      </dl>
                      <div v-if="fileData.presentation_memo.derived.key_documents?.length">
                        <h5>{{ t('appeals.file.memo.keyDocuments') }}</h5>
                        <ul class="doc-list">
                          <li v-for="doc in fileData.presentation_memo.derived.key_documents" :key="doc.id">
                            {{ doc.original_name }}
                          </li>
                        </ul>
                      </div>
                      <template v-if="fileData.presentation_memo.authored">
                        <p v-if="fileData.presentation_memo.authored.facts_summary"><strong>{{ t('appeals.file.memo.factsSummary') }}:</strong> {{ fileData.presentation_memo.authored.facts_summary }}</p>
                        <p v-if="fileData.presentation_memo.authored.legal_opinion"><strong>{{ t('appeals.file.memo.legalOpinion') }}:</strong> {{ fileData.presentation_memo.authored.legal_opinion }}</p>
                        <p v-if="fileData.presentation_memo.authored.committee_question"><strong>{{ t('appeals.file.memo.committeeQuestion') }}:</strong> {{ fileData.presentation_memo.authored.committee_question }}</p>
                      </template>
                      <p v-else class="state">{{ t('appeals.file.memo.notAuthoredYet') }}</p>
                    </template>
                  </section>

                  <section class="dossier-section">
                    <h4>{{ t('appeals.file.minutes.heading') }}</h4>
                    <p v-if="!fileData.meeting_minutes" class="state">{{ t('appeals.file.minutes.none') }}</p>
                    <template v-else>
                      <span class="pill">{{ minutesStatusLabel(fileData.meeting_minutes.status) }}</span>
                      <dl class="info-grid" v-if="fileData.meeting_minutes.attendance">
                        <dt>{{ t('appeals.file.minutes.quorum') }}</dt>
                        <!-- Stage 73 — a محضر compiled for a committee with no
                             transcribed quorum rule carries no required figure. -->
                        <dd>{{ fileData.meeting_minutes.attendance.quorum_present }} / {{ fileData.meeting_minutes.attendance.quorum_required ?? t('meetingsUnit.readiness.quorum.notRecorded') }}</dd>
                      </dl>
                      <template v-if="fileData.meeting_minutes.agenda_item">
                        <p v-if="fileData.meeting_minutes.agenda_item.facts_summary"><strong>{{ t('appeals.file.minutes.factsSummary') }}:</strong> {{ fileData.meeting_minutes.agenda_item.facts_summary }}</p>
                        <p v-if="fileData.meeting_minutes.agenda_item.legal_basis"><strong>{{ t('appeals.file.minutes.legalBasis') }}:</strong> {{ fileData.meeting_minutes.agenda_item.legal_basis }}</p>
                        <div v-if="fileData.meeting_minutes.agenda_item.votes">
                          <h5>{{ t('appeals.file.minutes.votes') }}</h5>
                          <p class="text-block">
                            <span v-for="(count, outcome) in fileData.meeting_minutes.agenda_item.votes" :key="outcome">
                              {{ t('decisions.tally.' + outcome) }}: {{ count }}&nbsp;&nbsp;
                            </span>
                          </p>
                        </div>
                        <div v-if="fileData.meeting_minutes.agenda_item.dissenting_opinions?.length">
                          <h5>{{ t('appeals.file.minutes.dissentingOpinions') }}</h5>
                          <ul class="doc-list">
                            <li v-for="(opinion, index) in fileData.meeting_minutes.agenda_item.dissenting_opinions" :key="index">
                              {{ opinion.user }} — {{ t('decisions.vote.' + opinion.vote) }}: {{ opinion.comment }}
                            </li>
                          </ul>
                        </div>
                      </template>
                      <div v-if="fileData.meeting_minutes.required_signatories?.length">
                        <h5>{{ t('appeals.file.minutes.requiredSignatories') }}</h5>
                        <ul class="doc-list">
                          <li v-for="signatory in fileData.meeting_minutes.required_signatories" :key="signatory.id">
                            {{ signatory.name }}
                          </li>
                        </ul>
                      </div>
                    </template>
                  </section>

                  <section class="dossier-section">
                    <h4>{{ t('appeals.file.decision.heading') }}</h4>
                    <p v-if="!fileData.decision && !fileData.decision_reference_fallback" class="state">{{ t('appeals.file.decision.none') }}</p>
                    <dl v-else-if="fileData.decision" class="info-grid">
                      <dt>{{ t('appeals.file.decision.outcome') }}</dt>
                      <dd>{{ t('decisions.outcome.' + fileData.decision.outcome) }}</dd>
                      <dt v-if="fileData.decision.referral_authority">{{ t('appeals.file.decision.referralAuthority') }}</dt>
                      <dd v-if="fileData.decision.referral_authority">{{ fileData.decision.referral_authority }}</dd>
                      <dt>{{ t('appeals.file.decision.decidedBy') }}</dt>
                      <dd>{{ fileData.decision.decided_by?.name || t('common.none') }}</dd>
                      <dt>{{ t('appeals.file.decision.decidedAt') }}</dt>
                      <dd>{{ dateTime(fileData.decision.decided_at) }}</dd>
                    </dl>
                    <dl v-else class="info-grid">
                      <dt>{{ t('appeals.file.decision.referenceFallback') }}</dt>
                      <dd>{{ fileData.decision_reference_fallback.reference || t('common.none') }}</dd>
                      <dt>{{ t('appeals.file.decision.decidedAt') }}</dt>
                      <dd>{{ dateTime(fileData.decision_reference_fallback.date) }}</dd>
                    </dl>
                  </section>

                  <section class="dossier-section">
                    <h4>{{ t('appeals.file.documents.heading') }}</h4>
                    <p v-if="!fileData.appeal_documents?.length" class="state">{{ t('appeals.empty') }}</p>
                    <ul v-else class="doc-list">
                      <li v-for="doc in fileData.appeal_documents" :key="doc.id">
                        <button class="link" type="button" @click="openAppealDocument(doc)">{{ doc.original_name }}</button>
                        <small class="muted">({{ formatBytes(doc.size_bytes) }})</small>
                      </li>
                    </ul>
                  </section>

                  <section class="dossier-section" v-if="fileData.notification_evidence">
                    <h4>{{ t('appeals.file.notifications.heading') }}</h4>
                    <h5>{{ t('appeals.file.notifications.inApp') }}</h5>
                    <p v-if="!fileData.notification_evidence.in_app?.length" class="state">{{ t('appeals.file.notifications.none') }}</p>
                    <ul v-else class="doc-list">
                      <li v-for="notice in fileData.notification_evidence.in_app" :key="notice.id">
                        {{ locale === 'ar' ? notice.title_ar : notice.title_en }} — {{ dateTime(notice.created_at) }}
                        <small class="muted">({{ notice.read_at ? t('appeals.file.notifications.read') : t('appeals.file.notifications.unread') }})</small>
                      </li>
                    </ul>
                    <p class="text-block muted">{{ t('appeals.file.notifications.email') }}: {{ t('appeals.file.notifications.noEvidence') }}</p>
                    <p class="text-block muted">{{ t('appeals.file.notifications.sms') }}: {{ t('appeals.file.notifications.noEvidence') }}</p>
                  </section>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('appeals.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
        {{ t('requests.previous') }}
      </button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
        {{ t('requests.next') }}
      </button>
    </nav>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
h3 { margin: 0 0 .75rem; color: var(--color-black-700); font-size: 1rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }

.card { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); padding: 1.25rem; margin-bottom: 1rem; }
.create label { display: flex; flex-direction: column; gap: .3rem; font-size: .82rem; color: var(--color-black-700); margin-bottom: .75rem; }
.create label.full { margin-bottom: .75rem; }
.create input, .create select, .create textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font-size: .85rem; font-family: inherit; resize: vertical; }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem 1rem; margin-bottom: .75rem; }

.filters { display: flex; align-items: end; gap: .75rem; margin-bottom: 1rem; }
.filters label { display: flex; flex-direction: column; gap: .3rem; font-size: .82rem; color: var(--color-black-700); }
.filters select { padding: .4rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font-size: .85rem; }

.verification-summary { display: flex; flex-direction: column; gap: .2rem; align-items: start; }
.pill.passed { border-color: var(--color-success-border); color: var(--color-success-fg); background: var(--color-success-bg); }
.pill.rejected { border-color: var(--color-danger-border); color: var(--color-danger-fg); background: var(--color-danger-bg); }

.file-panel { background: var(--color-surface-hover); }
.dossier { display: flex; flex-direction: column; gap: 1rem; padding: .5rem 0; }
.dossier-section { border-inline-start: 3px solid var(--color-border-hover); padding-inline-start: .75rem; }
.dossier-section h4 { margin: 0 0 .5rem; color: var(--color-brand-text); font-size: .92rem; }
.dossier-section h5 { margin: .6rem 0 .3rem; color: var(--color-black-700); font-size: .82rem; }
.dossier-section p { margin: .25rem 0; font-size: .84rem; color: var(--color-foreground); }
.info-grid { display: grid; grid-template-columns: max-content 1fr; gap: .3rem .75rem; margin: 0; font-size: .84rem; }
.info-grid dt { color: var(--color-muted); }
.info-grid dd { margin: 0; color: var(--color-foreground); }
.text-block { white-space: pre-line; }
.doc-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .3rem; font-size: .82rem; }
.doc-list li { display: flex; align-items: center; gap: .4rem; }
.link { padding: 0; border: 0; background: none; color: var(--color-brand-text); text-decoration: underline; font-size: inherit; }

.results, .search-block ul.state { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; max-height: 12rem; overflow-y: auto; }
.results button { width: 100%; display: flex; gap: .5rem; align-items: center; text-align: start; }
.selected { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .6rem .75rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); margin-bottom: .75rem; }
.selected > div { display: flex; gap: .6rem; align-items: baseline; }

button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.create > .ghost { margin-top: .75rem; }
.create > .ghost + .ghost { margin-inline-start: .5rem; }
.create > .primary + .ghost { margin-top: .75rem; margin-inline-start: .5rem; }
.primary { padding: .5rem .9rem; border: 0; color: var(--color-on-brand); background: var(--color-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
button:disabled { cursor: not-allowed; opacity: .55; }

.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.notice { padding: .65rem .8rem; margin: 0 0 .75rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); font-size: .85rem; }
.notice.success { border-color: var(--color-success-border); color: var(--color-success-fg); background: var(--color-success-bg); }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 900px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
.nowrap { white-space: nowrap; }
.ref { font-family: var(--font-mono); font-size: .78rem; }
.muted { display: block; color: var(--color-muted); font-size: .72rem; }
.pill { display: inline-block; padding: .12rem .5rem; border: 1px solid var(--color-border-hover); border-radius: 999px; font-size: .72rem; white-space: nowrap; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
</style>
