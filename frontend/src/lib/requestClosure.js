// Stage 75 — [D] Appendix 47's قائمة التدقيق النهائية قبل الإقفال, mirrored
// once for every screen that renders it (the decisionOutcomes.js precedent).
//
// Twelve checks, verbatim and in the appendix's own order. Nine are answered by
// the closer; `appeal_path_concluded`, `archive_location_set` and — since Stage
// 76 — `execution_document_attached` are derived server-side (from the
// open-appeal hold, the card's own required archive location, and Appendix 70's
// real دليل التنفيذ), so they appear in a recorded audit but never in the form.
export const AUDIT_CHECKS = [
  'final_result_issued',
  'minutes_approved',
  'authority_approval_complete',
  'employee_notified',
  'executed',
  'service_file_updated',
  'electronic_record_updated',
  'decision_copy_attached',
  'execution_document_attached',
  'appeal_path_concluded',
  'no_party_awaiting_action',
  'archive_location_set',
]

const DERIVED_CHECKS = ['appeal_path_concluded', 'archive_location_set', 'execution_document_attached']

export const CLOSER_CHECKS = AUDIT_CHECKS.filter((check) => !DERIVED_CHECKS.includes(check))

// Art. 37's own field list, less تاريخ الإقفال and مسؤول الإقفال, which travel
// as their own columns and are rendered separately.
export const CLOSURE_FIELDS = [
  'final_result_code',
  'final_decision_number',
  'approving_body',
  'execution_date',
  'executing_body',
  'notice_status',
  'file_storage_location',
]
