/**
 * Stage 35 — the outcomes a committee vote/decision can carry (Stage 49 adds
 * a seventh, `no_jurisdiction`).
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
  'no_jurisdiction',
]

/**
 * Stage 41 — a committee member who attended but doesn't vote either way.
 * Tallied like any other vote, but never a plurality leader: it has no
 * matching DecisionController::ACTIONS entry, so it's deliberately kept out
 * of DECISION_OUTCOMES and only added here, for casting a vote and reading
 * a tally — never for choosing/labelling a recorded decision's outcome.
 */
export const VOTE_OPTIONS = [...DECISION_OUTCOMES, 'abstain']

/** Outcomes still counted as an "approval" for signature-pad purposes. */
export const SIGNATURE_OUTCOMES = ['approve']
