import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaArrowLeft } from 'react-icons/fa'
import api from '../services/api'

function formatDate(d) {
  if (!d) return ''
  try { return new Date(d + 'T00:00:00').toLocaleDateString() } catch { return d }
}

function formatTime(t) {
  if (!t) return '—'
  try { const dt = new Date('1970-01-01T' + t); return dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) } catch { return t }
}

const getTodayDate = () => {
  // Use Intl.DateTimeFormat to explicitly use Asia/Manila timezone
  const options = { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Manila' };
  const formatter = new Intl.DateTimeFormat('en-CA', options);
  return formatter.format(new Date());
};

export default function AttendanceRecords() {
  const navigate = useNavigate()
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [attendanceDate, setAttendanceDate] = useState(getTodayDate())
  const [status, setStatus] = useState('all')
  const [page, setPage] = useState(1)
  const perPage = 25
  const [meta, setMeta] = useState(null)
  const [summary, setSummary] = useState(null)
  const [viewRecord, setViewRecord] = useState(null)

  const fetchRecords = useCallback(async () => {
    try {
      const params = { search: search || undefined, attendance_date: attendanceDate || undefined, status: status || undefined, page, per_page: perPage }
      const res = await api.get('/attendance_records.php', { params })
      if (res?.data?.success) {
        setRecords(res.data.records || [])
        setMeta(res.data.meta || null)
        setSummary(res.data.summary || null)
      } else {
        setError(res?.data?.message || 'Unable to load attendance records.')
      }
    } catch (err) {
      const statusCode = err?.response?.status
      const msg = err?.response?.data?.message || err?.message || 'Unable to load attendance records.'
      if (statusCode) {
        setError(`HTTP ${statusCode}: ${msg}`)
      } else {
        setError(msg)
      }
    } finally { setLoading(false) }
  }, [attendanceDate, page, perPage, search, status])

  useEffect(() => {
    const requestId = setTimeout(fetchRecords, 0)
    return () => clearTimeout(requestId)
  }, [fetchRecords])

  const refresh = () => {
    setLoading(true)
    setError('')
    if (page === 1) {
      fetchRecords()
    } else {
      setPage(1)
    }
  }

  const filteredRecords = useMemo(() => records, [records])

  const initials = (name) => {
    if (!name) return ''
    const parts = name.split(' ').filter(Boolean)
    return (parts[0]?.[0] || '') + (parts[1]?.[0] || '')
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-20">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-4">
            <button
              onClick={() => navigate('/dashboard')}
              className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-slate-700 transition hover:bg-slate-50 hover:text-slate-900"
              title="Back to Dashboard"
            >
              <FaArrowLeft />
            </button>
            <div>
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Attendance Records</p>
              <h1 className="text-2xl font-semibold text-slate-900">View and monitor member attendance</h1>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <button onClick={refresh} className="rounded-2xl bg-violet-700 px-4 py-2 text-white">Refresh</button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-6 py-8">
        <div className="grid gap-6 lg:grid-cols-[1.4fr_0.6fr]">
          <div className="rounded-3xl bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div className="flex items-center gap-3">
                <input value={search} onChange={(e) => { setLoading(true); setError(''); setSearch(e.target.value) }} placeholder="Search member..." className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2" />
                <input type="date" value={attendanceDate} onChange={(e) => { setLoading(true); setError(''); setAttendanceDate(e.target.value); setPage(1) }} className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2" />
                <select value={status} onChange={(e) => { setLoading(true); setError(''); setStatus(e.target.value); setPage(1) }} className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2">
                  <option value="all">All</option>
                  <option value="checked_in">Checked In</option>
                  <option value="completed">Completed</option>
                </select>
              </div>
              <div className="text-sm text-slate-500">Showing latest records</div>
            </div>

            <div className="mt-6 overflow-auto rounded-2xl border border-slate-100">
              {loading ? (
                <div className="p-8 text-center text-slate-500">Loading attendance records...</div>
              ) : error ? (
                <div className="p-8 text-center">
                  <div className="mb-4 text-rose-600">Unable to load attendance records.</div>
                  <button onClick={refresh} className="rounded-2xl bg-violet-700 px-4 py-2 text-white">Retry</button>
                </div>
              ) : records.length === 0 ? (
                <div className="p-8 text-center text-slate-500">No attendance records found.</div>
              ) : (
                <table className="w-full table-auto text-sm">
                  <thead className="bg-slate-50 text-left text-xs text-slate-500 uppercase">
                    <tr>
                      <th className="px-4 py-3">Member</th>
                      <th className="px-4 py-3">Member Code</th>
                      <th className="px-4 py-3">Date</th>
                      <th className="px-4 py-3">Time In</th>
                      <th className="px-4 py-3">Time Out</th>
                      <th className="px-4 py-3">Status</th>
                      <th className="px-4 py-3"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {filteredRecords.map((r) => {
                      const statusText = r.time_in && r.time_out ? 'Completed' : (r.time_in ? 'Checked In' : '—')
                      return (
                        <tr key={r.id} className="text-slate-700 hover:bg-slate-50">
                          <td className="px-4 py-4">
                            <div className="flex items-center gap-3">
                              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">{initials(r.full_name)}</div>
                              <div className="font-medium">{r.full_name}</div>
                            </div>
                          </td>
                          <td className="px-4 py-4">{r.member_code}</td>
                          <td className="px-4 py-4">{formatDate(r.attendance_date)}</td>
                          <td className="px-4 py-4">{formatTime(r.time_in)}</td>
                          <td className="px-4 py-4">
                            {formatTime(r.time_out)}
                            {r.checkout_type === 'automatic' && (
                              <span className="ml-2 inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-700">
                                Auto
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-4">
                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${statusText === 'Completed' ? 'bg-emerald-100 text-emerald-700' : (statusText === 'Checked In' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700')}`}>{statusText}</span>
                          </td>
                          <td className="px-4 py-4 text-right"><button onClick={() => setViewRecord(r)} className="rounded-full border border-violet-700 px-3 py-1 text-sm text-violet-700">View</button></td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
            </div>

            {/* Pagination */}
            {meta && meta.total_pages > 1 && (
              <div className="mt-4 flex items-center justify-between">
                <div className="text-sm text-slate-600">Page {meta.page} of {meta.total_pages}</div>
                <div className="flex items-center gap-2">
                  <button disabled={meta.page <= 1} onClick={() => { setLoading(true); setError(''); setPage((p) => Math.max(1, p - 1)) }} className="rounded-2xl border border-slate-200 px-3 py-2">Previous</button>
                  <button disabled={meta.page >= meta.total_pages} onClick={() => { setLoading(true); setError(''); setPage((p) => Math.min(meta.total_pages, p + 1)) }} className="rounded-2xl border border-slate-200 px-3 py-2">Next</button>
                </div>
              </div>
            )}
          </div>

          {/* Sidebar summary */}
          <aside className="space-y-6">
            <div className="rounded-3xl bg-white p-6 shadow-sm">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Summary</p>
              {summary ? (
                <div className="mt-4 space-y-3">
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-sm text-slate-500">Today's Attendance</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">{summary.total_today}</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-sm text-slate-500">Currently Checked In</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">{summary.checked_in}</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-sm text-slate-500">Completed Today</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">{summary.completed}</p>
                  </div>
                </div>
              ) : (
                <div className="mt-4 text-sm text-slate-500">Loading summary...</div>
              )}
            </div>
          </aside>
        </div>

        {/* View modal */}
        {viewRecord && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black/40" onClick={() => setViewRecord(null)} />
            <div className="relative mx-4 w-full max-w-md rounded-2xl bg-white p-6 shadow-lg">
              <h3 className="text-lg font-semibold">Attendance Details</h3>
              <div className="mt-4 text-sm text-slate-700">
                <p><strong>Member:</strong> {viewRecord.full_name}</p>
                <p><strong>Member Code:</strong> {viewRecord.member_code}</p>
                <p><strong>Date:</strong> {formatDate(viewRecord.attendance_date)}</p>
                <p><strong>Time In:</strong> {formatTime(viewRecord.time_in)}</p>
                <p><strong>Time Out:</strong> {formatTime(viewRecord.time_out)}</p>
                {viewRecord.checkout_type === 'automatic' && <p><strong>Checkout:</strong> Automatically checked out at day end</p>}
                <p className="mt-2"><strong>Status:</strong> {viewRecord.time_in && viewRecord.time_out ? 'Completed' : (viewRecord.time_in ? 'Checked In' : '—')}</p>
              </div>
              <div className="mt-6 text-right"><button onClick={() => setViewRecord(null)} className="rounded-2xl bg-violet-700 px-4 py-2 text-white">Close</button></div>
            </div>
          </div>
        )}
      </main>
    </div>
  )
}
