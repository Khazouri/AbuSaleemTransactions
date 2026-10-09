/**
 * Stage 33 — one readiness exception as a sentence, shared by the readiness
 * screen and the meeting wizard's Checks step (decision wizard, sub-project 2).
 * Stage 85 — `missing_files` counts items with no documents at all,
 * `incomplete_required_documents` items missing their Appendix 57 ones.
 */
export function readinessExceptionMessage(t, exception) {
  const key = `meetingsUnit.readiness.exceptions.${exception.code}`
  if (exception.code === 'quorum_not_met') {
    return t(key, { confirmed: exception.confirmed, required: exception.required })
  }
  if (exception.code === 'missing_files' || exception.code === 'incomplete_required_documents') {
    return t(key, { count: exception.item_ids?.length ?? 0 })
  }
  if (exception.code === 'unconfirmed_members') {
    return t(key, { count: exception.user_ids?.length ?? 0 })
  }
  return t(key)
}
