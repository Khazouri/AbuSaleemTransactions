/**
 * Stage 83 — [D]'s lifecycle edge cases: Appendix 16 (duplicate prevention),
 * Appendices 17/18 (المسؤول الحالي and الإجراء التالي), Appendix 30 (document
 * conflicts), Appendix 31 (document validity), Appendix 33 (urgent requests),
 * Appendix 53 (material-error correction), Appendix 60 (the six special cases)
 * and Appendices 68/69 (withdrawal before and after a decision).
 *
 * Mirrors the server's own vocabularies once, so every screen offers the same
 * codes in the same order. The server is the enforcement everywhere — these
 * lists only decide what a form shows and in what order.
 */

/** Appendix 16's four classifications for a request raised after a closed one. */
export const PRIOR_RELATIONS = ['appeal', 're_presentation', 'new_incident', 'completion_of_previous']

/** Appendix 30's six named differences, plus the appendix's own open "مثل". */
export const CONFLICT_KINDS = [
  'appointment_date',
  'grade',
  'name',
  'qualification',
  'promotion_date',
  'service_period',
  'other',
]

/**
 * Appendix 31's nine checks. `conditional` marks the two the appendix itself
 * qualifies — الختم "عند الحاجة" and مطابقة الصورة للأصل "عند اشتراطها" — which
 * are the only two that may be answered غير منطبق.
 */
export const VALIDITY_CHECKS = [
  { code: 'issuing_body', conditional: false },
  { code: 'document_number', conditional: false },
  { code: 'document_date', conditional: false },
  { code: 'signature', conditional: false },
  { code: 'stamp', conditional: true },
  { code: 'copy_integrity', conditional: false },
  { code: 'copy_matches_original', conditional: true },
  { code: 'no_unapproved_alteration', conditional: false },
  { code: 'linked_to_employee', conditional: false },
]

export const VALIDITY_ANSWERS = ['yes', 'no', 'not_applicable']

/** Appendix 33's five enumerated grounds for عاجل. */
export const URGENCY_REASONS = [
  'legal_period',
  'serious_job_harm',
  'returned_with_deadline',
  'official_directive',
  'statutory_urgency',
]

/** Appendix 53's five material kinds — the only ones a correction memo may fix. */
export const MATERIAL_ERROR_KINDS = ['name', 'number', 'date', 'reference_number', 'typographical']

/**
 * Its six substantive kinds. Offered so the screen can explain why they are
 * not corrections; the server refuses them and names the formal route.
 */
export const SUBSTANTIVE_ERROR_KINDS = [
  'decision_reason',
  'intended_employee',
  'entitlement',
  'grade',
  'result',
  'approving_body',
]

/**
 * Appendix 60's six cases with the determinations each one requires, in the
 * appendix's own order. `options` mirrors the server's fixed answer sets; a
 * field without one is free text.
 */
export const SPECIAL_CASES = [
  {
    code: 'employee_death',
    fields: [
      { code: 'legal_effect' },
      { code: 'procedure_continues', options: ['continues', 'stops'] },
      { code: 'transferable_rights' },
      { code: 'legal_review_reference' },
    ],
  },
  {
    code: 'service_ended',
    fields: [
      { code: 'legal_effect', options: ['moot', 'prior_effect_remains', 'completion_for_prior_period'] },
      { code: 'legal_effect_note' },
    ],
  },
  {
    code: 'transferred_during_study',
    fields: [
      { code: 'transfer_date' },
      { code: 'right_arose_date' },
      { code: 'competent_body_at_right' },
      { code: 'body_completing_procedure' },
    ],
  },
  {
    code: 'legislation_changed',
    fields: [{ code: 'legislation_effective_date' }, { code: 'impact_note' }, { code: 'applicable_law' }],
  },
  {
    code: 'document_lost',
    fields: [{ code: 'document_note' }, { code: 'replacement_attempt' }, { code: 'replacement_source' }],
  },
  {
    code: 'invalid_document_after_decision',
    fields: [{ code: 'material', options: ['yes', 'no'] }, { code: 'referred_to' }],
  },
]

/** The two cases the appendix lets halt something, so the form can say so. */
export const HALTING_CASES = ['legislation_changed']

/** Appendices 68/69's three outcomes. */
export const WITHDRAWAL_OUTCOMES = ['granted', 'refused_administrative_continuation', 'recorded_only']
