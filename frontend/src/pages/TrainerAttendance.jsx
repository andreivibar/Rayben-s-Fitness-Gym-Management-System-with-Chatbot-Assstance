import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaArrowLeft, FaQrcode } from 'react-icons/fa'
import api from '../services/api'
import { formatTime12Hour } from '../utils/timeFormat'

const statCards = [
  { key: 'scheduled', label: "Today's shifts", color: 'text-slate-900' },
  { key: 'present', label: 'Present', color: 'text-emerald-700' },
  { key: 'late', label: 'Late', color: 'text-amber-700' },
  { key: 'absent', label: 'Absent', color: 'text-rose-700' },
]

function displayTime(value) {
  return value ? formatTime12Hour(value) : '—'
}

function statusStyle(status) {
  const styles = {
    present: 'bg-emerald-50 text-emerald-700',
    late: 'bg-amber-50 text-amber-700',
    absent: 'bg-rose-50 text-rose-700',
  }
  return styles[String(status || '').toLowerCase()] || 'bg-slate-100 text-slate-600'
}

function AttendanceStatus({ status }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${statusStyle(status)}`}>
      {status || 'Unknown'}
    </span>
  )
}

export default function TrainerAttendance() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api.get('/trainer_attendance.php')
      .then((response) => {
        if (active) setData(response.data)
      })
      .catch((requestError) => {
        console.error('Unable to load trainer attendance:', requestError)
        if (active) setError('We could not load your attendance. Please try again.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [])

  const summary = data?.summary || {}
  const schedule = Array.isArray(data?.schedule) ? data.schedule : []
  const scheduledShifts = schedule.filter((record) => record.availability_id !== null && record.availability_id !== undefined).length
  const attendanceDate = data?.attendance_date
    ? new Date(`${data.attendance_date}T12:00:00+08:00`).toLocaleDateString('en-PH', {
      timeZone: 'Asia/Manila',
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
    : 'Today'

  return (
    <main className="min-h-full bg-slate-50 px-4 py-5 pb-28 sm:px-6 sm:pb-8 lg:px-8">
      <div className="mx-auto max-w-6xl space-y-5">
        <header className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
          <div className="flex items-start gap-3">
            <Link
              to="/trainer"
              aria-label="Back to dashboard"
              className="mt-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50"
            >
              <FaArrowLeft aria-hidden="true" />
            </Link>
            <div className="min-w-0">
              <p className="text-sm font-medium text-teal-700">Trainer attendance</p>
              <h1 className="mt-0.5 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Today's Attendance</h1>
              <p className="mt-1 text-sm text-slate-500">{attendanceDate}</p>
            </div>
          </div>
          <Link
            to="/trainer/attendance/scan"
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
          >
            <FaQrcode aria-hidden="true" />
            Scan Gym QR
          </Link>
        </header>

        {error && (
          <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {error}
          </div>
        )}

        <section aria-label="Today's attendance statistics" className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          {statCards.map(({ key, label, color }) => (
            <div key={key} className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
              <p className="text-sm text-slate-500">{label}</p>
              <p className={`mt-2 text-2xl font-bold ${color}`}>
                {loading ? <span className="text-lg text-slate-300">—</span> : key === 'scheduled' ? scheduledShifts : Number(summary[key] || 0)}
              </p>
            </div>
          ))}
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-base font-semibold text-slate-900">Trainer information</h2>
              <p className="mt-1 text-sm text-slate-500">Attendance records for this trainer and date.</p>
            </div>
            <p className="text-sm font-medium text-slate-600">{attendanceDate}</p>
          </div>
          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="rounded-xl bg-slate-50 px-4 py-3">
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Trainer</p>
              <p className="mt-1 break-words font-semibold text-slate-900">{data?.trainer?.full_name || '—'}</p>
            </div>
            <div className="rounded-xl bg-slate-50 px-4 py-3">
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Trainer ID</p>
              <p className="mt-1 break-words font-semibold text-slate-900">{data?.trainer?.trainer_code || '—'}</p>
            </div>
          </div>
        </section>

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-4 sm:px-5">
            <div>
              <h2 className="font-semibold text-slate-900">Attendance records</h2>
              <p className="mt-0.5 text-sm text-slate-500">Scheduled and QR check-ins for today.</p>
            </div>
            {!loading && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{schedule.length} records</span>}
          </div>

          {loading ? (
            <div className="px-5 py-10 text-center text-sm text-slate-500">Loading attendance…</div>
          ) : schedule.length === 0 ? (
            <div className="px-5 py-10 text-center">
              <p className="font-medium text-slate-800">No attendance records yet</p>
              <p className="mt-1 text-sm text-slate-500">Your scheduled shifts and QR check-ins will appear here.</p>
            </div>
          ) : (
            <>
              <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[600px] text-left text-sm">
                  <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                      <th className="px-5 py-3 font-semibold">Shift</th>
                      <th className="px-5 py-3 font-semibold">Check-in</th>
                      <th className="px-5 py-3 font-semibold">Check-out</th>
                      <th className="px-5 py-3 font-semibold">Source</th>
                      <th className="px-5 py-3 font-semibold">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {schedule.map((record) => (
                      <tr key={record.id}>
                        <td className="px-5 py-4 font-medium text-slate-800">
                          {record.scheduled_start && record.scheduled_end
                            ? `${displayTime(record.scheduled_start)} – ${displayTime(record.scheduled_end)}`
                            : 'Unscheduled check-in'}
                        </td>
                        <td className="px-5 py-4 text-slate-600">{displayTime(record.time_in)}</td>
                        <td className="px-5 py-4 text-slate-600">{displayTime(record.time_out)}</td>
                        <td className="px-5 py-4 capitalize text-slate-600">{record.attendance_source || 'Shift'}</td>
                        <td className="px-5 py-4"><AttendanceStatus status={record.status} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <ul className="divide-y divide-slate-100 md:hidden">
                {schedule.map((record) => (
                  <li key={record.id} className="space-y-3 px-4 py-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="break-words text-sm font-semibold text-slate-900">
                          {record.scheduled_start && record.scheduled_end
                            ? `${displayTime(record.scheduled_start)} – ${displayTime(record.scheduled_end)}`
                            : 'Unscheduled check-in'}
                        </p>
                        <p className="mt-1 text-xs capitalize text-slate-500">{record.attendance_source || 'Shift'}</p>
                      </div>
                      <AttendanceStatus status={record.status} />
                    </div>
                    <div className="grid grid-cols-2 gap-3 text-sm">
                      <div><p className="text-xs text-slate-500">Check-in</p><p className="mt-0.5 font-medium text-slate-700">{displayTime(record.time_in)}</p></div>
                      <div><p className="text-xs text-slate-500">Check-out</p><p className="mt-0.5 font-medium text-slate-700">{displayTime(record.time_out)}</p></div>
                    </div>
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>
      </div>
    </main>
  )
}
