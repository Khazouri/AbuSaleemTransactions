import { test } from 'node:test'
import assert from 'node:assert/strict'
import { actionLabel } from './workflowActions.js'

const i18n = (locale, keys = {}) => ({ locale, te: (key) => key in keys, t: (key) => keys[key] })

test('a seeded action reads from the locale files', () => {
  assert.equal(actionLabel({ action: 'forward' }, i18n('en', { 'workflow.actions.forward': 'Approve & forward' })), 'Approve & forward')
})

test("a custom outcome uses its label in the reader's locale", () => {
  assert.equal(actionLabel({ action: 'custom_01j', label_ar: 'اعتماد', label_en: 'Sign off' }, i18n('en')), 'Sign off')
  assert.equal(actionLabel({ action: 'custom_01j', label_ar: 'اعتماد', label_en: 'Sign off' }, i18n('ar')), 'اعتماد')
})

test('actionLabel falls back to Arabic, never the code', () => {
  assert.equal(actionLabel({ action: 'custom_01j', label_ar: 'اعتماد', label_en: null }, i18n('en')), 'اعتماد')
})
