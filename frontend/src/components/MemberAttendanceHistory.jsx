import { useEffect, useMemo, useState } from 'react'
import { FaChevronLeft, FaChevronRight, FaSyncAlt } from 'react-icons/fa'
import api from '../services/api'

function manilaToday() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function formatDate(value, options = { dateStyle: 'long' }) {
  if (!value) return '—'
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { ...options, timeZone: 'Asia/Manila' }).format(date)
}

function formatTime(value) {
  if (!value) return '—'
  const [hours, minutes] = String(value).split(':').map(Number)
  if (Number.isNaN(hours) || Number.isNaN(minutes)) return value
  const suffix = hours >= 12 ? 'PM' : 'AM'
  return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`
}

function dateKey(year, month, day) {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export default function MemberAttendanceHistory() {
  const today = manilaToday()
  const [selectedMonth, setSelectedMonth] = useState(() => new Date(`${today.slice(0, 7)}-01T00:00:00Z`))
  const [records, setRecords] = useState([])
  const [summary, setSummary] = useState(null)
  const [selectedDate, setSelectedDate] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refreshToken, setRefreshToken] = useState(0)

  const year = selectedMonth.getUTCFullYear()
  const month = selectedMonth.getUTCMonth() + 1
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate()
  const firstWeekday = new Date(Date.UTC(year, month - 1, 1)).getUTCDay()
  const isCurrentMonth = selectedMonth.getUTCFullYear() === Number(today.slice(0, 4))
    && selectedMonth.getUTCMonth() + 1 === Number(today.slice(5, 7))

  useEffect(() => {
    let active = true
    const fetchHistory = async () => {
      setLoading(true)
      setError('')
      try {
        const response = await api.get('/member_attendance_history.php', { params: { month, year } })
        if (!response.data?.success) {
          throw new Error(response.data?.message || 'Unable to load attendance history.')
        }
        if (!active) return
        setRecords(response.data.records || [])
        setSummary(response.data.summary || null)
      } catch (requestError) {
        if (active) {
          setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load attendance history.')
          setRecords([])
          setSummary(null)
        }
      } finally {
        if (active) setLoading(false)
      }
    }

    fetchHistory()
    const refreshOnReturn = () => fetchHistory()
    window.addEventListener('focus', refreshOnReturn)
    window.addEventListener('gym:attendance-recorded', refreshOnReturn)
    return () => {
      active = false
      window.removeEventListener('focus', refreshOnReturn)
      window.removeEventListener('gym:attendance-recorded', refreshOnReturn)
    }
  }, [month, refreshToken, year])

  const recordsByDate = useMemo(() => records.reduce((grouped, record) => {
    const key = String(record.attendance_date).slice(0, 10)
    if (!grouped.has(key)) grouped.set(key, [])
    grouped.get(key).push(record)
    return grouped
  }, new Map()), [records])

  const selectedRecords = selectedDate ? recordsByDate.get(selectedDate) || [] : []
  const monthLabel = new Intl.DateTimeFormat('en-PH', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(selectedMonth)

  const changeMonth = (offset) => {
    setSelectedDate(null)
    setSelectedMonth((current) => new Date(Date.UTC(
      current.getUTCFullYear(),
      current.getUTCMonth() + offset,
      1
    )))
  }

  const isFutureDate = (key) => key > today

  return (
    <section aria-labelledby="attendance-history-heading" className="mt-4 rounded-2xl bg-white p-4 shadow-sm sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-[0.24em] text-slate-500">Your visits</p>
          <h2 id="attendance-history-heading" className="mt-1 text-lg font-semibold text-slate-900">Attendance History</h2>
        </div>
        <button
          type="button"
          onClick={() => setRefreshToken((value) => value + 1)}
          disabled={loading}
          className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          aria-label="Refresh attendance history"
        >
          <FaSyncAlt aria-hidden="true" /> Refresh
        </button>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div className="rounded-xl bg-violet-50 p-3">
          <p className="text-[11px] text-slate-600">Check-ins this month</p>
          <p className="mt-1 text-xl font-bold text-violet-800">{summary?.monthly_check_ins ?? '—'}</p>
        </div>
        <div className="rounded-xl bg-violet-50 p-3">
          <p className="text-[11px] text-slate-600">Attendance days</p>
          <p className="mt-1 text-xl font-bold text-violet-800">{summary?.monthly_attendance_days ?? '—'}</p>
        </div>
        <div className="rounded-xl bg-violet-50 p-3">
          <p className="text-[11px] text-slate-600">Most recent check-in</p>
          <p className="mt-1 text-sm font-bold text-violet-800">{formatDate(summary?.most_recent_check_in, { month: 'short', day: 'numeric', year: 'numeric' })}</p>
        </div>
        <div className="rounded-xl bg-violet-50 p-3">
          <p className="text-[11px] text-slate-600">Check-ins in {summary?.current_year || year}</p>
          <p className="mt-1 text-xl font-bold text-violet-800">{summary?.current_year_check_ins ?? '—'}</p>
        </div>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(220px,0.7fr)]">
        <div>
          <div className="mb-3 flex items-center justify-between gap-2">
            <button
              type="button"
              onClick={() => changeMonth(-1)}
              className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50"
              aria-label="Previous month"
            >
              <FaChevronLeft aria-hidden="true" />
            </button>
            <div className="text-center">
              <h3 className="font-semibold text-slate-900" aria-live="polite">{monthLabel}</h3>
              {!isCurrentMonth && (
                <button
                  type="button"
                  onClick={() => {
                    setSelectedDate(null)
                    setSelectedMonth(new Date(`${today.slice(0, 7)}-01T00:00:00Z`))
                  }}
                  className="mt-1 text-xs font-semibold text-violet-700 hover:underline"
                >
                  Return to current month
                </button>
              )}
            </div>
            <button
              type="button"
              onClick={() => changeMonth(1)}
              disabled={isCurrentMonth}
              className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
              aria-label="Next month"
            >
              <FaChevronRight aria-hidden="true" />
            </button>
          </div>

          <div role="grid" aria-label={`${monthLabel} attendance calendar`} className="grid grid-cols-7 gap-1">
            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day, index) => (
              <div role="columnheader" key={day} className="py-2 text-center text-[10px] font-semibold uppercase text-slate-500 sm:text-xs">
                <span className="sm:hidden">{day.slice(0, 1)}</span>
                <span className="hidden sm:inline">{day}</span>
                <span className="sr-only">{['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][index]}</span>
              </div>
            ))}
            {Array.from({ length: firstWeekday }, (_, index) => (
              <div key={`empty-${index}`} role="gridcell" aria-hidden="true" className="aspect-square" />
            ))}
            {Array.from({ length: daysInMonth }, (_, index) => {
              const day = index + 1
              const key = dateKey(year, month, day)
              const attendedRecords = recordsByDate.get(key) || []
              const isToday = key === today
              const isSelected = key === selectedDate
              const isFuture = isFutureDate(key)
              return (
                <div role="gridcell" key={key} aria-selected={isSelected}>
                  <button
                    type="button"
                    disabled={isFuture}
                    onClick={() => setSelectedDate(key)}
                    aria-label={`${formatDate(key, { dateStyle: 'full' })}${attendedRecords.length ? `, ${attendedRecords.length} check-in${attendedRecords.length === 1 ? '' : 's'}` : ', no recorded attendance'}${isToday ? ', today' : ''}`}
                    aria-pressed={isSelected}
                    className={`relative flex aspect-square w-full flex-col items-center justify-center rounded-xl border text-sm transition
                      ${isToday ? 'border-violet-600 ring-2 ring-violet-200' : 'border-transparent'}
                      ${isSelected ? 'bg-violet-50 text-violet-900' : 'text-slate-700 hover:bg-slate-50'}
                      ${attendedRecords.length ? 'font-bold' : 'font-normal'}
                      ${isFuture ? 'cursor-default text-slate-300' : ''}
                    `}
                  >
                    {day}
                    {attendedRecords.length > 0 && (
                      <span aria-hidden="true" className="absolute bottom-1 h-1.5 w-1.5 rounded-full bg-emerald-500" />
                    )}
                  </button>
                </div>
              )
            })}
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
            <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-emerald-500" /> Checked in</span>
            <span className="inline-flex items-center gap-2"><span className="h-3 w-3 rounded border-2 border-violet-600" /> Today</span>
            {loading && <span role="status" className="text-violet-700">Loading attendance…</span>}
          </div>
          {error && <p role="alert" className="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
          {!loading && !error && records.length === 0 && (
            <p className="mt-3 text-sm text-slate-500">No attendance recorded for {monthLabel}.</p>
          )}
        </div>

        <aside aria-live="polite" className="min-h-36 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <h3 className="font-semibold text-slate-900">
            {selectedDate ? formatDate(selectedDate) : 'Attendance details'}
          </h3>
          {!selectedDate ? (
            <p className="mt-2 text-sm text-slate-600">Select a calendar date to view its attendance records.</p>
          ) : selectedRecords.length === 0 ? (
            <p className="mt-2 text-sm text-slate-600">No attendance recorded for this date.</p>
          ) : (
            <ul className="mt-3 space-y-3">
              {selectedRecords.map((record) => (
                <li key={record.id} className="rounded-xl border border-slate-200 bg-white p-3 text-sm">
                  <p className="font-semibold text-emerald-800">
                    {record.attendance_status === 'completed' ? 'Completed' : 'Checked in'}
                  </p>
                  <p className="mt-1 text-slate-700">Check-in: {formatTime(record.time_in)}</p>
                  {record.time_out && <p className="text-slate-700">Check-out: {formatTime(record.time_out)}</p>}
                  <p className="mt-1 text-xs text-slate-500">Attendance record #{record.id}</p>
                </li>
              ))}
            </ul>
          )}
        </aside>
      </div>
    </section>
  )
}
