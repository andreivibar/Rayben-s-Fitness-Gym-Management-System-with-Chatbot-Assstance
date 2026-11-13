import { useState } from 'react'

function parseTime(value) {
  if (!value) return { hour: '', minute: '', period: '' }
  const [rawHours, rawMinutes] = String(value).split(':')
  const hours = Number(rawHours)
  const minutes = Number(rawMinutes)
  if (!Number.isInteger(hours) || hours < 0 || hours > 23 || !Number.isInteger(minutes) || minutes < 0 || minutes > 59) {
    return { hour: '', minute: '', period: '' }
  }
  return {
    hour: String(hours % 12 || 12),
    minute: String(minutes).padStart(2, '0'),
    period: hours >= 12 ? 'PM' : 'AM',
  }
}

export default function Time12HourSelect({ value, onChange, onValidityChange, label, disabled = false, invalid = false }) {
  const [prevValue, setPrevValue] = useState(value)
  const [time, setTime] = useState(() => parseTime(value))

  if (value !== prevValue) {
    setPrevValue(value)
    setTime(parseTime(value))
  }

  const update = (field, nextValue) => {
    const next = { ...time, [field]: nextValue }
    setTime(next)
    const isComplete = Boolean(next.hour && next.minute && next.period)
    onValidityChange?.(isComplete)
    if (!isComplete) {
      onChange('')
      return
    }

    let hours = Number(next.hour)
    if (next.period === 'AM' && hours === 12) hours = 0
    if (next.period === 'PM' && hours !== 12) hours += 12
    onChange(`${String(hours).padStart(2, '0')}:${next.minute}`)
  }

  return (
    <div className="grid min-w-0 flex-1 grid-cols-3 gap-1">
      <select value={time.hour} onChange={(event) => update('hour', event.target.value)} aria-label={`${label} hour`} aria-invalid={invalid} disabled={disabled} className={`min-w-0 rounded-lg border bg-white px-2 py-2 text-sm disabled:bg-slate-100 ${invalid ? 'border-rose-400' : 'border-slate-200'}`}>
        <option value="">Hour</option>
        {Array.from({ length: 12 }, (_, index) => String(index + 1)).map((hour) => <option key={hour} value={hour}>{hour}</option>)}
      </select>
      <select value={time.minute} onChange={(event) => update('minute', event.target.value)} aria-label={`${label} minute`} aria-invalid={invalid} disabled={disabled} className={`min-w-0 rounded-lg border bg-white px-2 py-2 text-sm disabled:bg-slate-100 ${invalid ? 'border-rose-400' : 'border-slate-200'}`}>
        <option value="">Min</option>
        {Array.from({ length: 60 }, (_, minute) => String(minute).padStart(2, '0')).map((minute) => <option key={minute} value={minute}>{minute}</option>)}
      </select>
      <select value={time.period} onChange={(event) => update('period', event.target.value)} aria-label={`${label} AM or PM`} aria-invalid={invalid} disabled={disabled} className={`min-w-0 rounded-lg border bg-white px-2 py-2 text-sm disabled:bg-slate-100 ${invalid ? 'border-rose-400' : 'border-slate-200'}`}>
        <option value="">AM/PM</option>
        <option value="AM">AM</option>
        <option value="PM">PM</option>
      </select>
    </div>
  )
}
