import assert from 'node:assert/strict'
import test from 'node:test'
import { canAccessMemberProfile } from './memberProfileAccess.js'

test('allows monthly member accounts', () => {
  assert.equal(canAccessMemberProfile({ role: 'member', membership_type: 'monthly' }), true)
})

test('allows a member with no email address', () => {
  assert.equal(canAccessMemberProfile({ role: 'member', email: '' }), true)
})

test('pass payment state does not affect profile access', () => {
  for (const paymentStatus of ['pending', 'rejected', 'paid']) {
    assert.equal(
      canAccessMemberProfile({ role: 'member', membership_type: 'daily_pass', payment_status: paymentStatus }),
      true
    )
  }
})

test('rejects users who are not members', () => {
  assert.equal(canAccessMemberProfile({ role: 'trainer' }), false)
  assert.equal(canAccessMemberProfile(null), false)
})
