/**
 * Stage 74 — the structure [D] requires of a recorded decision, mirrored from
 * the backend so the recording form asks for exactly what
 * DecisionStructureRules will insist on.
 *
 * Kept beside decisionOutcomes.js, and for the same reason: these lists are
 * read by AgendaItemDecisionPanel.vue (the recording form and the recorded
 * display) and DecisionsView.vue (the register), so neither can drift from
 * the other or from the server. The server is the enforcement either way —
 * this only stops the form offering a shape the endpoint would refuse.
 */

/** Art. 90's قرار / توصية / رأي — which the article forbids interchanging. */
export const DECISION_INSTRUMENTS = ['decision', 'recommendation', 'opinion']

/**
 * Appendix 28's seven professional refusal reasons, plus `other` — the
 * appendix introduces its list with "مثل", so it is illustrative rather than
 * closed. See DecisionReasoningRules::REFUSAL_REASON_CODES.
 */
export const REFUSAL_REASON_CODES = [
  'period_condition_unmet',
  'position_unavailable',
  'legal_text_inapplicable',
  'essential_condition_missing',
  'outside_jurisdiction',
  'document_invalid',
  'legal_impediment',
  'other',
]

/**
 * Outcomes that dispose of the matter, and therefore need Appendix 27's
 * الوقائع and السند on top of the موضوع and المنطوق every decision needs.
 * Mirrors DecisionStructureRules::SUBSTANTIVE_OUTCOMES.
 */
export const SUBSTANTIVE_OUTCOMES = [
  'approve',
  'conditional_approval',
  'reject',
  'no_jurisdiction',
  'appeal_accept',
  'appeal_partial_accept',
  'appeal_reject',
]

/**
 * Art. 91's reasoned cases that exist in this system's outcome vocabulary:
 * عدم الموافقة, عدم الاختصاص and رفض تظلم. Mirrors
 * DecisionStructureRules::REASONED_OUTCOMES.
 */
export const REASONED_OUTCOMES = ['reject', 'no_jurisdiction', 'appeal_reject']

/**
 * Outcomes that hand the matter to somebody else and must therefore say who —
 * [D] Art. 26 (د) and [E] 13D. Mirrors
 * DecisionStructureRules::REFERRING_OUTCOMES.
 *
 * `legal_opinion` is deliberately absent: it goes to the committee's own legal
 * member, not out to a body that needs naming.
 */
export const REFERRING_OUTCOMES = ['no_jurisdiction', 'refer_other_body', 'appeal_refer']

/** Art. 34's five deferral fields — the first four mandatory, the fifth "إن وجدت". */
export const DEFERRAL_FIELDS = [
  'deferral_reason',
  'deferral_required_completion',
  'deferral_responsible_body',
  'deferral_required_document',
  'deferral_legal_period',
]

export function isSubstantiveOutcome(outcome) {
  return SUBSTANTIVE_OUTCOMES.includes(outcome)
}

export function needsRefusalReason(outcome) {
  return REASONED_OUTCOMES.includes(outcome)
}

export function needsReferralAuthority(outcome) {
  return REFERRING_OUTCOMES.includes(outcome)
}
