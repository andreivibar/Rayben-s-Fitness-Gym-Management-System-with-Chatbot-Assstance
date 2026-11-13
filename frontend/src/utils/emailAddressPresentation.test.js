import assert from 'node:assert/strict'
import test from 'node:test'
import { getEmailAddressPresentation } from './emailAddressPresentation.js'

test('shows the add-email state for accounts without an address', () => {
  for (const email of [null, '', '   ']) {
    assert.deepEqual(getEmailAddressPresentation(email), {
      currentEmail: '',
      hasEmail: false,
      emptyMessage: 'No email address registered.',
      actionLabel: 'Add Email Address',
    })
  }
})

test('shows the change-email state for accounts with an address', () => {
  assert.deepEqual(getEmailAddressPresentation(' member@example.test '), {
    currentEmail: 'member@example.test',
    hasEmail: true,
    emptyMessage: '',
    actionLabel: 'Change Email Address',
  })
})
