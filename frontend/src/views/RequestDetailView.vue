<script setup>
/**
 * Stage 15 — the workflow workspace for a single request.
 *
 * Restyled/restructured pass: the page reads cover → stage rail → routing
 * slip → actions, then five tabs (الملف / البوابات / الاعتمادات /
 * اللجنة والقانون / المخرجات) instead of thirteen always-rendered cards
 * stacked in a row. Every v-if, v-can, prop and endpoint below is unchanged
 * from before this pass — only where each block renders moved.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import ApprovalReferralPanel from '../components/ApprovalReferralPanel.vue'
import ApprovalReturnPanel from '../components/ApprovalReturnPanel.vue'
import IntakeGatePanel from '../components/IntakeGatePanel.vue'
import RequestSoundnessPanel from '../components/RequestSoundnessPanel.vue'
import RequestSuspensionPanel from '../components/RequestSuspensionPanel.vue'
import ApprovalTrail from '../components/ApprovalTrail.vue'
import FileUpload from '../components/FileUpload.vue'
import RequestArchivePanel from '../components/RequestArchivePanel.vue'
import RequestClosurePanel from '../components/RequestClosurePanel.vue'
import RequestLifecyclePanel from '../components/RequestLifecyclePanel.vue'
import RequestNotes from '../components/RequestNotes.vue'
import RequestStageRail from '../components/RequestStageRail.vue'
import api from '../lib/api'
// Stage 72 — [D] Appendix 57's grouped document matrix, shared with the intake
// screen so both read the same list the same way.
import { documentCondition, documentLabel, groupDocuments } from '../lib/requiredDocuments'
import { fileSectionLabel } from '../lib/fileSections'
import { stageProgressLabel } from '../lib/stageProgress'
// Stage 75 — [D] Appendix 47's twelve checks, mirrored once for every screen.
import { AUDIT_CHECKS } from '../lib/requestClosure'
import { TRACKING_CHECKS } from '../lib/requestExecution'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const request = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const comment = ref('')
const acting = ref(false)
const activeAction = ref('')
const selectedException = ref(null)
const exceptionReason = ref('')
const exceptionError = ref('')
// Signatures have been removed from the system — approving now just asks
// for a plain confirmation before submitting.
const pendingApprove = ref(false)
const selectedAttachment = ref(null)
const attachmentPreviewUrl = ref('')
const attachmentPreviewing = ref(false)
const attachmentPreviewError = ref('')
const financialImpactSaving = ref(false)
const financialImpactError = ref('')
const jurisdictionTestSaving = ref(false)
const jurisdictionTestError = ref('')
const jurisdictionTestForm = ref(blankJurisdictionTest())

// Stage 66, Track J — [D] Arts. 34–37/78–79's re-presentation path. Mirrors
// RequestController::REOPENABLE_STATUS_CODES exactly.
const REOPENABLE_STATUS_CODES = ['cancelled', 'archived', 'not_approved', 'completed_closed', 'decision_withdrawn', 'decision_amended']
const REOPEN_REASON_CODES = [
  'new_document', 'external_reply_received', 'material_error_correction',
  'legal_status_change', 'returned_by_approving_body', 'competent_authority_restudy',
]
const reopenPanelOpen = ref(false)
const reopenReasonCode = ref('')
const reopenTargetStageId = ref('')
const reopenNote = ref('')
const reopenSubmitting = ref(false)
const reopenError = ref('')
const redoStageOptions = ref([])
const redoStagesLoading = ref(false)
const redoStagesError = ref('')

// Restyled pass — the tab strip. `?tab=` keeps a link openable to a specific
// section; `v-show` (not v-if) keeps every panel's own components mounted
// regardless of the active tab, matching how they already mounted before
// this pass, and is what lets @media print force every panel visible.
const TABS = ['file', 'gates', 'approvals', 'legal', 'outputs']
const activeTab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'file')
function selectTab(tab) {
  activeTab.value = tab
  router.replace({ query: { ...route.query, tab } })
}
watch(() => route.query.tab, (value) => {
  if (value && TABS.includes(value) && value !== activeTab.value) activeTab.value = value
})

/**
 * Arrow-key tab navigation, direction-aware: "next" always moves the reading
 * direction the tabs are actually laid out in, so Right and Left swap roles
 * between Arabic (RTL) and English (LTR) rather than always meaning the same
 * physical arrow key regardless of which way the strip reads.
 */
function stepTab(direction) {
  const delta = (locale.value === 'ar' ? -1 : 1) * direction
  const index = TABS.indexOf(activeTab.value)
  selectTab(TABS[(index + delta + TABS.length) % TABS.length])
}

// Stage 79 — an Art. 101 notice stores both languages in its payload, so the
// register reads back the one the viewer is in without re-deriving the text.
const noticeText = (notice, field) => (locale.value === 'ar'
  ? notice[`${field}_ar`] || notice[`${field}_en`]
  : notice[`${field}_en`] || notice[`${field}_ar`]) ?? '—'

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
// Stage 80 — [D] Appendix 14's folder name for a linked document, or the
// honest "unclassified" label for a row written before that column existed.
const fileSectionName = (code) => fileSectionLabel(t, code)
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : t('common.none')
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')
const actionLabel = (action) => t(`workflow.actions.${action}`)
// Stage 75 — the close endpoint answers with the full detail resource, so the
// screen swaps in the closed request rather than refetching it.
const onClosed = (updated) => { request.value = updated }
// Stage 77 — the two approval-return endpoints answer with the same full detail
// resource, so the card swaps in the updated request rather than refetching.
const onApprovalReturnUpdated = (updated) => { request.value = updated }
// Stage 80 — the referral still awaiting Art. 30's تاريخ ورود النتيجة,
// resolved against the id the server computed rather than re-deriving
// "unanswered" here, so the panel and the endpoint agree on which one it is.
const openApprovalReferral = computed(() => {
  const openId = request.value?.approval_referral_eligibility?.open_referral_id
  return openId ? request.value.approval_referrals?.find((entry) => entry.id === openId) ?? null : null
})
// Stage 78 — every control-gate endpoint answers with the same full detail
// resource, so each card swaps in the updated request rather than refetching.
const onGateUpdated = (updated) => { request.value = updated }
// The round still waiting on Art. 94's "الإجراء الذي اتخذ بشأنها". Resolved
// against the id the server computed rather than re-deriving "unresolved" here,
// so the panel and the endpoint agree on which round is open.
const openApprovalReturn = computed(() => {
  const openId = request.value?.approval_return_eligibility?.open_return_id
  return openId ? request.value.approval_returns?.find((entry) => entry.id === openId) ?? null : null
})
// Stage 52 — "3" for a single-day target, "5–10" for a range.
const stageTargetLabel = (st) => st.target_days_min === st.target_days_max
  ? String(st.target_days_max)
  : `${st.target_days_min}–${st.target_days_max}`
const timelineMovement = (entry) => entry.from_stage
  ? `${name(entry.from_stage)} ${locale.value === 'ar' ? '←' : '→'} ${name(entry.to_stage)}`
  : name(entry.to_stage)
const transitions = computed(() => {
  if (request.value?.available_transitions?.length) return request.value.available_transitions
  return (request.value?.available_actions ?? []).map((action) => ({
    action,
    is_exception: false,
    requires_comment: false,
  }))
})
const normalActions = computed(() => transitions.value.filter((item) => !item.is_exception))
const exceptionActions = computed(() => transitions.value.filter((item) => item.is_exception))
const canAct = computed(() => transitions.value.length > 0)
// Stage 56 — advisory only; the manager can still pick any of the 3 routes.
const ADMINISTRATIVE_ROUTE_ACTIONS = {
  hr: 'route_to_hr',
  diwan: 'route_to_diwan',
  committee_secretary: 'route_to_committee_secretary',
}
const suggestedRoutingAction = computed(() => {
  if (request.value?.current_stage?.code !== 'administrative_routing') return null
  const route = request.value?.request_type?.default_administrative_route
  return route ? ADMINISTRATIVE_ROUTE_ACTIONS[route] ?? null : null
})
// Stage 66 — a concluded request may be re-presented for one of six
// enumerated reasons, never a plain "I disagree with the outcome" attempt.
const isReopenable = computed(() => REOPENABLE_STATUS_CODES.includes(request.value?.status?.code))
// Stage 72 — Appendix 57's groups for this request's own type.
const documentSections = computed(() => groupDocuments(request.value?.required_documents))
const currentStageName = computed(() => request.value?.current_stage ? name(request.value.current_stage) : '')

// Restyled pass — a tab that can genuinely be empty says so, rather than
// showing a bare card border with nothing in it. Each mirrors the exact
// v-if conditions its own panels already carried.
const hasGatesTabContent = computed(() => request.value?.current_stage?.code === 'requirements_check' || !!request.value?.control_gates)
const hasApprovalsTabContent = computed(() => Boolean(
  request.value?.approval_referrals?.length || request.value?.approval_referral_eligibility?.can_record
  || request.value?.approval_returns?.length || request.value?.approval_return_eligibility?.can_record
  || request.value?.approvals?.length
  || isReopenable.value,
))
const hasLegalTabContent = computed(() => Boolean(
  request.value?.legal_review || canDispatchLegalReview.value || request.value?.committee_summary,
))

