/**
 * Every option the decision wizard's Choose step lists, whichever endpoint it
 * posts to. Read by the wizard and by the request page's list beside
 * «اتخاذ القرار», so the two always name the same decisions the same way.
 */
import { REQUEST_ACTS, actLabel } from './requestActs'
import { actionLabel } from './workflowActions'

// Mirrors the page's old destructive tint: these end or refuse the file.
const DESTRUCTIVE = ['cancel', 'reject', 'reject_review', 'reject_formally', 'reject_by_committee', 'declare_no_jurisdiction']
// The legal member's two answers; either returns the file to the pending list.
const RECOMMENDATIONS = ['approve', 'decline']

export const isDestructiveChoice = (item) => (item?.kind === 'transition' && DESTRUCTIVE.includes(item.action))
  || (item?.kind === 'act' && Boolean(REQUEST_ACTS[item.action]?.destructive))

export function decisionChoices(request, { t, te, locale }) {
  const name = (item) => (locale === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar) ?? ''
  const meaning = (action) => (te(`decisionWizard.meaning.${action}`) ? t(`decisionWizard.meaning.${action}`) : '')
  const committeeActions = request.committee_actions ?? []
  const offersLegalOpinion = committeeActions.some((item) => item.action === 'record_legal_review')

  return [
    ...(request.available_transitions ?? []).map((item) => ({
      key: `transition:${item.action}`,
      kind: 'transition',
      action: item.action,
      // Workflow designer — slice 3: a custom outcome is named by its own label.
      label: actionLabel(item, { t, te, locale }),
      meaning: meaning(item.action),
      destination: item.to_stage
        ? t('decisionWizard.choose.destination', { stage: name(item.to_stage), status: name(item.to_status) })
        : '',
      requiresComment: item.requires_comment,
      isException: item.is_exception,
    })),
    ...committeeActions
      .filter((item) => item.action !== 'record_legal_review')
      .map((item) => ({
        key: `committee:${item.action}`,
        kind: 'committee',
        action: item.action,
        label: t(`decisionWizard.actions.${item.action}`),
        meaning: meaning(item.action),
        destination: '',
        requiresComment: item.requires_comment,
        isException: false,
      })),
    ...(offersLegalOpinion
      ? RECOMMENDATIONS.map((code) => ({
        key: `verdict:${code}`,
        kind: 'verdict',
        action: code,
        label: t(`meetingsUnit.legalReview.recommendations.${code}`),
        meaning: t('meetingsUnit.legalReview.recommendationMeaning'),
        destination: '',
        requiresComment: false,
        isException: false,
      }))
      : []),
    ...(request.acts?.available ?? []).map((item) => ({
      key: `act:${item.action}:${item.target?.id ?? ''}`,
      kind: 'act',
      action: item.action,
      act: item,
      family: item.family,
      label: actLabel(t, item),
      meaning: '',
      destination: '',
      requiresComment: false,
      isException: false,
    })),
  ]
}
