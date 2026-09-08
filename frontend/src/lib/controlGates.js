// Stage 78 — [D] Appendix 63's four control gates, plus Arts. 103 and 105,
// mirrored once for every screen that renders them (the requestClosure.js /
// requestExecution.js / approvalReturn.js precedent).
//
// The three lists below are the *server's* lists, in the sources' own order.
// Keeping them here rather than in each component is what stops a screen from
// offering an answer the endpoint refuses, and what makes a change to any of
// them a one-file edit.

/** Appendix 63's own four points, in its own order. */
export const GATES = ['intake', 'agenda', 'minutes', 'closure']

// --- بوابة 1 — قبل القيد -----------------------------------------------

/**
 * The per-document answers. `missing` is the one that refuses; `not_applicable`
 * is honest only for a document Appendix 57 itself qualifies with a condition,
 * which the server enforces and this picker mirrors (see `answersFor`).
 */
export const INTAKE_ANSWERS = ['present', 'not_applicable', 'missing']

/**
 * An unconditional Appendix 57 entry cannot be waived, so "لا ينطبق" is not
 * offered for one — the server refuses it, and offering a choice that always
 * 422s is worse than not offering it.
 */
export function intakeAnswersFor(document) {
  return document?.conditional ? INTAKE_ANSWERS : ['present', 'missing']
}

// --- Art. 103 — قائمة فحص سلامة القرار ---------------------------------

/** All twelve, in the article's own order, for rendering the recorded card. */
export const SOUNDNESS_CHECKS = [
  'employee_name_correct',
  'employee_number_correct',
  'decision_subject_present',
  'committee_jurisdiction',
  'convening_valid',
  'voting_complete',
  'documents_present',
  'legal_basis_sound',
  'minutes_signed',
  'competent_authority_approved',
  'effective_date_correct',
  'no_conflict_with_attachments',
]

/**
 * The four a human answers. The other eight are read from real state by the
 * server and are not accepted from the client at all — Art. 104's "صحة
 * المستند والاختصاص ليستا إجراءات شكلية" is the reason, so the form shows
 * them read-only rather than as inputs.
 */
export const SOUNDNESS_CERTIFIER_CHECKS = [
  'employee_name_correct',
  'employee_number_correct',
  'effective_date_correct',
  'no_conflict_with_attachments',
]

export const SOUNDNESS_ANSWERS = ['yes', 'no', 'not_applicable']

export function isCertifierCheck(key) {
  return SOUNDNESS_CERTIFIER_CHECKS.includes(key)
}

// --- Art. 105 — الإيقاف الإجرائي ----------------------------------------

/** The article's own two grounds, and there are no others in it. */
export const SUSPENSION_GROUNDS = ['incorrect_material_fact', 'document_in_doubt']

/**
 * What the legal review concluded, and therefore where the file goes. There is
 * deliberately no third "cancel it" outcome: ending a matter is a committee
 * decision, not a consequence of a document check.
 */
export const SUSPENSION_RESOLUTIONS = ['fact_confirmed', 'referred_to_committee']

// --- Appendix 8 — ضوابط جودة المحضر -------------------------------------

/** All sixteen, in the appendix's own order. */
export const MINUTES_QUALITY_CHECKS = [
  'meeting_number_matches',
  'date_correct',
  'member_names_match',
  'attendance_recorded',
  'convening_validity_recorded',
  'items_match_agenda',
  'every_item_has_a_result',
  'decisions_separated_from_recommendations',
  'deferrals_clear',
  'required_completions_named',
  'refusals_clear',
  'sensitive_results_reasoned',
  'next_approving_body_named',
  'request_numbers_match',
  'no_internal_contradictions',
  'signatures_complete',
]

/** The one the reviewer answers; the rest are derived or enforced elsewhere. */
export const MINUTES_REVIEWER_CHECK = 'no_internal_contradictions'