function docLabel(doc) {
  return documentLabel(doc, locale.value)
}

function docCondition(doc) {
  return documentCondition(doc, locale.value)
}

function fileSize(bytes) {
  if (!Number.isFinite(bytes)) return t('common.none')
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

function isPreviewable(attachment) {
  return attachment?.mime_type === 'application/pdf' || attachment?.mime_type?.startsWith('image/')
}

function isImage(attachment) {
  return attachment?.mime_type?.startsWith('image/')
}

function clearAttachmentPreview() {
  if (attachmentPreviewUrl.value) URL.revokeObjectURL(attachmentPreviewUrl.value)
  attachmentPreviewUrl.value = ''
}

function closeAttachmentPreview() {
  if (attachmentPreviewing.value) return
  clearAttachmentPreview()
  selectedAttachment.value = null
  attachmentPreviewError.value = ''
}

async function openAttachmentPreview(attachment) {
  clearAttachmentPreview()
  selectedAttachment.value = attachment
  attachmentPreviewing.value = true
  attachmentPreviewError.value = ''
  try {
    const { data } = await api.get(attachment.preview_url, { responseType: 'blob' })
    attachmentPreviewUrl.value = URL.createObjectURL(data)
  } catch (requestError) {
    attachmentPreviewError.value = requestError.response?.data?.message ?? t('attachments.previewFailed')
  } finally {
    attachmentPreviewing.value = false
  }
}

async function downloadAttachment(attachment) {
  try {
    const { data } = await api.get(attachment.preview_url, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.original_name
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 0)
  } catch {
    actionError.value = t('attachments.downloadFailed')
  }
}

/** Stage 54 — [D] Art. 45's 6-question jurisdiction test, kept as a draft form. */
function blankJurisdictionTest() {
  return {
    has_legal_basis: '',
    employee_covered: '',
    within_municipal_jurisdiction: '',
    committee_decides: '',
    final_approval_authority: '',
    requires_central_approval: '',
  }
}

function syncJurisdictionTestForm() {
  const existing = request.value?.jurisdiction_test
  jurisdictionTestForm.value = existing
    ? {
        has_legal_basis: existing.has_legal_basis ? 'yes' : 'no',
        employee_covered: existing.employee_covered ? 'yes' : 'no',
        within_municipal_jurisdiction: existing.within_municipal_jurisdiction ? 'yes' : 'no',
        committee_decides: existing.committee_decides ? 'yes' : 'no',
        final_approval_authority: existing.final_approval_authority || '',
        requires_central_approval: existing.requires_central_approval ? 'yes' : 'no',
      }
    : blankJurisdictionTest()
}

async function saveJurisdictionTest() {
  if (jurisdictionTestSaving.value) return
  jurisdictionTestSaving.value = true
  jurisdictionTestError.value = ''
  try {
    const { data } = await api.patch(`/requests/${request.value.id}/jurisdiction-test`, {
      has_legal_basis: jurisdictionTestForm.value.has_legal_basis === 'yes',
      employee_covered: jurisdictionTestForm.value.employee_covered === 'yes',
      within_municipal_jurisdiction: jurisdictionTestForm.value.within_municipal_jurisdiction === 'yes',
      committee_decides: jurisdictionTestForm.value.committee_decides === 'yes',
      final_approval_authority: jurisdictionTestForm.value.final_approval_authority.trim(),
      requires_central_approval: jurisdictionTestForm.value.requires_central_approval === 'yes',
    })
    request.value = data.data
    syncJurisdictionTestForm()
  } catch (requestError) {
    jurisdictionTestError.value = requestError.response?.data?.errors?.final_approval_authority?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.jurisdictionTest.saveFailed')
  } finally {
    jurisdictionTestSaving.value = false
  }
}

// Stage 68 — [D] Art. 21. Dispatching a file to the legal member is the
// rapporteur's coordinating act (Appendix 6's RACI), gated by
// `legal_review.edit`; recording the verdict itself belongs to the legal
// member's own screen, so nothing here writes a review.
const auth = useAuthStore()
const canDispatchLegalReview = computed(() => auth.can('legal_review', 'edit'))
const dispatchingLegalReview = ref(false)
const legalReviewError = ref('')

async function sendToLegalReview() {
  if (dispatchingLegalReview.value) return
  dispatchingLegalReview.value = true
  legalReviewError.value = ''
  try {
    await api.post(`/requests/${request.value.id}/legal-reviews/request`)
    await load()
  } catch (requestError) {
    legalReviewError.value = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.legalReview.sendFailed')
  } finally {
    dispatchingLegalReview.value = false
  }
}

// Stage 100 — Appendix 6 row 15: the archive is recordable only while the file
// stands on one of Art. 37's final paths and is not yet closed.
const ARCHIVABLE_STATUSES = ['executed', 'not_approved', 'outside_jurisdiction']
const archiveEditable = computed(() =>
  !request.value?.closure && ARCHIVABLE_STATUSES.includes(request.value?.status?.code),
)

// Stage 100 — Appendix 6 row 14: المقرر issues the file's current notice.
const noticeIssuing = ref(false)
const noticeError = ref('')

async function issueNotice() {
  noticeIssuing.value = true
  noticeError.value = ''
  try {
    const { data } = await api.post(`/requests/${request.value.id}/notices/issue`)
    request.value = data.data
  } catch (requestError) {
    noticeError.value = requestError.response?.data?.message ?? t('employeeNotices.issueFailed')
  } finally {
    noticeIssuing.value = false
  }
}

/** Stage 47 — flips the auto-derived flag; the PATCH returns the full detail resource. */
async function toggleFinancialImpact() {
  if (financialImpactSaving.value) return
  financialImpactSaving.value = true
  financialImpactError.value = ''
  try {
    const { data } = await api.patch(`/requests/${request.value.id}/financial-impact`, {
      has_financial_impact: !request.value.has_financial_impact,
    })
    request.value = data.data
  } catch (requestError) {
    financialImpactError.value = requestError.response?.data?.message ?? t('requestDetail.financialImpact.updateFailed')
  } finally {
    financialImpactSaving.value = false
  }
}

// Stage 66 — the target-stage picker is the same lookup Stage 64's appeal
// outcome execution already uses (excludes the 4 pre-committee stages).
async function loadRedoStageOptions() {
  if (redoStageOptions.value.length) return
  redoStagesLoading.value = true
  redoStagesError.value = ''
  try {
    const { data } = await api.get('/appeals/redo-stage-options')
    redoStageOptions.value = data.data ?? []
  } catch {
    redoStagesError.value = t('requestDetail.reopen.loadStagesFailed')
  } finally {
    redoStagesLoading.value = false
  }
}

function openReopenPanel() {
  reopenPanelOpen.value = true
  reopenReasonCode.value = ''
  reopenTargetStageId.value = ''
  reopenNote.value = ''
  reopenError.value = ''
  loadRedoStageOptions()
}

function closeReopenPanel() {
  if (reopenSubmitting.value) return
  reopenPanelOpen.value = false
  reopenError.value = ''
}

async function submitReopen() {
  if (reopenSubmitting.value) return
  reopenSubmitting.value = true
  reopenError.value = ''
  try {
    const { data } = await api.patch(`/requests/${request.value.id}/reopen`, {
      reason_code: reopenReasonCode.value,
      target_stage_id: reopenTargetStageId.value,
      note: reopenNote.value || null,
    })
    request.value = data.data
    reopenPanelOpen.value = false
  } catch (requestError) {
    reopenError.value = requestError.response?.data?.errors?.reason_code?.[0]
      ?? requestError.response?.data?.errors?.target_stage_id?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.reopen.failed')
  } finally {
    reopenSubmitting.value = false
  }
}

async function load() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  try {
    const { data } = await api.get(`/requests/${route.params.id}`)
    request.value = data.data
    syncJurisdictionTestForm()
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('requestDetail.loadFailed')
  } finally {
    loading.value = false
  }
}

async function transition(action, suppliedComment = comment.value) {
  if (acting.value) return

  acting.value = true
  activeAction.value = action
  actionError.value = ''
  try {
    const payload = { action }
    if (suppliedComment.trim()) payload.comment = suppliedComment.trim()
    const { data } = await api.post(`/requests/${request.value.id}/transition`, payload)
    request.value = data.data
    comment.value = ''
    selectedException.value = null
    exceptionReason.value = ''
    exceptionError.value = ''
    pendingApprove.value = false
    return true
  } catch (requestError) {
    const message = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.actionFailed')
    if (selectedException.value) exceptionError.value = message
    else actionError.value = message
    return false
  } finally {
    acting.value = false
    activeAction.value = ''
  }
}

