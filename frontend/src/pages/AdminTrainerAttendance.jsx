import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaArrowLeft, FaSearch } from 'react-icons/fa'
import api from '../services/api'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import { formatTime12Hour } from '../utils/timeFormat'

export default function AdminTrainerAttendance() {
  // Initialize date using Asia/Manila timezone instead of browser local timezone
  const getInitialDate = () => {
    const options = { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Manila' };
    const formatter = new Intl.DateTimeFormat('en-CA', options);
    return formatter.format(new Date());
  };
  
  const [date, setDate] = useState(getInitialDate())
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')
  const [data, setData] = useState({ records: [], summary: { scheduled: 0, present: 0, late: 0, absent: 0 } })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const loadRecords = useCallback(async () => {
    try {
      const response = await api.get('/trainer_attendance_records.php', { params: { date, search: search || undefined, status } })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load trainer attendance.')
      setData(response.data)
      setError('')
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Unable to load trainer attendance.')
    } finally {
      setLoading(false)
    }
  }, [date, search, status])

  useEffect(() => {
    const requestId = setTimeout(loadRecords, 0)
    return () => clearTimeout(requestId)
  }, [loadRecords])

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur"><div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-4 sm:px-6"><Link to="/dashboard" className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50" title="Back to dashboard"><FaArrowLeft /></Link><div><p className="text-xs uppercase tracking-[0.24em] text-slate-500">Admin Attendance</p><h1 className="text-xl font-semibold sm:text-2xl">Trainer Attendance Records</h1></div></div></header>
      <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Today's Scheduled</p><p className="mt-3 text-3xl font-semibold">{data.summary.scheduled}</p></div><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Present</p><p className="mt-3 text-3xl font-semibold text-emerald-600">{data.summary.present}</p></div><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Late</p><p className="mt-3 text-3xl font-semibold text-amber-600">{data.summary.late}</p></div><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Absent</p><p className="mt-3 text-3xl font-semibold text-rose-600">{data.summary.absent}</p></div></div>
        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex flex-col gap-3 md:flex-row"><label className="relative flex-1"><FaSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={search} onChange={(event) => { setLoading(true); setError(''); setSearch(event.target.value) }} placeholder="Search trainer or code" className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-3 text-sm" /></label><input type="date" value={date} onChange={(event) => { setLoading(true); setError(''); setDate(event.target.value) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm" /><select value={status} onChange={(event) => { setLoading(true); setError(''); setStatus(event.target.value) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="all">All statuses</option><option value="scheduled">Scheduled</option><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option></select></div></section>
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full min-w-[950px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Trainer</th><th className="px-5 py-3">Code</th><th className="px-5 py-3">Date</th><th className="px-5 py-3">Source</th><th className="px-5 py-3">Schedule</th><th className="px-5 py-3">Time In</th><th className="px-5 py-3">Time Out</th><th className="px-5 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{loading ? <tr><td colSpan="8" className="px-5 py-10 text-center text-slate-500">Loading attendance...</td></tr> : error ? <tr><td colSpan="8" className="px-5 py-10 text-center text-rose-600">{error}</td></tr> : data.records.length === 0 ? <tr><td colSpan="8" className="px-5 py-10 text-center text-slate-500">No trainer attendance records found.</td></tr> : data.records.map((record) => <tr key={`${record.trainer_id}-${record.availability_id ?? `qr-${record.id}`}-${record.attendance_date}`}><td className="px-5 py-4 font-medium">{record.trainer_name}</td><td className="px-5 py-4 font-mono text-xs">{record.trainer_code}</td><td className="px-5 py-4">{record.attendance_date}</td><td className="px-5 py-4">{record.attendance_source === 'qr' ? 'Gym QR' : 'Scheduled'}</td><td className="px-5 py-4">{record.scheduled_start ? `${formatTime12Hour(record.scheduled_start)} - ${formatTime12Hour(record.scheduled_end)}` : '—'}</td><td className="px-5 py-4">{formatTime12Hour(record.time_in)}</td><td className="px-5 py-4">{formatTime12Hour(record.time_out)}</td><td className="px-5 py-4"><StatusBadge status={record.status} /></td></tr>)}</tbody></table></div></section>
      </main>
    </div>
  )
}
