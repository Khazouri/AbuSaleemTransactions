/**
 * Stage 82 — [D] Art. 83's agenda ordering and Appendix 24's two priority
 * levels.
 *
 * Mirrors the server's own vocabularies once, so every screen names the same
 * ranks and grounds. The server is the enforcement everywhere — these lists
 * only decide what the screens offer and in what order they render.
 */

/** Art. 83's four ranked categories, plus the tail it does not categorise. */
export const AGENDA_RANKS = {
  1: 'deferred_from_previous',
  2: 'legal_deadline',
  3: 'urgent',
  4: 'ready_by_date',
  5: 'chair_order',
}

/** Appendix 24's أولوية عالية grounds — three derived, one declared. */
export const PRIORITY_GROUNDS = [
  'legal_deadline',
  'returned_from_approving_body',
  'deferred_from_previous',
  'declared',
]

/** Appendix 24's two levels. Stage 31's high/medium/low was this app's own invention. */
export const PRIORITY_LEVELS = ['high', 'normal']

export function agendaRankLabel(t, rank) {
  const code = AGENDA_RANKS[rank]
  return code ? t(`meetings.agenda.rank.${code}`) : ''
}

export function priorityLabel(t, level) {
  return PRIORITY_LEVELS.includes(level) ? t(`meetings.agenda.priority.${level}`) : ''
}

export function priorityGroundLabel(t, ground) {
  return PRIORITY_GROUNDS.includes(ground) ? t(`meetings.agenda.priorityGround.${ground}`) : ground
}
