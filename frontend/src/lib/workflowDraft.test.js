import { test } from 'node:test'
import assert from 'node:assert/strict'
import { placeStage, removeRule, removeStage, toPayload, upsertRule } from './workflowDraft.js'

const version = {
  stages: [
    { id: 2, code: 'b', order_no: 2, name_ar: 'ب', name_en: null, responsible_role: null, target_days_min: null, target_days_max: null },
    { id: 1, code: 'a', order_no: 1, name_ar: 'أ', name_en: null, responsible_role: { id: 5 }, target_days_min: 1, target_days_max: 2, checklist: [{ key: 'cq', type: 'attestation', label_ar: 'أقر' }] },
  ],
  transitions: [
    { id: 9, from_stage_id: 1, to_stage_id: 2, action: 'forward', label_ar: null, label_en: null, required_role: null, set_status: { id: 3 }, required_status: null, is_exception: false, requires_comment: false, requires_submitter_manager: true, requires_creator: false, requires_checks: true },
  ],
}

test('toPayload orders stages and names rule ends by code', () => {
  const payload = toPayload(version)
  assert.deepEqual(payload.stages.map((stage) => stage.code), ['a', 'b'])
  assert.equal(payload.stages[0].responsible_role_id, 5)
  assert.deepEqual([payload.transitions[0].from, payload.transitions[0].to, payload.transitions[0].set_status_id], ['a', 'b', 3])
  assert.deepEqual(payload.stages[0].checklist, [{ key: 'cq', type: 'attestation', label_ar: 'أقر' }])
  assert.equal(payload.stages[1].checklist, null)
  assert.equal(payload.transitions[0].requires_checks, true)
})

test('placeStage inserts a new step after the chosen one, and moves an existing one', () => {
  const inserted = placeStage(toPayload(version), null, { name_ar: 'جديد' }, 'a')
  assert.deepEqual(inserted.stages.map((stage) => stage.code), ['a', null, 'b'])
  const moved = placeStage(toPayload(version), 'a', { name_ar: 'أ' }, 'b')
  assert.deepEqual(moved.stages.map((stage) => stage.code), ['b', 'a'])
})

test('removeStage drops its custom rules but keeps a built-in rule leading into it', () => {
  const withCustom = upsertRule(toPayload(version), { id: null, custom: true, from: 'b', to: 'a', label_ar: 'رجوع' })
  const payload = removeStage(withCustom, 'b')
  assert.deepEqual(payload.stages.map((stage) => stage.code), ['a'])
  // The built-in forward a→b stays, so the server can name it: redirect it first.
  assert.deepEqual(payload.transitions.map((rule) => rule.id), [9])
})

test('upsertRule replaces by id and appends a new one; removeRule drops by id', () => {
  const replaced = upsertRule(toPayload(version), { ...toPayload(version).transitions[0], to: 'a' })
  assert.equal(replaced.transitions.length, 1)
  assert.equal(replaced.transitions[0].to, 'a')
  const added = upsertRule(toPayload(version), { id: null, from: 'b', to: 'a', label_ar: 'رجوع' })
  assert.equal(added.transitions.length, 2)
  assert.equal(removeRule(toPayload(version), 9).transitions.length, 0)
})
