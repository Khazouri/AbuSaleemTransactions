// 2026-10-26 — one glyph per built-in stage, shared by the request page's stage
// rail and its timeline so a stage looks the same in both. A custom step
// (`custom_…`) has no fixed meaning, so it gets the checklist glyph its own
// step card is about.
const ICONS = {
  receive_from_municipality: 'file-plus',
  direct_manager_review: 'user-check',
  administrative_routing: 'route',
  receive_and_register: 'inbox',
  requirements_check: 'list-checks',
  reviewer_review: 'search',
  observations: 'message',
  forward_to_committee: 'send',
  receive_from_committee: 'users',
  approval_by_authority: 'stamp',
  local_governance_ministry: 'landmark',
  rapporteur_decision_review: 'check-square',
  final_approval_archiving: 'archive',
}

/** @param {string|null|undefined} code */
export function stageIcon(code) {
  return ICONS[code] ?? 'clipboard-list'
}
