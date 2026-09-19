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

/**
 * Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary, a second and
 * completely independent outcome set from DECISION_OUTCOMES: an `appeal`
 * agenda item is decided with exactly one of these, never one of the seven
 * above. Mirrors DecisionController::APPEAL_OUTCOMES.
 */
export const APPEAL_DECISION_OUTCOMES = [
  'appeal_accept',
  'appeal_partial_accept',
  'appeal_reject',
  'appeal_refer',
  'appeal_redo',
]

/** Stage 63 — the appeal-item equivalent of VOTE_OPTIONS. */
export const APPEAL_VOTE_OPTIONS = [...APPEAL_DECISION_OUTCOMES, 'abstain']
