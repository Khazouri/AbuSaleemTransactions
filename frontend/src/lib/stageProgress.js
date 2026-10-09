/**
 * Stage 93 — one stage count, everywhere an employee can see one.
 *
 * `stage_progress` is computed server-side (see RequestResource::toArray())
 * because that is the one place carrying both `current_stage.order_no` and
 * the reconciliation decision behind the denominator: [F]'s ten steps and
 * [G]'s own «1 من 11» disagree with each other, and neither poster is
 * committed to this repo to check against. Every screen that names a
 * request's current stage reads this one formatter, so the figure cannot
 * phrase itself two ways between screens even if the underlying count ever
 * changes.
 *
 * @param {(key: string, params?: object) => string} t
 * @param {{current: number, total: number}|null} stageProgress
 */
export function stageProgressLabel(t, stageProgress) {
  return stageProgress
    ? t('requests.stageProgress', { current: stageProgress.current, total: stageProgress.total })
    : null
}