// Signatures have been removed from the system — clicking "Approve" opens a
// plain confirmation before the transition actually submits.
function openApproveConfirm() {
  pendingApprove.value = true
}

function closeApproveConfirm() {
  if (acting.value) return
  pendingApprove.value = false
}

async function confirmApprove() {
  await transition('approve')
}

// Stage 16 — exception actions pause for an explicit reason before execution.
function openException(exception) {
  selectedException.value = exception
  exceptionReason.value = ''
  exceptionError.value = ''
}

function closeException() {
  if (acting.value) return
  selectedException.value = null
  exceptionReason.value = ''
  exceptionError.value = ''
}

async function submitException() {
  exceptionError.value = ''
  if (!exceptionReason.value.trim()) {
    exceptionError.value = t('requestDetail.reasonRequired')
    return
  }
  await transition(selectedException.value.action, exceptionReason.value)
}

watch(() => route.params.id, load)
onMounted(load)
onBeforeUnmount(clearAttachmentPreview)
</script>

<template>
  <section class="detail">
    <RouterLink class="back" :to="{ name: 'requests' }">{{ t('requestDetail.back') }}</RouterLink>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="request">
      <!-- Cover band. -->
      <header class="cover" :class="{ overdue: request.is_overdue }">
        <div>
          <!-- A request before the قيد genuinely has no reference number; its
               intake receipt is the only handle that exists, and labelling it
               as such keeps the two distinct. -->
          <p class="reference ltr">{{ request.reference_number || request.intake_receipt_number || `#${request.id}` }}</p>
          <p v-if="!request.reference_number && request.intake_receipt_number" class="reference-hint">{{ t('requestDetail.awaitingRegistration') }}</p>
          <h2>{{ request.title }}</h2>
        </div>
        <span v-if="request.status" class="status" :style="{ '--status-color': request.status.color || 'var(--color-muted)' }">
          {{ name(request.status) }}
        </span>
      </header>

      <!-- Stage 17 — a breach remains visible without replacing workflow status. -->
      <p v-if="request.is_overdue" class="sla-alert" role="status">
        {{ t('requestDetail.overdue', { date: date(request.due_date) }) }}
      </p>

      <RequestStageRail
        variant="full"
        :stage-progress="request.stage_progress"
        :stage-timeliness="request.stage_timeliness"
        :current-stage-name="currentStageName"
      />

      <!-- Stage 83 — [D] Appendices 17 and 18's المسؤول الحالي / الإجراء
           التالي, promoted out of the summary grid: the appendix says this
           pair must never be blank, which is exactly the burial risk of
           ranking it field 6 of 11. -->
      <section v-if="request.responsibility" class="routing-slip">
        <div>
          <span>{{ t('lifecycle.responsibility.label') }}</span>
          <strong>{{ t(`lifecycle.responsibility.parties.${request.responsibility.responsible.code}`) }}</strong>
        </div>
        <div>
          <span>{{ t('lifecycle.responsibility.nextAction') }}</span>
          <strong>{{ t(`lifecycle.responsibility.actions.${request.responsibility.next_action.code}`) }}</strong>
        </div>
      </section>

      <!-- Actions. The primary transition is the one solid button; exception
           actions sit behind a disclosure so they stop competing with it. -->
      <section v-if="canAct" class="card card-flat card-pad action-panel">
        <h3>{{ t('requestDetail.actions') }}</h3>
        <p>{{ t('requestDetail.actionHint') }}</p>
        <label v-if="normalActions.length">
          {{ t('requestDetail.comment') }}
          <textarea v-model="comment" rows="2" maxlength="5000" :disabled="acting" />
        </label>
        <p v-if="actionError" class="action-error" role="alert">{{ actionError }}</p>
        <div class="action-buttons">
          <button
            v-for="item in normalActions"
            :key="item.action"
            class="primary"
            type="button"
            :disabled="acting"
            @click="item.action === 'approve' ? openApproveConfirm() : transition(item.action)"
          >
            {{ acting && activeAction === item.action ? t('requestDetail.processing') : actionLabel(item.action) }}
          </button>
        </div>
        <details v-if="exceptionActions.length" class="exception-actions">
          <summary>{{ t('requestDetail.exceptionActions') }}</summary>
          <div class="action-buttons">
            <button
              v-for="item in exceptionActions"
              :key="item.action"
              class="exception-button"
              :class="{ destructive: ['reject_review', 'reject_formally', 'reject_by_committee', 'cancel'].includes(item.action) }"
              type="button"
              :disabled="acting"
              @click="openException(item)"
            >
              {{ actionLabel(item.action) }}
              <span v-if="item.action === suggestedRoutingAction" class="suggested-badge">
                {{ t('requestDetail.suggestedRoute') }}
              </span>
            </button>
          </div>
        </details>
      </section>

      <!-- Stage 54 — [D] Art. 45's jurisdiction test, answered once at requirements_check. -->
      <section v-if="request.current_stage?.code === 'requirements_check'" class="card card-flat card-pad jurisdiction-test">
        <h3>{{ t('requestDetail.jurisdictionTest.title') }}</h3>
        <p>{{ t('requestDetail.jurisdictionTest.hint') }}</p>
        <p v-if="request.jurisdiction_test" class="state">{{ t('requestDetail.jurisdictionTest.recorded') }}</p>
        <p v-else class="action-error">{{ t('requestDetail.jurisdictionTest.notRecorded') }}</p>
        <!-- Stage 84 — whose answers these are. -->
        <p v-if="request.control_gates?.intake?.jurisdiction_test?.recorded_by">
          {{ t('requestDetail.jurisdictionTest.recordedBy', {
            name: request.control_gates.intake.jurisdiction_test.recorded_by.name,
            at: dateTime(request.control_gates.intake.jurisdiction_test.recorded_at),
          }) }}
        </p>
        <fieldset :disabled="jurisdictionTestSaving">
          <div class="grid">
            <label>
              {{ t('requestDetail.jurisdictionTest.q1') }}
              <select v-model="jurisdictionTestForm.has_legal_basis">
                <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
                <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
                <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
              </select>
            </label>
            <label>
              {{ t('requestDetail.jurisdictionTest.q2') }}
              <select v-model="jurisdictionTestForm.employee_covered">
                <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
                <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
                <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
              </select>
            </label>
            <label>
              {{ t('requestDetail.jurisdictionTest.q3') }}
              <select v-model="jurisdictionTestForm.within_municipal_jurisdiction">
                <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
                <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
                <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
              </select>
            </label>
            <label>
              {{ t('requestDetail.jurisdictionTest.q4') }}
              <select v-model="jurisdictionTestForm.committee_decides">
                <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
                <option value="yes">{{ t('requestDetail.jurisdictionTest.binding') }}</option>
                <option value="no">{{ t('requestDetail.jurisdictionTest.advisory') }}</option>
              </select>
            </label>
            <label class="wide">
              {{ t('requestDetail.jurisdictionTest.q5') }}
              <input v-model="jurisdictionTestForm.final_approval_authority" type="text" maxlength="255" />
            </label>
            <label>
              {{ t('requestDetail.jurisdictionTest.q6') }}
              <select v-model="jurisdictionTestForm.requires_central_approval">
                <option value="" disabled>{{ t('requestDetail.jurisdictionTest.choose') }}</option>
                <option value="yes">{{ t('requestDetail.jurisdictionTest.yes') }}</option>
                <option value="no">{{ t('requestDetail.jurisdictionTest.no') }}</option>
              </select>
            </label>
          </div>
        </fieldset>
        <p v-if="jurisdictionTestError" class="action-error" role="alert">{{ jurisdictionTestError }}</p>
        <button
          v-can="'notes_attachments.edit'"
          class="ghost"
          type="button"
          :disabled="jurisdictionTestSaving"
          @click="saveJurisdictionTest"
        >
          {{ jurisdictionTestSaving ? t('requestDetail.jurisdictionTest.saving') : t('requestDetail.jurisdictionTest.save') }}
        </button>
      </section>

      <!-- Tab strip. -->
      <div class="tabs" role="tablist" :aria-label="t('requestDetail.tabsLabel')" @keydown.right.prevent="stepTab(1)" @keydown.left.prevent="stepTab(-1)">
        <button
          v-for="tab in TABS"
          :id="`tab-${tab}`"
          :key="tab"
          class="tab"
          role="tab"
          type="button"
          :aria-selected="activeTab === tab ? 'true' : 'false'"
          :aria-controls="`panel-${tab}`"
          :tabindex="activeTab === tab ? 0 : -1"
          @click="selectTab(tab)"
        >
          {{ t(`requestDetail.tabs.${tab}`) }}
        </button>
      </div>

      <!-- الملف -->
      <div id="panel-file" v-show="activeTab === 'file'" role="tabpanel" aria-labelledby="tab-file">
        <div class="columns">
          <div class="main-column">
            <section class="card card-flat card-pad summary">
              <div>
                <span>{{ t('requests.department') }}</span>
                <strong>{{ name(request.department) }}</strong>
              </div>
              <!-- Stage 95 — صاحب العلاقة, rendered only when somebody filed
                   on this employee's behalf. An ordinary self-filed request
                   would just say the same name twice. -->
              <div v-if="request.subject && request.subject.id !== request.created_by?.id">
                <span>{{ t('requestDetail.subjectUser') }}</span>
                <strong>{{ request.subject.name }}</strong>
              </div>
              <div v-if="request.subject && request.subject.id !== request.created_by?.id">
                <span>{{ t('requestDetail.filedBy') }}</span>
                <strong>{{ request.created_by?.name }}</strong>
              </div>
              <div>
                <span>{{ t('requests.type') }}</span>
                <strong>{{ name(request.request_type) }}</strong>
              </div>
              <div>
                <span>{{ t('requestDetail.submittedAt') }}</span>
                <strong>{{ dateTime(request.submitted_at || request.created_at) }}</strong>
              </div>
              <div v-if="request.due_date">
                <span>{{ t('requestDetail.dueDate') }}</span>
                <strong>{{ date(request.due_date) }}</strong>
              </div>
              <!-- Stage 51 — [A] §7's "المستندات الناقصة" flag, derived from status. -->
              <div>
                <span>{{ t('requestDetail.documentsComplete.label') }}</span>
                <strong>{{ request.documents_complete ? t('requestDetail.documentsComplete.yes') : t('requestDetail.documentsComplete.no') }}</strong>
              </div>
              <!-- Stage 52 — a non-blocking, per-stage soft-SLA indicator. -->
              <div v-if="request.stage_timeliness">
                <span>{{ t('requestDetail.stageTimeliness.label') }}</span>
                <strong>
                  <span class="timeliness" :class="`level-${request.stage_timeliness.level}`">
                    {{ t(`requestDetail.stageTimeliness.level.${request.stage_timeliness.level}`) }}
                  </span>
                  <br>
                  <small>{{ t('requestDetail.stageTimeliness.elapsed', { days: request.stage_timeliness.elapsed_days, target: stageTargetLabel(request.stage_timeliness) }) }}</small>
                  <template v-if="request.stage_timeliness.escalation">
                    <br>
                    <small>
                      {{ t('requestDetail.stageTimeliness.escalation') }}:
                      {{ t(`requestDetail.stageTimeliness.level.${request.stage_timeliness.escalation.level}`) }}
                      —
                      {{ t('requestDetail.stageTimeliness.escalated', { date: dateTime(request.stage_timeliness.escalation.notified_at) }) }}
                    </small>
                  </template>
                </strong>
              </div>
              <!-- Stage 47 — derived from request type at intake, correctable by
                   whoever studies the file (rides notes_attachments,edit). -->
              <div>
                <span>{{ t('requestDetail.financialImpact.label') }}</span>
                <strong>
                  {{ request.has_financial_impact ? t('requestDetail.financialImpact.yes') : t('requestDetail.financialImpact.no') }}
                  <button
                    v-can="'notes_attachments.edit'"
                    class="ghost financial-impact-toggle"
                    type="button"
                    :disabled="financialImpactSaving"
                    @click="toggleFinancialImpact"
                  >
                    {{ t('requestDetail.financialImpact.toggle') }}
                  </button>
                </strong>
              </div>
            </section>
            <p v-if="financialImpactError" class="action-error" role="alert">{{ financialImpactError }}</p>

            <section class="card card-flat card-pad description">
              <h3>{{ t('requestDetail.description') }}</h3>
              <p>{{ request.description || t('requestDetail.noDescription') }}</p>
              <h3 class="reasons-heading">{{ t('requestDetail.reasons') }}</h3>
              <p>{{ request.reasons || t('requestDetail.noReasons') }}</p>
            </section>

            <section class="card card-flat card-pad timeline">
              <h3>{{ t('requestDetail.timeline') }}</h3>
              <p v-if="!request.timeline?.length" class="state">{{ t('requestDetail.noTimeline') }}</p>
              <ol v-else>
                <li v-for="entry in request.timeline" :key="entry.id">
                  <span class="dot" />
                  <div>
                    <strong>{{ actionLabel(entry.action) }}</strong>
                    <p v-if="entry.to_stage">{{ timelineMovement(entry) }}</p>
                    <p v-if="entry.comment" class="entry-comment">{{ entry.comment }}</p>
                    <small>
                      {{ entry.acted_by?.name || t('common.none') }}
                      <template v-if="entry.body"> · {{ name(entry.body) }}</template>
                      · {{ dateTime(entry.acted_at) }}
                    </small>
                    <ul v-if="entry.documents?.length" class="entry-documents">
                      <li v-for="(doc, docIndex) in entry.documents" :key="docIndex">
                        <span class="doc-kind">{{ t(`requestDetail.linkedDocuments.kinds.${doc.kind}`) }}</span>
                        <span class="doc-label">{{ doc.label }}</span>
                        <span v-if="doc.reference" class="doc-reference ltr">{{ doc.reference }}</span>
                        <span v-if="doc.section" class="doc-section">{{ fileSectionName(doc.section) }}</span>
                      </li>
                    </ul>
                  </div>
                </li>
              </ol>
            </section>
          </div>

          <aside class="side-column">
            <section class="card card-flat card-pad attachments">
              <h3>{{ t('attachments.title') }}</h3>
              <p v-if="!request.attachments?.length" class="state">{{ t('requestDetail.noAttachments') }}</p>
              <ul v-else>
                <li v-for="attachment in request.attachments" :key="attachment.id">
                  <strong class="file-name ltr">{{ attachment.original_name }}</strong>
                  <small>{{ attachment.label || attachment.mime_type }} · {{ fileSize(attachment.size_bytes) }}</small>
                  <small class="doc-section">{{ fileSectionName(attachment.file_section) }}</small>
                  <small v-if="attachment.execution_evidence_type" class="evidence-tag">
                    {{ t('requestExecution.evidenceBadge') }} — {{ t(`requestExecution.evidenceTypes.${attachment.execution_evidence_type}`) }}
                  </small>
                  <div class="attachment-actions">
                    <button
                      v-if="isPreviewable(attachment)"
                      class="ghost"
                      type="button"
                      @click="openAttachmentPreview(attachment)"
                    >{{ t('attachments.preview') }}</button>
                    <button v-else class="ghost" type="button" @click="downloadAttachment(attachment)">{{ t('attachments.download') }}</button>
                  </div>
                </li>
              </ul>
              <!-- Only the filer attaches (Request::attachmentRight()); can_attach is
                   that same server-side rule, so the form never offers a refused upload. -->
              <FileUpload v-if="request.can_attach" v-can="'notes_attachments.add'" :request-id="request.id" @uploaded="load" />
            </section>

            <section v-if="documentSections.length" class="card card-flat card-pad checklist">
              <h3>{{ t('requestDetail.requiredDocuments.title') }}</h3>
              <p class="state">{{ t('requestDetail.requiredDocuments.hint') }}</p>
              <div v-for="section in documentSections" :key="section.group" class="doc-group">
                <h4>{{ t(`intake.requiredDocuments.groups.${section.group}`) }}</h4>
                <ul>
                  <li v-for="(doc, index) in section.items" :key="index">
                    {{ docLabel(doc) }}
                    <span v-if="docCondition(doc)" class="doc-condition">({{ docCondition(doc) }})</span>
                  </li>
                </ul>
              </div>
            </section>

            <section class="card card-flat card-pad"><RequestNotes :request-id="request.id" /></section>
          </aside>
        </div>
      </div>

      <!-- البوابات -->
      <div id="panel-gates" v-show="activeTab === 'gates'" role="tabpanel" aria-labelledby="tab-gates" class="stacked">
        <p v-if="!hasGatesTabContent" class="state">{{ t('requestDetail.tabs.empty') }}</p>

        <!-- Stage 78 — [D] Appendix 63's مصفوفة الرقابة الداخلية for this one file. -->
        <section v-if="request.control_gates" class="card card-flat card-pad summary closure">
          <h3>{{ t('controlGates.title') }}</h3>
          <p class="hint">{{ t('controlGates.intro') }}</p>

          <!-- Stage 98 — [D] Appendix 6 row 3. Above gate 1 because it comes
               before it: HR assembles الملف الوظيفي at receive_and_register,
               then المقرر checks the committee file at the قيد. -->
          <div
            v-if="request.current_stage?.code === 'receive_and_register' || request.control_gates.employment_file.record"
            class="gate-block"
          >
            <h4>{{ t('controlGates.employmentFile.title') }}</h4>
            <p class="hint">{{ t('controlGates.employmentFile.question') }}</p>
            <p class="hint">{{ t('controlGates.employmentFile.owner') }}</p>
            <IntakeGatePanel
              :request-id="request.id"
              :required-documents="request.control_gates.employment_file.required_documents"
              :record="request.control_gates.employment_file.record"
              :refusal="request.control_gates.employment_file.refusal"
              :recorded-by="request.control_gates.employment_file.prepared_by"
              :recorded-at="request.control_gates.employment_file.prepared_at"
              endpoint="employment-file"
              attestation-field="assembled"
              grant="notes_attachments.add"
              copy="employmentFile"
              @updated="onGateUpdated"
            />
          </div>

          <div
            v-if="request.current_stage?.code === 'requirements_check' || request.control_gates.intake.record"
            class="gate-block"
          >
            <h4>{{ t('controlGates.intake.title') }}</h4>
            <p class="hint">{{ t('controlGates.intake.question') }}</p>
            <IntakeGatePanel
              :request-id="request.id"
              :required-documents="request.control_gates.intake.required_documents"
              :record="request.control_gates.intake.record"
              :refusal="request.control_gates.intake.refusal"
              :recorded-by="request.control_gates.intake.recorded_by"
              :recorded-at="request.control_gates.intake.recorded_at"
              @updated="onGateUpdated"
            />
          </div>

          <div
            v-if="request.current_stage?.code === 'final_approval_archiving' || request.control_gates.execution_soundness.record"
            class="gate-block"
          >
            <h4>{{ t('controlGates.soundness.title') }}</h4>
            <p class="hint">{{ t('controlGates.soundness.question') }}</p>
            <RequestSoundnessPanel
              :request-id="request.id"
              :record="request.control_gates.execution_soundness.record"
              :derived="request.control_gates.execution_soundness.derived"
              :refusal="request.control_gates.execution_soundness.refusal"
              @updated="onGateUpdated"
            />
          </div>

          <div
            v-if="request.suspensions?.length || !request.control_gates.suspension.refusal"
            class="gate-block"
          >
            <h4>{{ t('controlGates.suspension.title') }}</h4>
            <p class="hint">{{ t('controlGates.suspension.question') }}</p>
            <RequestSuspensionPanel
              :request-id="request.id"
              :suspensions="request.suspensions ?? []"
              :refusal="request.control_gates.suspension.refusal"
              :open-id="request.control_gates.suspension.open_id"
              @updated="onGateUpdated"
            />
          </div>
        </section>
      </div>

      <!-- الاعتمادات -->
      <div id="panel-approvals" v-show="activeTab === 'approvals'" role="tabpanel" aria-labelledby="tab-approvals" class="stacked">
        <p v-if="!hasApprovalsTabContent" class="state">{{ t('requestDetail.tabs.empty') }}</p>

        <!-- Stage 80 — [D] Art. 30's سجل الإحالات للاعتماد. -->
        <section
          v-if="request.approval_referrals?.length || request.approval_referral_eligibility?.can_record"
          class="card card-flat card-pad summary closure"
        >
          <h3>{{ t('approvalReferral.title') }}</h3>
          <ol v-if="request.approval_referrals?.length" class="return-list">
            <li v-for="entry in request.approval_referrals" :key="entry.id">
              <div class="return-head">
                <span class="return-kind">{{ entry.letter_number }}</span>
                <span>{{ entry.referred_to_body }}</span>
                <span class="muted">{{ date(entry.referred_at) }}</span>
              </div>
              <dl>
                <div>
                  <span>{{ t('approvalReferral.referredFrom') }}</span>
                  <strong>{{ entry.referred_from_stage ? name(entry.referred_from_stage) : '—' }}</strong>
                </div>
                <div>
                  <span>{{ t('approvalReferral.recordedBy') }}</span>
                  <strong>{{ entry.recorded_by?.name ?? '—' }}</strong>
                </div>
                <div>
                  <span>{{ t('approvalReferral.fields.result_outcome') }}</span>
                  <strong>
                    {{ entry.result_outcome
                      ? t(`approvalReferral.outcomes.${entry.result_outcome}`)
                      : t('approvalReferral.awaitingResult') }}
                  </strong>
                </div>
                <div v-if="entry.result_received_at">
                  <span>{{ t('approvalReferral.fields.result_received_at') }}</span>
                  <strong>{{ date(entry.result_received_at) }}</strong>
                </div>
                <div v-if="entry.approval_decision_number">
                  <span>{{ t('approvalReferral.fields.approval_decision_number') }}</span>
                  <strong>{{ entry.approval_decision_number }}</strong>
                </div>
                <div v-if="entry.result_note" class="wide">
                  <span>{{ t('approvalReferral.fields.result_note') }}</span>
                  <strong>{{ entry.result_note }}</strong>
                </div>
              </dl>
            </li>
          </ol>
          <ApprovalReferralPanel
            :request-id="request.id"
            :refusal="request.approval_referral_eligibility?.reason"
            :open-referral="openApprovalReferral"
            @updated="onApprovalReturnUpdated"
          />
        </section>

        <!-- Stage 77 — [D] Art. 94's إعادة المحضر من جهة الاعتماد. -->
        <section
          v-if="request.approval_returns?.length || request.approval_return_eligibility?.can_record"
          class="card card-flat card-pad summary closure"
        >
          <h3>{{ t('approvalReturn.title') }}</h3>
          <ol v-if="request.approval_returns?.length" class="return-list">
            <li v-for="entry in request.approval_returns" :key="entry.id">
              <div class="return-head">
                <span class="return-kind">{{ t(`approvalReturn.kinds.${entry.return_kind}`) }}</span>
                <span>{{ t(`approvalReturn.reasons.${entry.return_reason_code}`) }}</span>
                <span class="muted">{{ date(entry.received_at) }}</span>
              </div>
              <dl>
                <div class="wide">
                  <span>{{ t('approvalReturn.fields.return_note') }}</span>
                  <strong>{{ entry.return_note }}</strong>
                </div>
                <div>
                  <span>{{ t('approvalReturn.returnedFrom') }}</span>
                  <strong>{{ entry.returned_from_stage ? name(entry.returned_from_stage) : '—' }}</strong>
                </div>
                <div>
                  <span>{{ t('approvalReturn.fields.letter_number') }}</span>
                  <strong>{{ entry.letter_number ?? '—' }}</strong>
                </div>
                <div>
                  <span>{{ t('approvalReturn.recordedBy') }}</span>
                  <strong>{{ entry.recorded_by?.name ?? '—' }}</strong>
                </div>
                <div class="wide">
                  <span>{{ t('approvalReturn.fields.resolution_action') }}</span>
                  <strong>{{ entry.resolution_action ?? t('approvalReturn.notResolved') }}</strong>
                </div>
                <div v-if="entry.resolved_at">
                  <span>{{ t('approvalReturn.resolvedAt') }}</span>
                  <strong>{{ dateTime(entry.resolved_at) }}</strong>
                </div>
                <div v-if="entry.resolution_target_stage">
                  <span>{{ t('approvalReturn.resolvedTo') }}</span>
                  <strong>{{ name(entry.resolution_target_stage) }}</strong>
                </div>
              </dl>
            </li>
          </ol>
          <ApprovalReturnPanel
            :request-id="request.id"
            :refusal="request.approval_return_eligibility?.reason"
            :open-return="openApprovalReturn"
            @updated="onApprovalReturnUpdated"
          />
        </section>

        <ApprovalTrail :approvals="request.approvals ?? []" />

        <!-- Stage 66, Track J — [D] Arts. 34–37/78–79's re-presentation path;
             only shown once the request has genuinely concluded. -->
        <section v-if="isReopenable" v-can="'appeals.edit'" class="card card-flat card-pad action-panel reopen-panel">
          <h3>{{ t('requestDetail.reopen.title') }}</h3>
          <p>{{ t('requestDetail.reopen.hint') }}</p>
          <button v-if="!reopenPanelOpen" class="ghost" type="button" @click="openReopenPanel">
            {{ t('requestDetail.reopen.action') }}
          </button>
          <template v-else>
            <fieldset :disabled="reopenSubmitting">
              <label>
                {{ t('requestDetail.reopen.reasonLabel') }}
                <select v-model="reopenReasonCode">
                  <option value="" disabled>{{ t('requestDetail.reopen.chooseReason') }}</option>
                  <option v-for="code in REOPEN_REASON_CODES" :key="code" :value="code">
                    {{ t(`reopenReasons.${code}`) }}
                  </option>
                </select>
              </label>
              <label>
                {{ t('requestDetail.reopen.targetStageLabel') }}
                <select v-model="reopenTargetStageId">
                  <option value="" disabled>{{ t('requestDetail.reopen.chooseStage') }}</option>
                  <option v-for="stage in redoStageOptions" :key="stage.id" :value="stage.id">
                    {{ name(stage) }}
                  </option>
                </select>
              </label>
              <p v-if="redoStagesLoading" class="state">{{ t('common.loading') }}</p>
              <p v-if="redoStagesError" class="action-error" role="alert">{{ redoStagesError }}</p>
              <label>
                {{ t('requestDetail.reopen.noteLabel') }}
                <textarea v-model="reopenNote" rows="2" maxlength="5000" />
              </label>
            </fieldset>
            <p v-if="reopenError" class="action-error" role="alert">{{ reopenError }}</p>
            <div class="action-buttons">
              <button
                class="primary"
                type="button"
                :disabled="reopenSubmitting || !reopenReasonCode || !reopenTargetStageId"
                @click="submitReopen"
              >
                {{ reopenSubmitting ? t('requestDetail.reopen.submitting') : t('requestDetail.reopen.submit') }}
              </button>
              <button class="ghost" type="button" :disabled="reopenSubmitting" @click="closeReopenPanel">
                {{ t('requestDetail.reopen.cancel') }}
              </button>
            </div>
          </template>
        </section>
      </div>

      <!-- اللجنة والقانون -->
      <div id="panel-legal" v-show="activeTab === 'legal'" role="tabpanel" aria-labelledby="tab-legal" class="stacked">
        <p v-if="!hasLegalTabContent" class="state">{{ t('requestDetail.tabs.empty') }}</p>

        <!-- Stage 68 — [D] Art. 21's pre-meeting legal review. -->
        <section v-if="request.legal_review || canDispatchLegalReview" class="card card-flat card-pad summary legal-review">
          <h3>{{ t('requestDetail.legalReview.title') }}</h3>
          <template v-if="request.legal_review">
            <div>
              <span>{{ t('requestDetail.legalReview.verdict') }}</span>
              <strong :class="request.legal_review.permits_agenda ? 'ok' : 'warn'">
                {{ t(`meetingsUnit.legalReview.verdicts.${request.legal_review.verdict}`) }}
              </strong>
            </div>
            <div>
              <span>{{ t('requestDetail.legalReview.reviewedBy') }}</span>
              <strong>{{ request.legal_review.reviewed_by?.name ?? t('common.none') }}</strong>
            </div>
            <div>
              <span>{{ t('requestDetail.legalReview.reviewedAt') }}</span>
              <strong>{{ date(request.legal_review.reviewed_at) }}</strong>
            </div>
            <div v-if="request.legal_review.primary_legislation">
              <span>{{ t('meetingsUnit.legalReview.fields.primaryLegislation') }}</span>
              <strong>{{ request.legal_review.primary_legislation }}</strong>
            </div>
            <div v-if="request.legal_review.legal_note" class="full">
              <span>{{ t('meetingsUnit.legalReview.fields.legalNote') }}</span>
              <strong>{{ request.legal_review.legal_note }}</strong>
            </div>
            <div v-if="request.legal_reviews_count > 1">
              <span>{{ t('requestDetail.legalReview.rounds') }}</span>
              <strong>{{ request.legal_reviews_count }}</strong>
            </div>
          </template>
          <p v-else class="muted">{{ t('requestDetail.legalReview.none') }}</p>

          <div v-can="'legal_review.edit'" class="full">
            <button
              class="ghost"
              type="button"
              :disabled="dispatchingLegalReview"
              @click="sendToLegalReview"
            >
              {{ dispatchingLegalReview
                ? t('requestDetail.legalReview.sending')
                : t('requestDetail.legalReview.send') }}
            </button>
            <p v-if="legalReviewError" class="alert">{{ legalReviewError }}</p>
          </div>
        </section>

        <!-- Stage 51 — [A] §7's committee-presentation fields. -->
        <section v-if="request.committee_summary" class="card card-flat card-pad summary committee-summary">
          <h3>{{ t('requestDetail.committeeSummary.title') }}</h3>
          <div>
            <span>{{ t('requestDetail.committeeSummary.meetingNumber') }}</span>
            <strong>{{ request.committee_summary.meeting_number || t('common.none') }}</strong>
          </div>
          <div>
            <span>{{ t('requestDetail.committeeSummary.meetingDate') }}</span>
            <strong>{{ date(request.committee_summary.meeting_date) }}</strong>
          </div>
          <div>
            <span>{{ t('requestDetail.committeeSummary.agendaItemNumber') }}</span>
            <strong>{{ request.committee_summary.agenda_item_number ?? t('common.none') }}</strong>
          </div>
          <div>
            <span>{{ t('requestDetail.committeeSummary.committeeResult') }}</span>
            <strong>
              {{ request.committee_summary.committee_result
                ? t(`decisions.outcome.${request.committee_summary.committee_result}`)
                : t('requestDetail.committeeSummary.resultPending') }}
            </strong>
          </div>
          <div v-if="request.committee_summary.decision_date">
            <span>{{ t('requestDetail.committeeSummary.decisionDate') }}</span>
            <strong>{{ date(request.committee_summary.decision_date) }}</strong>
          </div>
        </section>
      </div>

      <!-- المخرجات -->
      <div id="panel-outputs" v-show="activeTab === 'outputs'" role="tabpanel" aria-labelledby="tab-outputs" class="stacked">
        <!-- Stage 81 — [D] Appendix 71's بطاقة قياس زمن المعاملة. -->
        <section v-if="request.time_card?.length" class="card card-flat card-pad time-card">
          <h3>{{ t('requestDetail.timeCard.title') }}</h3>
          <p class="source">{{ t('requestDetail.timeCard.source') }}</p>
          <ul class="segments">
            <li v-for="segment in request.time_card" :key="segment.key">
              <span class="segment-code">T{{ segment.number }}</span>
              <span class="segment-label">{{ segment.label }}</span>
              <span class="segment-days" :class="{ pending: segment.days === null }">
                {{ segment.days === null
                  ? t('requestDetail.timeCard.pending')
                  : t('requestDetail.timeCard.days', { days: segment.days }) }}
              </span>
            </li>
          </ul>
        </section>

        <!-- Stage 83 — [D]'s lifecycle edge cases. -->
        <section class="card card-flat card-pad summary closure">
          <h3>{{ t('lifecycle.title') }}</h3>
          <p class="hint">{{ t('lifecycle.intro') }}</p>
          <RequestLifecyclePanel
            :request-id="request.id"
            :is-requester="request.created_by?.id === auth.user?.id"
          />
        </section>

        <!-- Stage 79 — [D] Art. 101's register for this file. -->
        <section v-if="request.employee_notices?.length || auth.can('meeting_outputs', 'edit')" class="card card-flat card-pad summary closure">
          <h3>{{ t('employeeNotices.title') }}</h3>
          <p class="hint">{{ t('employeeNotices.intro') }}</p>
          <button
            v-can="'meeting_outputs.edit'"
            class="btn btn-sm"
            type="button"
            :disabled="noticeIssuing"
            @click="issueNotice"
          >
            {{ t('employeeNotices.issue') }}
          </button>
          <p v-if="noticeError" class="alert warning" role="alert">{{ noticeError }}</p>
          <ul class="notice-list">
            <li v-for="notice in request.employee_notices" :key="notice.id">
              <div class="notice-head">
                <strong>{{ noticeText(notice, 'title') }}</strong>
                <span v-if="notice.moment_number" class="notice-moment">{{ t('employeeNotices.moment', { number: notice.moment_number }) }}</span>
                <span v-else class="notice-moment">{{ t(`notifications.events.${notice.event_type}`) }}</span>
                <span class="notice-date">{{ dateTime(notice.sent_at) }}</span>
              </div>
              <p>{{ noticeText(notice, 'body') }}</p>
              <small v-if="notice.issued_by">{{ t('employeeNotices.issuedBy', { name: notice.issued_by }) }}</small>
            </li>
          </ul>
        </section>

        <!-- Stage 76 — [D] النموذج 17's recorded execution card, read-only here. -->
        <section v-if="request.execution" class="card card-flat card-pad summary closure">
          <h3>{{ t('requestExecution.title') }}</h3>
          <dl>
            <div>
              <span>{{ t('requestExecution.executedAt') }}</span>
              <strong>{{ dateTime(request.execution.executed_at) }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.executedBy') }}</span>
              <strong>{{ request.execution.executed_by?.name ?? '—' }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.fields.executing_body') }}</span>
              <strong>{{ request.execution.executing_body ?? '—' }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.fields.effective_date') }}</span>
              <strong>{{ request.execution.effective_date ? date(request.execution.effective_date) : '—' }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.fields.approving_body') }}</span>
              <strong>{{ request.execution.approving_body ?? '—' }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.fields.approval_number') }}</span>
              <strong>{{ request.execution.approval_number ?? '—' }}</strong>
            </div>
            <div>
              <span>{{ t('requestExecution.fields.approval_date') }}</span>
              <strong>{{ request.execution.approval_date ? date(request.execution.approval_date) : '—' }}</strong>
            </div>
            <div class="wide">
              <span>{{ t('requestExecution.fields.action_taken') }}</span>
              <strong>{{ request.execution.action_taken ?? '—' }}</strong>
            </div>
            <div v-if="request.execution.financial_effect_note" class="wide">
              <span>{{ t('requestExecution.fields.financial_effect_note') }}</span>
              <strong>{{ request.execution.financial_effect_note }}</strong>
            </div>
          </dl>
          <h4>{{ t('requestExecution.checklistTitle') }}</h4>
          <ul class="audit-record">
            <li v-for="check in TRACKING_CHECKS" :key="check">
              <span>{{ t(`requestExecution.checks.${check}`) }}</span>
              <strong>{{ t(`requestExecution.answers.${request.execution_checklist?.[check] ?? 'no'}`) }}</strong>
            </li>
          </ul>
        </section>

        <!-- Stage 75 — [D] Art. 37's الإقفال. -->
        <!-- Stage 100 — [D] Appendix 6 row 15's two archive records. -->
        <section
          v-if="request.archive && (archiveEditable || request.archive.committee_file || request.archive.service_file)"
          class="card card-flat card-pad summary closure"
        >
          <h3>{{ t('requestArchive.title') }}</h3>
          <RequestArchivePanel
            :request-id="request.id"
            :archive="request.archive"
            :editable="archiveEditable"
            @updated="request = $event"
          />
        </section>

        <section v-if="request.closure || request.closure_eligibility?.can_close" class="card card-flat card-pad summary closure">
          <h3>{{ t('requestClosure.title') }}</h3>
          <template v-if="request.closure">
            <dl>
              <div>
                <span>{{ t('requestClosure.fields.final_result_code') }}</span>
                <strong>{{ t(`requestClosure.results.${request.closure.final_result_code}`) }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.closedAt') }}</span>
                <strong>{{ dateTime(request.closure.closed_at) }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.closedBy') }}</span>
                <strong>{{ request.closure.closed_by?.name ?? '—' }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.fields.approving_body') }}</span>
                <strong>{{ request.closure.approving_body ?? '—' }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.fields.final_decision_number') }}</span>
                <strong>{{ request.closure.final_decision_number ?? '—' }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.fields.execution_date') }}</span>
                <strong>{{ request.closure.execution_date ? date(request.closure.execution_date) : '—' }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.fields.executing_body') }}</span>
                <strong>{{ request.closure.executing_body ?? '—' }}</strong>
              </div>
              <div>
                <span>{{ t('requestClosure.fields.notice_status') }}</span>
                <strong>{{ t(`requestClosure.notice.${request.closure.notice_status}`) }}</strong>
              </div>
            </dl>
            <h4>{{ t('requestClosure.auditTitle') }}</h4>
            <ul class="audit-record">
              <li v-for="check in AUDIT_CHECKS" :key="check">
                <span>{{ t(`requestClosure.checks.${check}`) }}</span>
                <strong>{{ t(`requestClosure.answers.${request.closure_audit?.[check] ?? 'no'}`) }}</strong>
              </li>
            </ul>
          </template>
          <RequestClosurePanel
            v-else
            :request-id="request.id"
            :refusal="request.closure_eligibility?.reason"
            @closed="onClosed"
          />
        </section>
      </div>

      <Teleport to="body">
        <div v-if="selectedAttachment" class="modal-backdrop" @click.self="closeAttachmentPreview">
          <section class="attachment-modal" role="dialog" aria-modal="true" :aria-label="t('attachments.preview')">
            <div class="modal-actions">
              <strong class="file-name ltr">{{ selectedAttachment.original_name }}</strong>
              <button class="ghost" type="button" :disabled="attachmentPreviewing" @click="closeAttachmentPreview">×</button>
            </div>
            <p v-if="attachmentPreviewing" class="state">{{ t('common.loading') }}</p>
            <p v-else-if="attachmentPreviewError" class="action-error" role="alert">{{ attachmentPreviewError }}</p>
            <img v-else-if="attachmentPreviewUrl && isImage(selectedAttachment)" class="attachment-image" :src="attachmentPreviewUrl" :alt="selectedAttachment.original_name" />
            <iframe v-else-if="attachmentPreviewUrl" class="attachment-pdf" :src="attachmentPreviewUrl" :title="selectedAttachment.original_name" />
          </section>
        </div>

        <div v-if="selectedException" class="modal-backdrop" @click.self="closeException">
          <section
            class="modal reason-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="exception-title"
          >
            <h3 id="exception-title">
              {{ t('requestDetail.exceptionReasonTitle', { action: actionLabel(selectedException.action) }) }}
            </h3>
            <p>{{ t('requestDetail.exceptionReasonHint') }}</p>
            <form @submit.prevent="submitException">
              <label>
                {{ t('requestDetail.reason') }}
                <textarea
                  v-model="exceptionReason"
                  rows="4"
                  maxlength="5000"
                  required
                  autofocus
                  :disabled="acting"
                />
              </label>
              <p v-if="exceptionError" class="action-error" role="alert">{{ exceptionError }}</p>
              <div class="modal-actions">
                <button class="ghost" type="button" :disabled="acting" @click="closeException">
                  {{ t('common.cancel') }}
                </button>
                <button
                  class="exception-button"
                  :class="{ destructive: ['reject_review', 'reject_formally', 'reject_by_committee', 'cancel'].includes(selectedException.action) }"
                  type="submit"
                  :disabled="acting || !exceptionReason.trim()"
                >
                  {{ acting ? t('requestDetail.processing') : t('requestDetail.confirmException') }}
                </button>
              </div>
            </form>
          </section>
        </div>

        <div v-if="pendingApprove" class="modal-backdrop" @click.self="closeApproveConfirm">
          <section
            class="modal reason-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-approve-title"
          >
            <h3 id="confirm-approve-title">{{ t('requestDetail.confirmApprove.title') }}</h3>
            <p>{{ t('requestDetail.confirmApprove.body') }}</p>
            <p v-if="actionError" class="action-error" role="alert">{{ actionError }}</p>
            <div class="modal-actions">
              <button class="ghost" type="button" :disabled="acting" @click="closeApproveConfirm">
                {{ t('common.cancel') }}
              </button>
              <button class="primary" type="button" :disabled="acting" @click="confirmApprove">
                {{ acting ? t('requestDetail.processing') : t('requestDetail.confirmApprove.confirm') }}
              </button>
            </div>
          </section>
        </div>
      </Teleport>
    </template>
  </section>
</template>

<style scoped>
.detail { max-inline-size: var(--page-max); margin-inline: auto; padding: var(--space-5) 0; }
.back { display: inline-block; margin-bottom: var(--space-3); color: var(--color-brand-text); font-size: var(--text-sm); text-decoration: none; }
.back:hover { text-decoration: underline; }

/* -- Cover ----------------------------------------------------------------- */
.cover {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-4) var(--space-5);
  margin-bottom: var(--space-4);
  border: 1px solid var(--color-border);
  border-inline-start: 4px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}
.cover.overdue { border-inline-start-color: var(--color-danger-fg); }
.cover h2 { margin: 0.15rem 0 0; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.reference { margin: 0; color: var(--color-muted); font-family: var(--font-mono); font-size: var(--text-sm); font-variant-numeric: tabular-nums; }
.reference-hint { margin: 0.15rem 0 0; color: var(--color-info-fg); font-size: var(--text-xs); }
.sla-alert { padding: var(--space-3) var(--space-4); margin: 0 0 var(--space-4); border: 1px solid var(--color-warning-border); border-radius: var(--radius-lg); color: var(--color-warning-fg); background: var(--color-warning-bg); font-size: var(--text-base); }
.timeliness { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.5rem; border-radius: var(--radius-full); font-size: var(--text-sm); font-weight: 600; }
.timeliness::before { content: ''; inline-size: 0.5rem; block-size: 0.5rem; border-radius: 50%; background: currentColor; }
.timeliness.level-green { color: var(--color-success-fg); background: var(--color-success-bg); border: 1px solid var(--color-success-border); }
.timeliness.level-yellow { color: var(--color-warning-fg); background: var(--color-warning-bg); border: 1px solid var(--color-warning-border); }
.timeliness.level-red { color: var(--color-danger-fg); background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); }
.timeliness.level-critical { color: var(--color-on-brand); background: var(--color-danger-fg); border: 1px solid var(--color-danger-fg); }

/* -- Routing slip ----------------------------------------------------------- */
.routing-slip {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-4);
  padding: var(--space-3) var(--space-5);
  margin: var(--space-4) 0;
  border-block: 1px solid var(--color-border);
}
.routing-slip > div { display: grid; gap: 0.15rem; }
.routing-slip span { color: var(--color-muted); font-size: var(--text-xs); }
.routing-slip strong { color: var(--color-black-700); font-size: var(--text-lg); }

/* -- Actions ----------------------------------------------------------------- */
.action-panel { margin-bottom: var(--space-4); border-inline-start: 3px solid var(--color-brand); }
.action-panel h3, .description h3, .timeline h3, .attachments h3, .committee-summary h3 { margin: 0 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); }
.committee-summary { margin-bottom: 0; }
.legal-review h3 { margin: 0 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); grid-column: 1 / -1; }
.legal-review .full { grid-column: 1 / -1; }
.legal-review .muted { margin: 0; color: var(--color-muted); font-size: var(--text-sm); grid-column: 1 / -1; }
.legal-review strong.ok { color: var(--color-success-fg); }
.legal-review strong.warn { color: var(--color-warning-fg); }
.legal-review .ghost { margin-inline-start: 0; }
.action-panel > p { margin: 0 0 var(--space-3); color: var(--color-muted); font-size: var(--text-sm); }
.action-panel label, .reason-modal label { display: grid; gap: 0.3rem; max-inline-size: 40rem; font-size: var(--text-sm); }
.action-panel textarea, .reason-modal textarea { padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); resize: vertical; font: inherit; }
.action-buttons, .attachment-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-3); }
.exception-button { padding: 0.5rem 0.9rem; border: 0; border-radius: var(--radius-lg); color: var(--color-on-brand); background: var(--color-brand); cursor: pointer; }
.exception-button:disabled { cursor: not-allowed; opacity: 0.6; }
.exception-actions { padding-top: var(--space-3); margin-top: var(--space-4); border-top: 1px solid var(--color-border); }
.exception-actions summary { cursor: pointer; color: var(--color-muted); font-size: var(--text-sm); }
.exception-actions summary:hover { color: var(--color-black-700); }
.exception-actions .action-buttons { margin-top: var(--space-3); }
.exception-button { color: var(--color-warning-fg); background: var(--color-warning-bg); border: 1px solid var(--color-warning-border); }
.exception-button.destructive { color: var(--color-danger-fg); background: var(--color-danger-bg); border-color: var(--color-danger-border); }
.suggested-badge { display: inline-block; margin-inline-start: 0.4rem; padding: 0.1rem 0.4rem; border-radius: var(--radius-full); color: var(--color-info-fg); background: var(--color-info-bg); border: 1px solid var(--color-info-border); font-size: 0.7rem; font-weight: 600; }
.action-error { color: var(--color-danger-fg); margin: 0.6rem 0 0; font-size: var(--text-sm); }
.financial-impact-toggle { font-weight: normal; font-size: var(--text-xs); }

/* -- Jurisdiction test ------------------------------------------------------- */
.jurisdiction-test { margin-bottom: var(--space-4); }
.jurisdiction-test h3 { margin: 0 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); }
.jurisdiction-test > p { margin: 0 0 var(--space-3); color: var(--color-muted); font-size: var(--text-sm); }
.jurisdiction-test fieldset { padding: 0; margin: 0; border: 0; }
.jurisdiction-test .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.jurisdiction-test label { display: grid; gap: 0.3rem; color: var(--color-black-700); font-size: var(--text-sm); }
.jurisdiction-test select, .jurisdiction-test input { padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); font: inherit; }
.jurisdiction-test button { margin-top: var(--space-3); }
@media (max-width: 640px) { .jurisdiction-test .grid { grid-template-columns: 1fr; } }

/* -- Tab panels ---------------------------------------------------------- */
.tabs { margin-bottom: var(--space-4); }
.stacked { display: grid; gap: var(--space-4); }

/* -- Two-column file tab ---------------------------------------------------- */
.columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(18rem, 0.85fr); gap: var(--space-4); align-items: start; }
.main-column, .side-column { display: grid; gap: var(--space-4); }
.description p { margin: 0; color: var(--color-black-700); line-height: var(--leading-relaxed); white-space: pre-wrap; }
.description .reasons-heading { margin-block-start: var(--space-4); }
.timeline ol { display: grid; gap: 0; padding: 0; margin: var(--space-3) 0 0; list-style: none; }
/* `>` so an entry's nested document list doesn't inherit the rail and its connector line. */
.timeline ol > li { position: relative; display: grid; grid-template-columns: 1.2rem minmax(0, 1fr); gap: 0.6rem; padding-bottom: var(--space-4); }
.timeline ol > li:not(:last-child)::before { content: ''; position: absolute; inset-inline-start: 0.45rem; inset-block-start: 0.85rem; inline-size: 1px; block-size: calc(100% - 0.25rem); background: var(--color-border); }
.entry-documents { display: grid; gap: 0.2rem; padding: 0; margin: 0.3rem 0 0; list-style: none; }
.entry-documents li { display: flex; flex-wrap: wrap; gap: 0.4rem; font-size: var(--text-xs); color: var(--color-muted); }
.entry-documents .doc-label { color: var(--color-black-700); overflow-wrap: anywhere; }
.dot { position: relative; z-index: 1; inline-size: 0.9rem; block-size: 0.9rem; margin-top: 0.15rem; border: 3px solid var(--color-surface); border-radius: 50%; background: var(--color-primary); box-shadow: 0 0 0 1px var(--color-border-hover); }
.timeline p { margin: 0.2rem 0; color: var(--color-black-700); font-size: var(--text-sm); }
.timeline small, .attachments small { color: var(--color-muted); font-size: var(--text-xs); }
.entry-comment { white-space: pre-wrap; }
.attachments ul { display: grid; gap: 0.65rem; padding: 0; margin: var(--space-3) 0; list-style: none; }
.attachments li { display: grid; gap: 0.15rem; padding-bottom: 0.65rem; border-bottom: 1px solid var(--color-border); }
.file-name { overflow-wrap: anywhere; color: var(--color-black-700); font-size: var(--text-sm); }

/* -- Modals ---------------------------------------------------------------- */
.attachment-modal { inline-size: min(64rem, 100%); padding: var(--space-5); border: 1px solid var(--color-border); border-radius: var(--radius-xl); background: var(--color-surface); box-shadow: var(--shadow-2xl); max-block-size: calc(100vh - 2rem); overflow: auto; }
.attachment-image, .attachment-pdf { display: block; inline-size: 100%; max-block-size: 72vh; border: 0; object-fit: contain; }
.attachment-pdf { block-size: 72vh; }
.reason-modal label { max-inline-size: none; }

.checklist h3 { margin: 0 0 0.3rem; color: var(--color-brand-text); font-size: var(--text-lg); }
.checklist ul { display: grid; gap: 0.3rem; padding-inline-start: 1.2rem; margin: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.doc-group { margin-block-start: 0.7rem; }
.doc-group h4 { margin: 0 0 0.3rem; color: var(--color-black-700); font-size: var(--text-xs); font-weight: 600; }
.doc-condition { color: var(--color-black-500); font-size: var(--text-xs); }
.reopen-panel select { padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; }
.reopen-panel fieldset { display: grid; gap: var(--space-3); padding: 0; margin: var(--space-3) 0; border: 0; max-inline-size: 24rem; }
.audit-record { display: grid; gap: 0.3rem; padding: 0; margin: var(--space-2) 0 0; list-style: none; font-size: var(--text-sm); }
.audit-record li { display: flex; justify-content: space-between; gap: var(--space-3); }
.evidence-tag { color: var(--color-success-fg); font-weight: 600; }
.return-list { display: grid; gap: var(--space-4); padding: 0; margin: 0 0 var(--space-4); list-style: none; grid-column: 1 / -1; }
.return-list > li { padding-bottom: var(--space-3); border-bottom: 1px solid var(--color-border); }
.return-list dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: var(--space-3); margin: var(--space-2) 0 0; }
.return-list dl > div { display: grid; gap: 0.2rem; }
.return-list dl span { color: var(--color-muted); font-size: var(--text-xs); }
.return-list dl strong { color: var(--color-black-700); font-size: var(--text-sm); white-space: pre-wrap; }
.return-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-black-700); }
.return-head .muted { color: var(--color-muted); font-size: var(--text-xs); }
.return-kind { padding: 0.1rem 0.45rem; border-radius: var(--radius-full); color: var(--color-warning-fg); background: var(--color-warning-bg); border: 1px solid var(--color-warning-border); font-size: 0.72rem; font-weight: 600; }

