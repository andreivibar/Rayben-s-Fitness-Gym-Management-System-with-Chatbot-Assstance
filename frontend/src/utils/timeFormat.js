export function formatTime12Hour(value) {
  if (!value) return '—'
  const match = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(String(value))
  if (!match) return String(value)

  const hours = Number(match[1])
  const minutes = Number(match[2])
  if (hours > 23 || minutes > 59) return String(value)

  const suffix = hours >= 12 ? 'PM' : 'AM'
  return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`
}
