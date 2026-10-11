/**
 * Workflow designer — slice 3. The PUT body for a draft and the edits made to
 * it. Each designer edit is one modal whose submit sends the whole graph, so
 * these turn the version payload into that body and apply one change; the
 * server's answer then replaces the version. Pure, so node --test checks it.
 */
const idOf = (item) => item?.id ?? null

export function toPayload(version) {
  const codeOf = Object.fromEntries(version.stages.map((stage) => [stage.id, stage.code]))
  return {
    stages: [...version.stages].sort((a, b) => a.order_no - b.order_no).map((stage) => ({
      code: stage.code,
      name_ar: stage.name_ar,
      name_en: stage.name_en,
      responsible_role_id: idOf(stage.responsible_role),
      target_days_min: stage.target_days_min,
      target_days_max: stage.target_days_max,
      checklist: stage.checklist ?? null,
    })),
    transitions: version.transitions.map((rule) => ({
      id: rule.id,
      // Read by removeStage only; the server takes custom-ness from the action, never from this.
      custom: Boolean(rule.custom),
      from: codeOf[rule.from_stage_id],
      to: codeOf[rule.to_stage_id],
      label_ar: rule.label_ar,
      label_en: rule.label_en,
      required_role_id: idOf(rule.required_role),
      set_status_id: idOf(rule.set_status),
      required_status_id: idOf(rule.required_status),
      is_exception: rule.is_exception,
      requires_comment: rule.requires_comment,
      requires_submitter_manager: rule.requires_submitter_manager,
      requires_creator: rule.requires_creator,
      requires_checks: Boolean(rule.requires_checks),
    })),
  }
}

/** A new step (code null; the server mints it) or a moved one, placed after afterCode (first when null). */
export function placeStage(payload, code, fields, afterCode) {
  const stages = payload.stages.filter((stage) => code === null || stage.code !== code)
  const at = afterCode === null ? 0 : stages.findIndex((stage) => stage.code === afterCode) + 1
  stages.splice(at, 0, { ...fields, code })
  return { ...payload, stages }
}

/**
 * The step and every custom outcome touching it go. A built-in rule leading
 * into it stays, still pointing at it, so the server refuses by naming that
 * rule's stage. A deleted built-in rule can't be re-made in a draft, so it is
 * redirected, never dropped silently.
 */
export function removeStage(payload, code) {
  return {
    stages: payload.stages.filter((stage) => stage.code !== code),
    transitions: payload.transitions.filter((rule) => rule.from !== code && (rule.to !== code || !rule.custom)),
  }
}

export function upsertRule(payload, rule) {
  const transitions = rule.id === null
    ? [...payload.transitions, rule]
    : payload.transitions.map((item) => (item.id === rule.id ? rule : item))
  return { ...payload, transitions }
}

export function removeRule(payload, id) {
  return { ...payload, transitions: payload.transitions.filter((rule) => rule.id !== id) }
}
