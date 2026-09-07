// Stage 77 — [D] Art. 94's return reasons merged with Appendix 34's own
// شكلية/موضوعية examples, mirrored once for every screen that renders them
// (the requestClosure.js / requestExecution.js precedent).
//
// The kind attached to each code is the source's own classification: Appendix
// 34 puts توقيع ناقص · خطأ رقمي · نقص مرفق under شكلية and طلب إعادة دراسة ·
// ملاحظة قانونية · تعارض في الاختصاص · اعتراض على نتيجة under موضوعية, while
// Art. 94 adds خطأ في الصياغة and نقص في البيانات. The server refuses a code
// whose stated kind disagrees with this table, so the picker below filters to
// the chosen kind rather than offering a combination that would 422.
//
// `other` carries no kind because both appendix lists are introduced with
// "مثل" — an exhaustive enum would close a list the source leaves open.
export const RETURN_KINDS = ['formal', 'substantive']

export const RETURN_REASONS = {
  missing_signature: 'formal',
  numeric_error: 'formal',
  missing_document: 'formal',
  drafting_error: 'formal',
  incomplete_data: 'formal',
  restudy_requested: 'substantive',
  legal_observation: 'substantive',
  jurisdiction_conflict: 'substantive',
  result_objection: 'substantive',
  other: null,
}

/** The codes offerable for a chosen kind: that kind's own, plus the open one. */
export function reasonsForKind(kind) {
  return Object.keys(RETURN_REASONS).filter(
    (code) => RETURN_REASONS[code] === kind || RETURN_REASONS[code] === null,
  )
}
