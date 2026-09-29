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
 *
 * Decision wizard — sub-project 3: every act and the filing itself are
 * wizards (AppealWizard, AppealFilingWizard); this view is the list.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import api from '../lib/api'
import AppealFilingWizard from '../components/AppealFilingWizard.vue'
import AppealWizard from '../components/AppealWizard.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const STATUS_CODES = [
  'submitted', 'formal_verification', 'file_assembly', 'legal_review',
  'committee_presentation', 'notified_closed', 'rejected', 'outside_jurisdiction',
]

// --- List -------------------------------------------------------------------

const rows = ref([])
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(false)
const loadError = ref(null)
// «المهام المعلقة» links here with ?status= so the appeal's next step is on screen.
const statusFilter = ref(typeof route.query.status === 'string' ? route.query.status : '')

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

function extractErrorMessage(error, fallback) {
  const errors = error?.response?.data?.errors
  if (errors) {
    const first = Object.values(errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return error?.response?.data?.message ?? fallback
}

// --- Stage 60 — formal verification: label only, the act itself is in AppealWizard ---

function deadlineLabel(deadlineMet) {
  if (deadlineMet === null) return t('appeals.verify.deadlineNotConfigured')
  return deadlineMet ? t('appeals.verify.deadlineMet') : t('appeals.verify.deadlineMissed')
}

// --- Stage 62 — jurisdiction test (Art. 77): label only -----------------------------

function competentBodyLabel(code) {
  return code ? t('appeals.jurisdiction.options.' + code) : t('common.none')
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

// --- Stage 65 — notification & closure: label only -----------------------------------
const APPEAL_DECISION_OUTCOME_CODES = ['appeal_accept', 'appeal_partial_accept', 'appeal_reject', 'appeal_refer', 'appeal_redo']

function finalResultLabel(code) {
  if (!code) return t('common.none')
  if (APPEAL_DECISION_OUTCOME_CODES.includes(code)) return t('decisions.outcome.' + code)
  return t('appeals.filters.statuses.' + code)
}

// Decision wizard — sub-project 3. Every act on an appeal is taken in its
// wizard; ?appeal=<id>&decide=<act|1> opens it, which is also how the inbox
// links here.
const wizardAppealId = computed(() => route.query.appeal ?? null)
function openWizard(row) {
  router.replace({ query: { ...route.query, appeal: row.id, decide: 1 } })
}
function closeWizard() {
  const { appeal, decide, ...query } = route.query
  router.replace({ query })
}
const showFiling = ref(false)

onMounted(() => load())
</script>

<template>
  <section class="page appeals">
    <div class="heading">
      <div>
        <h2>{{ t('appeals.title') }}</h2>
        <p class="subtitle">{{ t('appeals.subtitle') }}</p>
      </div>
      <button v-can="'appeals.add'" class="primary" type="button" @click="showFiling = true">
        {{ t('appeals.create.heading') }}
      </button>
    </div>

    <AppealWizard
      v-if="wizardAppealId"
      :key="wizardAppealId"
      :appeal-id="wizardAppealId"
      :initial="route.query.decide ? String(route.query.decide) : null"
      @done="load(page.current_page)"
      @close="closeWizard"
    />
    <AppealFilingWizard v-if="showFiling" @filed="load(1)" @close="showFiling = false" />

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card card-flat card-pad list">
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
        <table class="data-table">
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
              <th>{{ t('appeals.columns.actionFile') }}</th>
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
                <div v-if="row.formal_verification" class="verification-summary">
                  <span class="pill" :class="row.status?.code === 'rejected' ? 'bad' : 'good'">
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
                <div v-if="row.jurisdiction_test" class="verification-summary">
                  <span class="pill" :class="row.jurisdiction_test.competent_body === 'committee' ? 'good' : 'bad'">
                    {{ competentBodyLabel(row.jurisdiction_test.competent_body) }}
                  </span>
                  <small v-if="row.jurisdiction_test.tested_by" class="muted">
                    {{ row.jurisdiction_test.tested_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.jurisdiction.notYet') }}</span>
              </td>
              <td>
                <div v-if="row.legal_review" class="verification-summary">
                  <span class="pill good">{{ t('appeals.legalReview.recorded') }}</span>
                  <small v-if="row.legal_review.reviewed_by" class="muted">
                    {{ row.legal_review.reviewed_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.legalReview.notYet') }}</span>
              </td>
              <td>
                <div v-if="row.outcome_execution" class="verification-summary">
                  <span class="pill good">{{ t('decisions.outcome.' + row.committee_decision?.outcome) }}</span>
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
                <div v-if="row.closure" class="verification-summary">
                  <span class="pill good">{{ finalResultLabel(row.closure.final_result_code) }}</span>
                  <small class="muted">{{ t('appeals.closure.noticeStatus.' + row.closure.notice_status) }}</small>
                  <small v-if="row.closure.closed_by" class="muted">
                    {{ t('appeals.closure.closedBy') }}: {{ row.closure.closed_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.closure.notConcludedYet') }}</span>
              </td>
              <td>
                <div v-if="row.reopen" class="verification-summary">
                  <span class="pill good">{{ t(`reopenReasons.${row.reopen.reason_code}`) }}</span>
                  <small v-if="row.reopen.reopened_by" class="muted">
                    {{ t('appeals.reopen.reopenedBy') }}: {{ row.reopen.reopened_by.name }}
                  </small>
                </div>
                <span v-else class="muted">{{ t('appeals.reopen.notClosedYet') }}</span>
              </td>
              <td>
                <!-- Layout fix round 1: sharing this cell with the file
                     toggle (instead of a twelfth column) keeps the table
                     within the desktop no-sideways-scroll rule. -->
                <div class="verification-summary">
                  <button v-if="row.has_acts" class="ghost" type="button" @click="openWizard(row)">
                    {{ t('decisionWizard.open') }}
                  </button>
                  <span v-else class="muted">{{ t('common.none') }}</span>
                  <button class="ghost" type="button" @click="toggleFile(row)">
                    {{ fileTargetId === row.id ? t('appeals.file.hide') : t('appeals.file.view') }}
                  </button>
                </div>
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
.list { margin-bottom: var(--space-4); }
.filters { display: flex; align-items: end; gap: var(--space-3); margin-bottom: var(--space-4); }
.filters label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-sm); color: var(--color-black-700); }
.filters select { padding: .4rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font-size: var(--text-base); }

.verification-summary { display: flex; flex-direction: column; gap: .2rem; align-items: start; }

.file-panel { background: var(--color-surface-hover); }
.dossier { display: flex; flex-direction: column; gap: var(--space-4); padding: var(--space-2) 0; }
.dossier-section { border-inline-start: 3px solid var(--color-border-hover); padding-inline-start: var(--space-3); }
.dossier-section h4 { margin: 0 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); }
.dossier-section h5 { margin: .6rem 0 .3rem; color: var(--color-black-700); font-size: var(--text-sm); }
.dossier-section p { margin: .25rem 0; font-size: var(--text-sm); color: var(--color-foreground); }
.info-grid { display: grid; grid-template-columns: max-content 1fr; gap: .3rem .75rem; margin: 0; font-size: var(--text-sm); }
.info-grid dt { color: var(--color-muted); }
.info-grid dd { margin: 0; color: var(--color-foreground); }
.text-block { white-space: pre-line; }
.doc-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-sm); }
.doc-list li { display: flex; align-items: center; gap: .4rem; }
.link { padding: 0; border: 0; background: none; color: var(--color-brand-text); text-decoration: underline; font-size: inherit; cursor: pointer; }

/* Below desktop a wide register scrolls in its own card; at desktop width it must fit. */
@media (min-width: 641px) and (max-width: 1023px) { .data-table { min-width: 900px; } }
/* Layout fix round 2 — a populated row (result pill + "by <name>" lines in
   the verification/jurisdiction/legal-review/status cells) still pushed this
   table past its card at ≥1024px: style.css's ".pill { white-space: nowrap }"
   is unconditional, so those cells refused to shrink no matter how tightly
   style.css's own `overflow-wrap: anywhere` squeezed the plain-text columns
   (request, decision reference, execution, closure, reopen) around them.
   Scoped to this table rather than editing .pill globally — the DecisionsView
   vote tally (AGENT_NOTES 2026-09-22) is the same fix, applied locally. */
@media (min-width: 1024px) {
  .data-table .pill { white-space: normal; }
}
.nowrap { white-space: nowrap; }
.ref { font-family: var(--font-mono); font-size: var(--text-sm); }
.muted { display: block; color: var(--color-muted); font-size: var(--text-xs); }
</style>
