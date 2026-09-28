/**
 * Decision wizard — sub-project 3. One entry per act the server may list in a
 * request's `acts`: the form that asks for its input (moved out of the panel
 * that used to own it), the endpoint it posts to, an empty payload, and when
 * that payload is complete enough to submit. The wizard knows nothing act by
 * act beyond this table; the server decides which acts are offered.
 */
import ArchiveForm from '../components/acts/ArchiveForm.vue'
import ClosureForm from '../components/acts/ClosureForm.vue'
import ExecutionForm from '../components/acts/ExecutionForm.vue'
import LiftForm from '../components/acts/LiftForm.vue'
import ReferralForm from '../components/acts/ReferralForm.vue'
import ReferralResultForm from '../components/acts/ReferralResultForm.vue'
import ReturnForm from '../components/acts/ReturnForm.vue'
import ReturnResolveForm from '../components/acts/ReturnResolveForm.vue'
import SoundnessForm from '../components/acts/SoundnessForm.vue'
import SuspendForm from '../components/acts/SuspendForm.vue'
import { SOUNDNESS_CERTIFIER_CHECKS, SUSPENSION_GROUNDS, SUSPENSION_RESOLUTIONS } from './controlGates'
import { CLOSER_CHECKS } from './requestClosure'
import { EXECUTOR_CHECKS } from './requestExecution'

export const ACT_FAMILIES = ['after_decision', 'records', 'file']

const filled = (value) => String(value ?? '').trim() !== ''
const base = (request) => `/requests/${request.id}`

/** Appendix 70 — attachment id → evidence kind, as the endpoint wants the list. */
export function evidenceEntries(form) {
  return Object.entries(form.evidence)
    .filter(([, type]) => type)
    .map(([id, type]) => ({ attachment_id: Number(id), evidence_type: type }))
}

export const REQUEST_ACTS = {
  execution_soundness: {
    labelKey: 'controlGates.soundness.action',
    form: SoundnessForm,
    method: 'patch',
    url: (request) => `${base(request)}/execution-soundness`,
    // A revision starts from the answers already on record.
    blank: (request) => ({
      checks: Object.fromEntries(SOUNDNESS_CERTIFIER_CHECKS.map((key) => [
        key, request.control_gates?.execution_soundness?.record?.[key] ?? '',
      ])),
    }),
    ready: (form) => SOUNDNESS_CERTIFIER_CHECKS.every((key) => form.checks[key] !== ''),
  },
  execute: {
    labelKey: 'requestExecution.action',
    form: ExecutionForm,
    method: 'post',
    url: (request, act) => `/meetings/${act.target.meeting_id}/outputs/${act.target.id}/execute`,
    blank: () => ({
      executing_body: '',
      action_taken: '',
      effective_date: '',
      approving_body: '',
      approval_number: '',
      approval_date: '',
      financial_effect_note: '',
      checklist: Object.fromEntries(EXECUTOR_CHECKS.map((key) => [key, 'yes'])),
      evidence: {},
    }),
    ready: (form) => filled(form.executing_body) && filled(form.action_taken) && filled(form.effective_date)
      && filled(form.approving_body) && evidenceEntries(form).length > 0,
    body: (form) => ({ ...form, evidence: evidenceEntries(form) }),
  },
  archive_committee_file: {
    labelKey: 'decisionWizard.acts.archive_committee_file',
    form: ArchiveForm,
    method: 'patch',
    url: (request) => `${base(request)}/archive/committee-file`,
    blank: () => ({ location: '' }),
    ready: (form) => filled(form.location),
  },
  archive_service_file: {
    labelKey: 'decisionWizard.acts.archive_service_file',
    form: ArchiveForm,
    method: 'patch',
    url: (request) => `${base(request)}/archive/service-file`,
    blank: () => ({ location: '' }),
    ready: (form) => filled(form.location),
  },
  close: {
    labelKey: 'requestClosure.action',
    form: ClosureForm,
    method: 'patch',
    url: (request) => `${base(request)}/close`,
    blank: () => ({
      final_decision_number: '',
      approving_body: '',
      execution_date: '',
      executing_body: '',
      audit: Object.fromEntries(CLOSER_CHECKS.map((key) => [key, 'yes'])),
    }),
    ready: (form) => filled(form.approving_body),
  },
  record_approval_return: {
    labelKey: 'approvalReturn.action',
    form: ReturnForm,
    method: 'patch',
    url: (request) => `${base(request)}/approval-return`,
    blank: () => ({ return_kind: 'formal', return_reason_code: 'missing_signature', return_note: '', letter_number: '', received_at: '' }),
    ready: (form) => filled(form.return_note) && filled(form.received_at),
  },
  resolve_approval_return: {
    labelKey: 'approvalReturn.resolveAction',
    form: ReturnResolveForm,
    method: 'patch',
    url: (request) => `${base(request)}/approval-return/resolve`,
    blank: () => ({ resolution_action: '' }),
    ready: (form) => filled(form.resolution_action),
  },
  record_approval_referral: {
    labelKey: 'approvalReferral.action',
    form: ReferralForm,
    method: 'post',
    url: (request) => `${base(request)}/approval-referrals`,
    blank: () => ({ referred_at: '', letter_number: '', referred_to_body: '' }),
    ready: (form) => filled(form.referred_at) && filled(form.letter_number) && filled(form.referred_to_body),
  },
  record_referral_result: {
    labelKey: 'approvalReferral.resultAction',
    form: ReferralResultForm,
    method: 'patch',
    url: (request, act) => `${base(request)}/approval-referrals/${act.target.id}/result`,
    blank: () => ({ result_outcome: 'approved', result_received_at: '', approval_decision_number: '', result_note: '' }),
    // رقم قرار الاعتماد binds only on an approval.
    ready: (form) => filled(form.result_received_at)
      && (form.result_outcome !== 'approved' || filled(form.approval_decision_number)),
  },
  suspend: {
    labelKey: 'controlGates.suspension.action',
    form: SuspendForm,
    method: 'patch',
    destructive: true,
    url: (request) => `${base(request)}/suspend`,
    blank: () => ({ ground: SUSPENSION_GROUNDS[0], detail: '' }),
    ready: (form) => filled(form.detail),
  },
  lift_suspension: {
    labelKey: 'controlGates.suspension.liftAction',
    form: LiftForm,
    method: 'patch',
    url: (request) => `${base(request)}/suspend/lift`,
    blank: () => ({ resolution_action: SUSPENSION_RESOLUTIONS[0], resolution_note: '' }),
    ready: () => true,
  },
}

/** The option's text: the act, then — for an act on one row — which row. */
export function actLabel(t, act) {
  const spec = REQUEST_ACTS[act.action]
  const detail = [spec?.kindLabel?.(t, act.target?.kind), act.target?.label].filter(Boolean).join(': ')
  const name = t(spec?.labelKey ?? `decisionWizard.acts.${act.action}`)
  return detail ? `${name} — ${detail}` : name
}
