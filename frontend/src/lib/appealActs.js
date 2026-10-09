/**
 * Decision wizard — sub-project 3. One entry per appeal act the server may
 * list: its form (moved out of AppealsView's modals), endpoint, empty payload
 * and readiness. The jurisdiction test has no form — its five answers are the
 * wizard's options.
 */
import AppealCloseForm from '../components/acts/AppealCloseForm.vue'
import AppealExecuteForm from '../components/acts/AppealExecuteForm.vue'
import AppealLegalReviewForm from '../components/acts/AppealLegalReviewForm.vue'
import AppealNominateForm from '../components/acts/AppealNominateForm.vue'
import AppealReopenForm from '../components/acts/AppealReopenForm.vue'
import AppealVerifyForm from '../components/acts/AppealVerifyForm.vue'

export const COMPETENT_BODIES = ['committee', 'mayor', 'ministry', 'other_body', 'disciplinary_or_court']
export const VERIFY_CHECKS = ['appellant_standing', 'valid_target_decision', 'non_duplication']
export const LEGAL_REVIEW_CHECKS = ['factual_error', 'legal_text_violation', 'new_documents', 'formation_or_reasoning_defect', 'issued_by_competent_body']

const filled = (value) => String(value ?? '').trim() !== ''
const yes = (value) => value === 'yes'
const answers = (keys) => () => Object.fromEntries(keys.map((key) => [key, '']))

export const APPEAL_ACTS = {
  verify: {
    labelKey: 'appeals.verify.action',
    form: AppealVerifyForm,
    method: 'post',
    url: (appeal) => `/appeals/${appeal.id}/verify`,
    blank: () => ({ ...answers(VERIFY_CHECKS)(), reason: '' }),
    // A failed check needs its reason; the deadline is computed server-side,
    // and a 422 on Confirm covers the case where it alone fails.
    ready: (form) => VERIFY_CHECKS.every((key) => form[key] !== '')
      && (VERIFY_CHECKS.every((key) => yes(form[key])) || filled(form.reason)),
    body: (form) => ({ ...Object.fromEntries(VERIFY_CHECKS.map((key) => [key, yes(form[key])])), reason: form.reason || null }),
  },
  jurisdiction_test: {
    labelKey: 'appeals.jurisdiction.action',
    form: null,
    method: 'patch',
    url: (appeal) => `/appeals/${appeal.id}/jurisdiction-test`,
    blank: () => ({}),
    ready: () => true,
    body: (form, choice) => ({ competent_body: choice.body }),
    // Any body but the committee ends the appeal.
    destructive: (choice) => choice.body !== 'committee',
  },
  legal_review: {
    labelKey: 'appeals.legalReview.action',
    form: AppealLegalReviewForm,
    method: 'patch',
    url: (appeal) => `/appeals/${appeal.id}/legal-review`,
    blank: answers(LEGAL_REVIEW_CHECKS),
    ready: (form) => LEGAL_REVIEW_CHECKS.every((key) => form[key] !== ''),
    body: (form) => Object.fromEntries(LEGAL_REVIEW_CHECKS.map((key) => [key, yes(form[key])])),
  },
  nominate: {
    labelKey: 'decisionWizard.appeal.nominate',
    form: AppealNominateForm,
    method: 'post',
    url: (appeal, form) => `/meetings/${form.meeting_id}/agenda`,
    blank: () => ({ meeting_id: '' }),
    ready: (form) => filled(form.meeting_id),
    body: (form, choice, appeal) => ({ item_type: 'appeal', appeal_id: appeal.id }),
  },
  execute_outcome: {
    labelKey: 'appeals.execution.action',
    form: AppealExecuteForm,
    method: 'patch',
    url: (appeal) => `/appeals/${appeal.id}/execute-outcome`,
    blank: () => ({ redo_stage_id: '' }),
    ready: (form, appeal) => appeal.committee_decision?.outcome !== 'appeal_redo' || filled(form.redo_stage_id),
    body: (form) => (form.redo_stage_id ? { redo_stage_id: form.redo_stage_id } : {}),
  },
  close: {
    labelKey: 'appeals.closure.action',
    form: AppealCloseForm,
    method: 'patch',
    url: (appeal) => `/appeals/${appeal.id}/close`,
    blank: () => ({ final_decision_number: '', approving_body: '', execution_date: '', executing_body: '', file_storage_location: '' }),
    ready: (form) => filled(form.approving_body) && filled(form.file_storage_location),
    body: (form) => ({
      final_decision_number: form.final_decision_number || null,
      approving_body: form.approving_body,
      execution_date: form.execution_date || null,
      executing_body: form.executing_body || null,
      file_storage_location: form.file_storage_location,
    }),
  },
  reopen: {
    labelKey: 'appeals.reopen.action',
    form: AppealReopenForm,
    method: 'patch',
    url: (appeal) => `/appeals/${appeal.id}/reopen`,
    blank: () => ({ reason_code: '', note: '' }),
    ready: (form) => filled(form.reason_code),
    body: (form) => ({ reason_code: form.reason_code, note: form.note || null }),
  },
}
