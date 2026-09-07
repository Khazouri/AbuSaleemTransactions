// Stage 76 — [D] النموذج 17's متابعة التنفيذ and Appendix 70's دليل التنفيذ,
// mirrored once for every screen that renders them (the requestClosure.js
// precedent).
//
// Seven checks, verbatim and in the form's own order. Six are answered by the
// executing officer; `execution_document_attached` is derived server-side from
// the evidence actually attached, because Appendix 70 refuses to let it be a
// claim ("لا يكفي أن تقول الجهة المنفذة: (تم التنفيذ) بل يجب إرفاق دليل
// التنفيذ") — so it appears in a recorded checklist but never in the form.
export const TRACKING_CHECKS = [
  'administrative_decision_issued',
  'employee_file_updated',
  'system_updated',
  'financial_effect_referred',
  'organizational_unit_notified',
  'employee_notified',
  'execution_document_attached',
]

const DERIVED_CHECKS = ['execution_document_attached']

export const EXECUTOR_CHECKS = TRACKING_CHECKS.filter((check) => !DERIVED_CHECKS.includes(check))

// Appendix 70's own list of what دليل التنفيذ may be, plus its closing "أي وثيقة
// تثبت تحقق الأثر المطلوب" as `other` — the appendix offers the seven by example.
export const EVIDENCE_TYPES = [
  'administrative_decision',
  'employee_record_update',
  'transfer_decision',
  'secondment_decision',
  'grade_amendment',
  'financial_statement',
  'commencement_document',
  'other',
]

// النموذج 17's card fields, less الموظف/رقم المعاملة/القرار — all three are
// already known to the system and are shown from the request itself.
export const EXECUTION_FIELDS = [
  'executing_body',
  'action_taken',
  'effective_date',
  'approving_body',
  'approval_number',
  'approval_date',
  'financial_effect_note',
]

// Appendix 52's قواعد تحديث الملف الوظيفي بعد التنفيذ, shown as the prompt for
// what "تم تحديث ملف الموظف" actually means. Guidance only: Track K's scope
// decision (1) puts ملف الخدمة outside this application.
export const SERVICE_FILE_ITEMS = [
  'final_decision',
  'effective_date',
  'decision_effect',
  'grade_change',
  'position_change',
  'body_change',
  'financial_change',
  'seniority_date',
]
