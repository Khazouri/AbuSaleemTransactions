/**
 * Stage 35 — the six outcomes a committee vote/decision can carry.
 *
 * Shared by DecisionsView.vue (register filters, pending-tab vote buttons)
 * and AgendaItemDecisionPanel.vue (the vote/tally/record-decision block) so
 * neither list can drift from DecisionController::ACTIONS on the backend.
 */
export const DECISION_OUTCOMES = [
  'approve',
  'reject',
  'defer',
  'conditional_approval',
  'legal_opinion',
  'refer_other_body',
]

/** Outcomes still counted as an "approval" for signature-pad purposes. */
export const SIGNATURE_OUTCOMES = ['approve']
