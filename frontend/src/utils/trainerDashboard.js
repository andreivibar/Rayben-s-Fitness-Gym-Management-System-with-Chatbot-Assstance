const UPCOMING_STATUSES = new Set(['pending', 'confirmed', 'awaiting_member'])

export function getUpcomingSessions(bookings, now = new Date(), limit = 4) {
  return bookings
    .filter((booking) => {
      if (!UPCOMING_STATUSES.has(String(booking.status || '').toLowerCase())) return false
      const date = String(booking.booking_date || '').slice(0, 10)
      const time = String(booking.start_time || '').slice(0, 5)
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) return false
      const start = new Date(`${date}T${time}:00+08:00`)
      return !Number.isNaN(start.getTime()) && start > now
    })
    .sort((first, second) => {
      const firstStart = `${String(first.booking_date).slice(0, 10)}T${String(first.start_time).slice(0, 5)}`
      const secondStart = `${String(second.booking_date).slice(0, 10)}T${String(second.start_time).slice(0, 5)}`
      return firstStart.localeCompare(secondStart)
    })
    .slice(0, limit)
}