.notice-list { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-3); }
.notice-list li { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-3); background: var(--color-surface); }
.notice-list p { margin: 0.35rem 0 0; color: var(--color-black-700); line-height: var(--leading-normal); }
.notice-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-2); }
.notice-moment { font-size: var(--text-xs); padding: 0.1rem 0.45rem; border-radius: var(--radius-full); background: var(--color-info-bg); color: var(--color-info-fg); border: 1px solid var(--color-info-border); }
.notice-date { margin-inline-start: auto; font-size: var(--text-sm); color: var(--color-black-500); }

.time-card .source { margin: -0.4rem 0 var(--space-3); color: var(--color-muted); font-size: var(--text-xs); }
.segments { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.3rem; }
.segments li { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 0.6rem; padding: 0.35rem 0.55rem; border-radius: var(--radius-lg); background: var(--color-surface-hover); font-size: var(--text-sm); }
.segment-code { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--color-muted); }
.segment-label { min-width: 0; }
.segment-days { color: var(--color-brand-text); font-variant-numeric: tabular-nums; white-space: nowrap; }
.segment-days.pending { color: var(--color-muted); }

@media (max-width: 720px) {
  .columns { grid-template-columns: 1fr; }
  .cover { flex-direction: column; }
  .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .routing-slip { grid-template-columns: 1fr; }
}
</style>
