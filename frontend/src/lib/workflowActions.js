/**
 * Workflow designer — slice 3. A rule's name. A seeded action is named by the
 * locale files; an outcome an admin added (`custom_…`) carries its own label,
 * Arabic always and English optionally — so English falls back to Arabic, and
 * the raw code is shown only if both are somehow missing.
 */
export function actionLabel(item, { t, te, locale }) {
  const key = `workflow.actions.${item.action}`
  if (te(key)) return t(key)
  return (locale === 'en' && item.label_en) || item.label_ar || item.action
}
