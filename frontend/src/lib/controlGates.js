// Stage 78 — [D] Art. 105's procedural suspension, mirrored once for every
// screen that renders it (the requestClosure.js / approvalReturn.js
// precedent).
//
// These are the *server's* lists, in the source's own order. Keeping them here
// rather than in each component is what stops a screen from offering an
// answer the endpoint refuses, and what makes a change to either a one-file
// edit.

/** The article's own two grounds, and there are no others in it. */
export const SUSPENSION_GROUNDS = ['incorrect_material_fact', 'document_in_doubt']

/**
 * What the legal review concluded, and therefore where the file goes. There is
 * deliberately no third "cancel it" outcome: ending a matter is a committee
 * decision, not a consequence of a document check.
 */
export const SUSPENSION_RESOLUTIONS = ['fact_confirmed', 'referred_to_committee']
