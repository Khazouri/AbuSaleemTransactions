// Run with TZ=Africa/Tripoli: node --test src/lib/dateInput.test.js
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { toApiDateTime, toLocalInput } from './dateInput.js'

test('a Tripoli wall time goes to the API as its UTC instant', () => {
  assert.equal(toApiDateTime('2026-11-16T10:00'), '2026-11-16T08:00:00.000Z')
})

test('an empty input sends null', () => {
  assert.equal(toApiDateTime(''), null)
})

test('an API instant comes back as the wall time the input shows', () => {
  assert.equal(toLocalInput('2026-11-16T08:00:00+00:00'), '2026-11-16T10:00')
})
