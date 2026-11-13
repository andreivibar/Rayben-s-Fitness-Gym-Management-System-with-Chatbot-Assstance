import assert from 'node:assert/strict'
import test from 'node:test'
import { getUpcomingSessions } from './trainerDashboard.js'

const now = new Date('2026-11-06T00:00:00+08:00')
const bookings = [
  { id: 1, booking_date: '2026-11-07', start_time: '10:00:00', status: 'confirmed' },
  { id: 2, booking_date: '2026-11-06', start_time: '09:00:00', status: 'pending' },
  { id: 3, booking_date: '2026-11-06', start_time: '08:00:00', status: 'rejected' },
  { id: 4, booking_date: '2026-11-06', start_time: '00:00:00', status: 'confirmed' },
  { id: 5, booking_date: '2026-11-06', start_time: '11:00:00', status: 'awaiting_member' },
  { id: 6, booking_date: '2026-11-08', start_time: '12:00:00', status: 'cancelled' },
]

test('returns only future active bookings in chronological order', () => {
  assert.deepEqual(getUpcomingSessions(bookings, now).map(({ id }) => id), [2, 5, 1])
})

test('limits the upcoming list without mutating its source', () => {
  const source = [...bookings]
  assert.deepEqual(getUpcomingSessions(bookings, now, 2).map(({ id }) => id), [2, 5])
  assert.deepEqual(bookings, source)
})

test('ignores malformed dates and times', () => {
  assert.deepEqual(
    getUpcomingSessions([{ booking_date: 'tomorrow', start_time: 'not-a-time', status: 'confirmed' }], now),
    []
  )
})
