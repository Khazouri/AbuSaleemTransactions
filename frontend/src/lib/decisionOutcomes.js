/**
 * Stage 35 — every outcome a recorded decision's vote tally can carry, for the
 * decisions register's tally chips (DecisionsView.vue).
 *
 * The committee no longer votes in the system (it meets on paper, 2026-10-18),
 * but decisions recorded before then keep their vote counts, one
 * `votes_<outcome>_count` column each — so the register still reads them all.
 * Stage 63's appeal outcomes are a second, independent vocabulary, and
 * `abstain` (Stage 41) was a vote but never an outcome.
 */
export const TALLY_OUTCOMES = [
  'approve',
  'reject',
  'defer',
  'conditional_approval',
  'legal_opinion',
  'refer_other_body',
  'no_jurisdiction',
  'abstain',
  'appeal_accept',
  'appeal_partial_accept',
  'appeal_reject',
  'appeal_refer',
  'appeal_redo',
]
