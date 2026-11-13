import assert from 'node:assert/strict'
import test from 'node:test'
import { getManilaDateString } from './manilaDate.js'

test('uses the Manila date before the UTC day changes', () => {
  assert.equal(getManilaDateString(new Date('2026-11-05T16:30:00Z')), '2026-11-06')
})

test('advances the Manila date at midnight in Manila', () => {
  assert.equal(getManilaDateString(new Date('2026-11-05T15:59:59Z')), '2026-11-05')
  assert.equal(getManilaDateString(new Date('2026-11-05T16:00:00Z')), '2026-11-06')
})
