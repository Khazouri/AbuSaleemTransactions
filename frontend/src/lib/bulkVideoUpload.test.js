// Run with: node --test src/lib/bulkVideoUpload.test.js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { matchVideoFiles } from './bulkVideoUpload.js'

const catalogue = [{ code: 'screen.login' }, { code: 'task.manager_review' }]
const file = (name) => ({ name })

test('a file named after a code lands on that row', () => {
  const { matched, skipped } = matchVideoFiles([file('screen.login.mp4')], catalogue)
  assert.equal(matched.length, 1)
  assert.equal(matched[0].video.code, 'screen.login')
  assert.deepEqual(skipped, [])
})

test('the name and extension match whatever their case', () => {
  const { matched } = matchVideoFiles([file('Screen.Login.MP4')], catalogue)
  assert.equal(matched[0]?.video.code, 'screen.login')
})

test('an unknown name or another format is skipped, never sent', () => {
  const { matched, skipped } = matchVideoFiles([file('hello.mp4'), file('screen.login.mov')], catalogue)
  assert.deepEqual(matched, [])
  assert.deepEqual(skipped.map((f) => f.name), ['hello.mp4', 'screen.login.mov'])
})

test('a second file for the same code is skipped and the first kept', () => {
  const first = file('screen.login.mp4')
  const { matched, skipped } = matchVideoFiles([first, file('SCREEN.LOGIN.mp4')], catalogue)
  assert.equal(matched.length, 1)
  assert.equal(matched[0].file, first)
  assert.deepEqual(skipped.map((f) => f.name), ['SCREEN.LOGIN.mp4'])
})
