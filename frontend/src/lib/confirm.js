/**
 * The one "are you sure?" for a high-risk act. A caller awaits
 * `confirmAction({ title, message, reference, confirmLabel, danger })` and
 * gets true only if the person pressed the act's own button; ConfirmHost
 * (mounted once in App.vue) draws the dialog. Replaces window.confirm, whose
 * native box ignores the theme and the page's direction.
 */
import { shallowRef } from 'vue'
import i18n from '../i18n'

export const pendingConfirm = shallowRef(null)

export function confirmAction(options) {
  // A second ask while one is open answers the first "no" rather than losing it.
  pendingConfirm.value?.resolve(false)
  return new Promise((resolve) => {
    pendingConfirm.value = { ...options, resolve }
  })
}

export function answerConfirm(confirmed) {
  const pending = pendingConfirm.value
  pendingConfirm.value = null
  pending?.resolve(confirmed)
}

// Every delete button in the app asks the same question, naming what goes.
export function confirmDelete(name) {
  const { t } = i18n.global
  return confirmAction({
    title: t('confirmAction.deleteTitle'),
    message: t('confirmAction.deleteMessage'),
    reference: name,
    referenceLabel: t('confirmAction.deleting'),
    confirmLabel: t('common.delete'),
    danger: true,
  })
}
