import assert from 'node:assert/strict'
import test from 'node:test'
import { getSafeErrorMessage } from './FeedbackContext.js'

test('uses concise client validation messages', () => {
  assert.equal(
    getSafeErrorMessage({ response: { status: 422, data: { message: 'Choose an available time.' } } }, 'Try again.'),
    'Choose an available time.'
  )
})

test('uses a safe fallback for server failures', () => {
  assert.equal(
    getSafeErrorMessage({ response: { status: 500, data: { message: 'Database error' } } }, 'Try again.'),
    'Try again.'
  )
})

test('does not expose technical or sensitive backend messages', () => {
  assert.equal(
    getSafeErrorMessage({ response: { status: 400, data: { message: 'SQLSTATE[HY000]: database failure' } } }, 'Try again.'),
    'Try again.'
  )
})
