/**
 * Stage 72 — shared reading of a request type's `required_documents`, [D]
 * Appendix 57's مصفوفة المستندات الإلزامية.
 *
 * Two screens render the same matrix — the intake form (what to bring) and the
 * request workspace (what the officer running Art. 18's فحص اكتمال الملف has
 * to judge the file against) — so the grouping and label rules live here once
 * rather than in both, the same way lib/decisionOutcomes.js owns the outcome
 * vocabulary.
 *
 * GROUP_ORDER is Appendix 57's own order (أساسية مشتركة before الخاصة بالنوع).
 * The appendix's fourth group, الناتجة عن دورة اللجنة, is never seeded — those
 * documents only exist after the file reaches the committee — so it has no
 * entry here either.
 */
export const DOCUMENT_GROUPS = ['basic', 'specific']

/** Picks the caller's locale, falling back to the other side rather than blank. */
export function documentLabel(doc, locale) {
  return locale === 'ar' ? doc.ar || doc.en : doc.en || doc.ar
}

/**
 * Appendix 57's own inline qualifier for this row ("بحسب الموضوع", "عند
 * الحاجة", ...), or '' for a row the source states unconditionally.
 */
export function documentCondition(doc, locale) {
  return doc.condition ? documentLabel(doc.condition, locale) : ''
}

/**
 * Splits a type's list into Appendix 57's groups, dropping any group the type
 * has no entries for — the four types [D] names no per-type file for carry the
 * shared basics only, and an empty "خاصة بهذا النوع" heading would read as a
 * missing list rather than as an absent one.
 */
export function groupDocuments(documents) {
  const list = Array.isArray(documents) ? documents : []

  return DOCUMENT_GROUPS
    // An entry with no `group` predates Stage 72's shape; treat it as basic
    // rather than dropping it silently.
    .map((group) => ({
      group,
      items: list.filter((doc) => (doc.group || 'basic') === group),
    }))
    .filter((section) => section.items.length > 0)
}
