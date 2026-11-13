import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaArrowLeft, FaSearch, FaSyncAlt } from 'react-icons/fa'
import api from '../services/api'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import { formatTime12Hour } from '../utils/timeFormat.js'

function requestErrorMessage(error) {
  const status = error?.response?.status
  if (status === 401) return 'Your session has expired. Sign in again to manage attendance.'
  if (status === 403) return 'Only administrators can manage checked-in attendance.'
  if (!error?.response && (error?.request || error?.code === 'ERR_NETWORK')) return 'Could not reach the server. Check your connection and try again.'
  if (status >= 500) return 'The attendance service could not complete the request. Try again shortly.'
  return error?.response?.data?.message || 'The attendance request could not be completed.'
}

function displayName(record) {
  return record.person_name || `${record.record_type === 'member' ? 'Member' : 'Trainer'} ${record.person_id}`
}

function formatShift(record) {
  if (record.availability_id === null || record.availability_id === undefined) return 'Standalone QR attendance'
  const category = {
    morning: 'Morning Shift',
    afternoon: 'Afternoon Shift',
    night: 'Night Shift',
    custom: 'Custom Schedule',
  }[record.schedule_type] || 'Scheduled shift'
  const start = formatTime12Hour(record.scheduled_start)
  const end = formatTime12Hour(record.scheduled_end)
  return `${category} · ${start}–${end}`
}

export default function CheckedInToday() {
  const [members, setMembers] = useState([])
  const [trainers, setTrainers] = useState([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [confirmation, setConfirmation] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const submittingRef = useRef(false)

  const loadAttendance = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true)
    else setLoading(true)
    setError('')
    try {
      const response = await api.get('/admin_checked_in.php')
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load current attendance.')
      setMembers((response.data.members || []).map((record) => ({ ...record, record_type: 'member' })))
      setTrainers((response.data.trainers || []).map((record) => ({ ...record, record_type: 'trainer' })))
    } catch (requestError) {
      setError(requestErrorMessage(requestError))
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(() => loadAttendance())
  }, [loadAttendance])

  const filteredRecords = useMemo(() => {
    const query = search.trim().toLowerCase()
    const filterRecords = (records) => records.filter((record) => !query || [
      displayName(record),
      record.person_id,
      record.person_code,
    ].some((value) => String(value || '').toLowerCase().includes(query)))
    return { members: filterRecords(members), trainers: filterRecords(trainers) }
  }, [members, search, trainers])

  const confirmCheckout = async () => {
    if (!confirmation || submittingRef.current) return
    submittingRef.current = true
    setSubmitting(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.post('/admin_checked_in.php', {
        record_type: confirmation.record_type,
        attendance_id: confirmation.attendance_id,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to check out this attendance session.')
      setConfirmation(null)
      setSuccess(`${displayName(confirmation)} checked out successfully.`)
      await loadAttendance(true)
    } catch (requestError) {
      const status = requestError?.response?.status
      setError(requestErrorMessage(requestError))
      setConfirmation(null)
      if (status === 409 || status === 404) await loadAttendance(true)
    } finally {
      submittingRef.current = false
      setSubmitting(false)
    }
  }

  const renderRecords = (records, type) => {
    if (loading) {
      return <div className="p-8 text-center text-sm text-slate-500">Loading checked-in {type}…</div>
    }
    if (!records.length) {
      return <div className="p-8 text-center text-sm text-slate-500">No checked-in {type} match your search.</div>
    }
    return (
      <div className="divide-y divide-slate-100">
        {records.map((record) => (
          <article key={`${record.record_type}-${record.attendance_id}`} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-semibold text-slate-900">{displayName(record)}</h3>
                <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700">
                  {record.status || 'Checked in'}
                </span>
              </div>
              <p className="mt-1 text-sm text-slate-600">
                {type === 'members' ? 'Member ID' : 'Trainer ID'} {record.person_code || record.person_id}
                {' · '}Checked in {formatTime12Hour(record.time_in)}
                {record.attendance_date && ` · ${record.attendance_date}`}
              </p>
              {record.record_type === 'trainer' && (
                <p className="mt-1 text-xs text-slate-500">{formatShift(record)}</p>
              )}
            </div>
            <button
              type="button"
              onClick={() => setConfirmation(record)}
              disabled={submitting}
              className="shrink-0 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Check Out
            </button>
          </article>
        ))}
      </div>
    )
  }

  const totalOpen = members.length + trainers.length

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div className="flex items-center gap-3">
            <Link to="/dashboard" aria-label="Back to dashboard" className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50">
              <FaArrowLeft />
            </Link>
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Attendance Management</p>
              <h1 className="text-xl font-semibold sm:text-2xl">Currently Checked In</h1>
            </div>
          </div>
          <button type="button" onClick={() => loadAttendance(true)} disabled={refreshing || submitting} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
            <FaSyncAlt className={refreshing ? 'animate-spin' : ''} /> <span className="hidden sm:inline">{refreshing ? 'Refreshing…' : 'Refresh'}</span>
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 sm:py-8">
        {error && <div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>}
        {success && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{success}</div>}

        <section className="grid gap-3 sm:grid-cols-3">
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-500">Members checked in</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{loading ? '…' : members.length}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-500">Trainers checked in</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{loading ? '…' : trainers.length}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-500">Currently on site</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{loading ? '…' : totalOpen}</p>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <label className="relative block">
            <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search by name, member ID, or trainer ID" className="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm text-slate-900 outline-none focus:border-violet-400" />
          </label>
        </section>

        {!loading && totalOpen === 0 && !error ? (
          <section className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center shadow-sm">
            <h2 className="font-semibold text-slate-800">Nobody is currently checked in</h2>
            <p className="mt-1 text-sm text-slate-500">Open member and trainer attendance sessions will appear here.</p>
          </section>
        ) : (
          <div className="grid gap-5 xl:grid-cols-2">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-100 px-4 py-4">
                <h2 className="font-semibold text-slate-900">Members</h2>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{members.length}</span>
              </div>
              {renderRecords(filteredRecords.members, 'members')}
            </section>
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-100 px-4 py-4">
                <h2 className="font-semibold text-slate-900">Trainers</h2>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{trainers.length}</span>
              </div>
              {renderRecords(filteredRecords.trainers, 'trainers')}
            </section>
          </div>
        )}
      </main>

      <ConfirmationModal
        open={Boolean(confirmation)}
        title="Confirm Manual Checkout"
        message={confirmation ? `Are you sure you want to check out ${displayName(confirmation)} manually? This will close their current attendance session.` : ''}
        confirmText="Confirm Checkout"
        cancelText="Cancel"
        variant="danger"
        loading={submitting}
        loadingText="Checking Out…"
        onCancel={() => {
          if (!submitting) setConfirmation(null)
        }}
        onConfirm={confirmCheckout}
      />
    </div>
  )
}
