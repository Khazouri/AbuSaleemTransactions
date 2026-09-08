/**
 * Stage 80 — [D] Art. 30's referral register.
 *
 * Mirrors `ApprovalReferral::OUTCOMES` once, so the panel's picker and the
 * server's `Rule::in` can never offer different answers. The server is the
 * enforcement; this list only decides what the form shows.
 */
export const REFERRAL_OUTCOMES = ['approved', 'returned']
