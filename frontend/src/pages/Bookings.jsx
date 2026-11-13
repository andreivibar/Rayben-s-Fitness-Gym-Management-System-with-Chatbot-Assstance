import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  FaCalendarAlt,
  FaSearch,
  FaPlus,
  FaCheck,
  FaTimes,
  FaCheckDouble,
  FaClock,
} from 'react-icons/fa'
import api from '../services/api'
import ActionMenu from '../components/ui/ActionMenu.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import ReasonDialog from '../components/ui/ReasonDialog.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'

function formatDate(dateStr) {
  if (!dateStr) return ''
  try {
    const d = new Date(`${String(dateStr).slice(0, 10)}T00:00:00+08:00`)
    return d.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric' })
  } catch {
    return dateStr
  }
}

function formatTime(timeStr) {
  if (!timeStr) return ''
  const [hours, minutes] = String(timeStr).split(':').map(Number)
  if (Number.isNaN(hours) || Number.isNaN(minutes)) return timeStr
  const suffix = hours >= 12 ? 'PM' : 'AM'
  let hr = hours % 12
  if (hr === 0) hr = 12
  return `${hr}:${String(minutes).padStart(2, '0')} ${suffix}`
}

function formatDateTime(value) {
  if (!value) return '—'
  const raw = String(value)
  const parsed = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  return Number.isNaN(parsed.getTime())
    ? raw
    : parsed.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })
}

function getWeekDates(dateValue) {
  const [year, month, day] = String(dateValue).split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day))
  const offset = (date.getUTCDay() + 6) % 7
  date.setUTCDate(date.getUTCDate() - offset)
  return Array.from({ length: 7 }, (_, index) => {
    const current = new Date(date)
    current.setUTCDate(date.getUTCDate() + index)
    return current.toISOString().slice(0, 10)
  })
}

function addDaysToDate(date, days) {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

export default function Bookings() {
  const navigate = useNavigate()
  const { showSuccess, showRequestError } = useFeedback()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [bookings, setBookings] = useState([])
  const [cancellationHistory, setCancellationHistory] = useState([])
  const [currentTime, setCurrentTime] = useState(0)
  const [summary, setSummary] = useState({ total: 0, pending: 0, confirmed: 0, rejected: 0, completed: 0, cancelled: 0, awaiting_member: 0, awaiting_payment: 0, expired: 0, no_show: 0 })

  const getTodayDate = () => {
    const today = new Date();
    return today.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
  };

  // Filter states
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [dateFilter, setDateFilter] = useState('')
  const [trainerFilter, setTrainerFilter] = useState('')
  const [memberFilter, setMemberFilter] = useState('')
  const [calendarView, setCalendarView] = useState(false)

  // Create Booking Modal state
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [membersList, setMembersList] = useState([])
  const [trainersList, setTrainersList] = useState([])
  const [sessionTypes, setSessionTypes] = useState([])
  const [maxAdvanceDays, setMaxAdvanceDays] = useState(90)
  const [rescheduleBooking, setRescheduleBooking] = useState(null)
  const [rescheduleDate, setRescheduleDate] = useState('')
  const [rescheduleStart, setRescheduleStart] = useState('')
  const [rescheduleError, setRescheduleError] = useState('')
  const [rescheduleRequests, setRescheduleRequests] = useState([])

  const [formData, setFormData] = useState({
    member_id: '',
    trainer_id: '',
    booking_date: getTodayDate(),
    start_time: '',
    end_time: '',
    session_type_ids: [],
    notes: '',
  })
  const [formError, setFormError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [processingBookingId, setProcessingBookingId] = useState(null)
  const [statusConfirmation, setStatusConfirmation] = useState(null)
  const [rejectBooking, setRejectBooking] = useState(null)
  const [rescheduleReviewConfirmation, setRescheduleReviewConfirmation] = useState(null)
  const [processingRequestId, setProcessingRequestId] = useState(null)
  const bookingActionRef = useRef(false)
  const rescheduleReviewRef = useRef(false)

  useEffect(() => {
    const updateTime = () => setCurrentTime(Date.now())
    updateTime()
    const interval = window.setInterval(updateTime, 60_000)
    return () => window.clearInterval(interval)
  }, [])

  const fetchBookings = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = {}
      if (search) params.search = search
      if (statusFilter && statusFilter !== 'all') params.status = statusFilter
      if (calendarView) {
        const week = getWeekDates(dateFilter || getTodayDate())
        params.date_from = week[0]
        params.date_to = week[6]
      } else if (dateFilter) params.date = dateFilter
      if (trainerFilter) params.trainer_id = trainerFilter
      if (memberFilter) params.member_id = memberFilter

      const [res, historyResponse] = await Promise.all([
        api.get('/bookings.php', { params }),
        api.get('/bookings.php', { params: { action: 'cancellation-history' } }),
      ])
      if (res?.data?.success) {
        setBookings(res.data.bookings || [])
        setCancellationHistory(historyResponse.data?.history || [])
        setSummary(res.data.summary || { total: 0, pending: 0, confirmed: 0, rejected: 0, completed: 0, cancelled: 0, awaiting_member: 0, awaiting_payment: 0, expired: 0, no_show: 0 })
        setRescheduleRequests(res.data.reschedule_requests || [])
      } else {
        setError(res?.data?.message || 'Unable to load bookings.')
      }
    } catch (err) {
      setError(err?.response?.data?.message || 'Unable to load bookings.')
    } finally {
      setLoading(false)
    }
  }, [calendarView, dateFilter, memberFilter, search, statusFilter, trainerFilter])

  useEffect(() => {
    const raw = (() => { try { return localStorage.getItem('user') } catch { return null } })()
    if (!raw) return navigate('/login')
    let user
    try { user = JSON.parse(raw) } catch { return navigate('/login') }
    const role = (user?.role || '').toString().toLowerCase().trim()
    if (!['admin', 'administrator'].includes(role)) return navigate('/login')

    Promise.all([
      api.get('/members.php'),
      api.get('/trainers.php', { params: { per_page: 100 } }),
      api.get('/booking_settings.php'),
    ]).then(([memberResponse, trainerResponse, settingsResponse]) => {
      if (memberResponse.data?.success) setMembersList((memberResponse.data.members || []).filter((member) => String(member.status).toLowerCase() === 'active'))
      if (trainerResponse.data?.success) setTrainersList((trainerResponse.data.trainers || []).filter((trainer) => String(trainer.status).toLowerCase() === 'active'))
      if (settingsResponse.data?.success) {
        setSessionTypes((settingsResponse.data.session_types || []).filter((session) => Number(session.is_active) === 1))
        setMaxAdvanceDays(Number(settingsResponse.data.max_advance_days) || 90)
      }
    }).catch(() => setError('Unable to load booking filters and settings.'))
  }, [navigate])

  useEffect(() => {
    void Promise.resolve().then(fetchBookings)
  }, [fetchBookings])

  const openCreateModal = async () => {
    setFormError('')
    setFormData({
      member_id: '',
      trainer_id: '',
      booking_date: getTodayDate(),
      start_time: '',
      end_time: '',
      session_type_ids: sessionTypes[0] ? [Number(sessionTypes[0].id)] : [],
      notes: '',
    })
    setShowCreateModal(true)

    if (sessionTypes.length === 0) setFormError('No active session types are configured. Open Booking Settings first.')
  }

  const handleStatusChange = async (id, newStatus, reason = '') => {
    if (bookingActionRef.current) return false
    bookingActionRef.current = true
    setProcessingBookingId(id)
    try {
      const action = newStatus === 'cancelled' ? 'cancel' : newStatus === 'no_show' ? 'mark-no-show' : 'status'
      const res = await api.post(`/bookings.php?action=${action}`, { id, status: newStatus, reason })
      if (!res?.data?.success) throw new Error('Booking status update was not confirmed.')
      showSuccess(res.data.message || 'Booking status updated.')
      await fetchBookings()
      return true
    } catch (requestError) {
      showRequestError(requestError, 'Unable to update this booking. Refresh the list and try again.', 'Booking update failed')
      return false
    } finally {
      bookingActionRef.current = false
      setProcessingBookingId(null)
    }
  }

  const requestStatusChange = (booking, status) => {
    if (status === 'rejected') {
      setRejectBooking(booking)
      return
    }
    setStatusConfirmation({ id: booking.id, status })
  }

  const confirmStatusChange = async () => {
    if (!statusConfirmation) return
    const changed = await handleStatusChange(statusConfirmation.id, statusConfirmation.status, statusConfirmation.reason || '')
    if (changed) setStatusConfirmation(null)
  }

  const rejectBookingRequest = async (reason) => {
    if (!rejectBooking) return
    const changed = await handleStatusChange(rejectBooking.id, 'rejected', reason)
    if (changed) setRejectBooking(null)
  }

  const handleFormSubmit = async (e) => {
    e.preventDefault()
    setFormError('')
    if (!formData.member_id) return setFormError('Please select a member.')
    if (!formData.booking_date || !formData.trainer_id || formData.session_type_ids.length === 0 || !formData.start_time) return setFormError('Choose a trainer, at least one session, preferred date, and start time.')
    const combinedDuration = sessionTypes
      .filter((session) => formData.session_type_ids.includes(Number(session.id)))
      .reduce((total, session) => total + Number(session.duration_minutes), 0)
    if (combinedDuration > 480) return setFormError('The combined session duration cannot exceed 480 minutes.')

    setIsSubmitting(true)
    try {
      const res = await api.post('/bookings.php?action=create', formData)
      if (res?.data?.success) {
        setShowCreateModal(false)
        showSuccess(res.data.message || 'Booking request created.')
        await fetchBookings()
      } else {
        setFormError(res?.data?.message || 'Failed to create booking.')
      }
    } catch (err) {
      setFormError(err?.response?.data?.message || 'Failed to create booking.')
    } finally {
      setIsSubmitting(false)
    }
  }

  const requestReschedule = (booking) => {
    setRescheduleBooking(booking)
    setRescheduleDate('')
    setRescheduleStart('')
    setRescheduleError('')
  }

  const submitReschedule = async (event) => {
    event.preventDefault()
    if (!rescheduleBooking || !rescheduleStart) return
    setRescheduleError('')
    try {
      const response = await api.post('/bookings.php?action=reschedule', {
        id: rescheduleBooking.id,
        booking_date: rescheduleDate,
        start_time: rescheduleStart,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to reschedule booking.')
      setRescheduleBooking(null)
      showSuccess(response.data.message || 'Booking rescheduled.')
      await fetchBookings()
    } catch (requestError) {
      setRescheduleError(requestError?.response?.data?.message || requestError?.message || 'Unable to reschedule booking.')
    }
  }

  const resolveReschedule = async (requestId, decision) => {
    if (rescheduleReviewRef.current) return false
    rescheduleReviewRef.current = true
    setProcessingRequestId(requestId)
    try {
      const response = await api.post('/bookings.php?action=resolve-reschedule', { request_id: requestId, decision })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to review request.')
      showSuccess(response.data.message || 'Reschedule request reviewed.')
      await fetchBookings()
      setRescheduleReviewConfirmation(null)
      return true
    } catch (requestError) {
      showRequestError(requestError, 'Unable to review the reschedule request. Refresh and try again.', 'Request review failed')
      return false
    } finally {
      rescheduleReviewRef.current = false
      setProcessingRequestId(null)
    }
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      {/* Header */}
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6">
          <div>
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-slate-500 sm:text-xs">
              Rayben's Gym Management System
            </p>
            <h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Bookings Management</h1>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <Link to="/admin/settings?section=booking" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Booking Settings</Link>
            <Link to="/trainer/schedule" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Trainer availability</Link>
            <Link to="/dashboard" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
              Back to Dashboard
            </Link>
            <button onClick={openCreateModal} className="flex items-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-violet-200 hover:bg-violet-800">
              <FaPlus /> New Booking
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {/* Statistics Cards */}
        <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-slate-500">Total Bookings</p>
            <p className="mt-3 text-3xl font-semibold text-slate-900">{summary.total}</p>
            <p className="mt-1 text-xs text-slate-500">All recorded sessions</p>
          </div>
          <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-amber-700">Pending Requests</p>
            <p className="mt-3 text-3xl font-semibold text-amber-900">{summary.pending}</p>
            <p className="mt-1 text-xs text-amber-700">Awaiting confirmation</p>
          </div>
          <div className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-emerald-700">Confirmed</p>
            <p className="mt-3 text-3xl font-semibold text-emerald-900">{summary.confirmed}</p>
            <p className="mt-1 text-xs text-emerald-700">Ready for training</p>
          </div>
          <div className="rounded-2xl border border-blue-200 bg-blue-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-blue-700">Completed</p>
            <p className="mt-3 text-3xl font-semibold text-blue-900">{summary.completed}</p>
            <p className="mt-1 text-xs text-blue-700">Finished sessions</p>
          </div>
          <div className="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-rose-700">Rejected</p>
            <p className="mt-3 text-3xl font-semibold text-rose-900">{summary.rejected || 0}</p>
            <p className="mt-1 text-xs text-rose-700">Declined requests</p>
          </div>
          <div className="rounded-2xl border border-violet-200 bg-violet-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-violet-700">Awaiting Member</p>
            <p className="mt-3 text-3xl font-semibold text-violet-900">{summary.awaiting_member || 0}</p>
            <p className="mt-1 text-xs text-violet-700">Alternative times proposed</p>
          </div>
          <div className="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-amber-700">Awaiting Payment</p>
            <p className="mt-3 text-3xl font-semibold text-amber-900">{summary.awaiting_payment || 0}</p>
            <p className="mt-1 text-xs text-amber-700">Approved, within the payment deadline</p>
          </div>
        </div>

        {/* Filters and Search Bar */}
        <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative min-w-0 flex-1">
              <FaSearch className="absolute left-3.5 top-3.5 text-slate-400 text-sm" />
              <input
                type="text"
                placeholder="Search by code, member name, or trainer..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 py-2.5 text-sm outline-none focus:border-violet-400 focus:bg-white"
              />
            </div>
            <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-violet-400"
              >
                <option value="all">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="rejected">Rejected</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="awaiting_member">Awaiting member</option>
                <option value="awaiting_payment">Awaiting payment</option>
                <option value="expired">Expired</option>
                <option value="no_show">No-show</option>
              </select>
              <select value={trainerFilter} onChange={(event) => setTrainerFilter(event.target.value)} aria-label="Filter by trainer" className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <option value="">All trainers</option>
                {trainersList.map((trainer) => <option key={trainer.trainer_id} value={trainer.trainer_id}>{trainer.full_name}</option>)}
              </select>
              <select value={memberFilter} onChange={(event) => setMemberFilter(event.target.value)} aria-label="Filter by member" className="max-w-48 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <option value="">All members</option>
                {membersList.map((member) => <option key={member.id} value={member.id}>{member.full_name}</option>)}
              </select>
              <button type="button" onClick={() => setCalendarView((current) => !current)} className="whitespace-nowrap rounded-xl border border-violet-200 bg-violet-50 px-3 py-2.5 text-xs font-medium text-violet-700">
                {calendarView ? 'List view' : 'Weekly calendar'}
              </button>
              <input
                type="date"
                value={dateFilter}
                onChange={(e) => setDateFilter(e.target.value)}
                className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-violet-400"
              />
              {dateFilter && (
                <button
                  onClick={() => setDateFilter('')}
                  className="rounded-xl border border-slate-200 px-3 py-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                >
                  Clear Date
                </button>
              )}
            </div>
          </div>
        </div>

        {calendarView && (
          <section aria-label="Weekly booking calendar" className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {getWeekDates(dateFilter || getTodayDate()).map((date) => {
              const dayBookings = bookings.filter((booking) => booking.booking_date === date)
              return (
                <article key={date} className="min-h-40 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <h2 className="border-b border-slate-100 pb-2 text-sm font-semibold text-slate-900">{formatDate(date)}</h2>
                  {loading ? <p className="py-4 text-xs text-slate-500">Loading…</p> : dayBookings.length === 0 ? <p className="py-4 text-xs text-slate-400">No sessions</p> : (
                    <ul className="mt-2 space-y-2">
                      {dayBookings.map((booking) => (
                        <li key={booking.id} className="rounded-xl border border-violet-100 bg-violet-50/60 p-2 text-xs">
                          <p className="font-semibold text-violet-900">{formatTime(booking.start_time)}–{formatTime(booking.end_time)} · {booking.session_type}</p>
                          {booking.session_items?.length > 1 && <ul className="mt-1 text-slate-600">{booking.session_items.map((session, index) => <li key={`${booking.id}-${session.session_type_id ?? index}`}>{session.session_name} · {session.duration_minutes} min</li>)}</ul>}
                          <p className="mt-1 text-slate-700">{booking.member_name} · {booking.trainer_name || 'No trainer'}</p>
                          <StatusBadge status={booking.status} />
                        </li>
                      ))}
                    </ul>
                  )}
                </article>
              )
            })}
          </section>
        )}

        {/* Table View */}
        {!calendarView && <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-slate-700">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="px-6 py-4 font-semibold">Booking Code</th>
                  <th className="px-6 py-4 font-semibold">Member</th>
                  <th className="px-6 py-4 font-semibold">Trainer</th>
                  <th className="px-6 py-4 font-semibold">Session & Time</th>
                  <th className="px-6 py-4 font-semibold">Status</th>
                  <th className="px-6 py-4 text-right font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-12 text-center text-slate-500">
                      Loading bookings...
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-12 text-center text-rose-600">
                      {error}
                    </td>
                  </tr>
                ) : bookings.length === 0 ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-12 text-center text-slate-500">
                      <p>
                        {dateFilter
                          ? `No bookings found for ${formatDate(dateFilter)}.`
                          : 'No bookings match the selected filters.'}
                      </p>
                      {dateFilter && (
                        <button
                          type="button"
                          onClick={() => setDateFilter('')}
                          className="mt-3 rounded-lg border border-violet-200 px-3 py-2 text-xs font-medium text-violet-700 hover:bg-violet-50"
                        >
                          Clear date filter to view all dates
                        </button>
                      )}
                    </td>
                  </tr>
                ) : (
                  bookings.map((booking) => (
                    <tr key={booking.id} className="hover:bg-slate-50/80 transition">
                      <td className="px-6 py-4 font-medium text-slate-900">
                        {booking.booking_code}
                      </td>
                      <td className="px-6 py-4">
                        <p className="font-semibold text-slate-900">{booking.member_name}</p>
                        <p className="text-xs text-slate-500">{booking.member_code}</p>
                      </td>
                      <td className="px-6 py-4">
                        {booking.trainer_name ? (
                          <div>
                            <p className="font-medium text-slate-900">{booking.trainer_name}</p>
                            <p className="text-xs text-slate-500">{booking.trainer_code}</p>
                          </div>
                        ) : (
                          <span className="text-xs italic text-slate-400">General / Unassigned</span>
                        )}
                      </td>
                      <td className="px-6 py-4">
                        <p className="font-medium text-slate-900">{booking.session_type}</p>
                        {booking.session_items?.length > 0 && <ul className="mt-1 list-inside list-disc text-xs text-slate-600">{booking.session_items.map((session, index) => <li key={`${booking.id}-${session.session_type_id ?? index}`}>{session.session_name} · {session.duration_minutes} min{session.unit_price !== null && session.unit_price !== undefined ? ` · ${session.currency || 'PHP'} ${Number(session.unit_price).toFixed(2)}` : ' · price unknown'}</li>)}</ul>}
                        {booking.subtotal !== null && booking.subtotal !== undefined && (
                          <div className="mt-1 text-xs text-slate-700">
                            <p>Subtotal {booking.price_currency || 'PHP'} {Number(booking.subtotal).toFixed(2)} · Credit {booking.price_currency || 'PHP'} {Number(booking.credit_amount || 0).toFixed(2)}</p>
                            <p className="font-semibold">Due {booking.price_currency || 'PHP'} {Number(booking.amount_due).toFixed(2)}</p>
                            {booking.payment_deadline_at && <p>Payment deadline {booking.payment_deadline_at} · {booking.latest_payment_status || 'not yet submitted'}</p>}
                          </div>
                        )}
                        <div className="mt-1 flex flex-wrap gap-1">
                          {!booking.within_normal_shift && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Outside trainer shift</span>}
                          {booking.has_confirmed_conflict && <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-800">Confirmed conflict</span>}
                          {booking.has_pending_conflict && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">Overlapping pending request</span>}
                        </div>
                        {booking.confirmation_issue && <p className="mt-1 text-xs text-rose-700">{booking.confirmation_issue}</p>}
                        {booking.alternative_proposal && <p className="mt-1 text-xs text-violet-700">Alternative {booking.alternative_proposal.status}: {formatDate(booking.alternative_proposal.proposed_date)} {formatTime(booking.alternative_proposal.proposed_start_time)} (requested {formatDate(booking.alternative_proposal.original_date)} {formatTime(booking.alternative_proposal.original_start_time)})</p>}
                        <div className="flex items-center gap-2 mt-0.5 text-xs text-slate-500">
                          <FaCalendarAlt className="text-slate-400" />
                          <span>{formatDate(booking.booking_date)}</span>
                          <span>•</span>
                          <FaClock className="text-slate-400" />
                          <span>{formatTime(booking.start_time)} - {formatTime(booking.end_time)} · {booking.duration_minutes} min</span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <StatusBadge status={booking.status} />
                      </td>
                      <td className="px-6 py-4 text-right">
                        <ActionMenu
                          actions={[
                            {
                              label: 'Confirm Booking',
                              icon: <FaCheck className="text-emerald-600" />,
                              disabled: processingBookingId !== null || booking.status !== 'pending' || Boolean(booking.confirmation_issue),
                              onClick: () => requestStatusChange(booking, 'confirmed'),
                            },
                            {
                              label: 'Reject Request',
                              icon: <FaTimes className="text-rose-600" />,
                              variant: 'danger',
                              disabled: processingBookingId !== null || !['pending', 'awaiting_member'].includes(booking.status),
                              onClick: () => requestStatusChange(booking, 'rejected'),
                            },
                            {
                              label: 'Reschedule',
                              icon: <FaCalendarAlt className="text-violet-600" />,
                              disabled: processingBookingId !== null || !['pending', 'confirmed'].includes(booking.status) || !booking.trainer_id,
                              onClick: () => requestReschedule(booking),
                            },
                            {
                              label: 'Mark Completed',
                              icon: <FaCheckDouble className="text-blue-600" />,
                              disabled: processingBookingId !== null || booking.status !== 'confirmed',
                              onClick: () => requestStatusChange(booking, 'completed'),
                            },
                            {
                              label: 'Cancel Booking',
                              icon: <FaTimes className="text-rose-600" />,
                              variant: 'danger',
                              disabled: processingBookingId !== null || !['pending', 'confirmed', 'awaiting_member', 'awaiting_payment'].includes(booking.status),
                              onClick: () => requestStatusChange(booking, 'cancelled'),
                            },
                            {
                              label: 'Mark No-show',
                              icon: <FaTimes className="text-amber-600" />,
                              variant: 'danger',
                              disabled: processingBookingId !== null || booking.status !== 'confirmed'
                                || currentTime === 0
                                || new Date(`${String(booking.booking_date).slice(0, 10)}T${String(booking.end_time).slice(0, 5)}:00+08:00`).getTime() > currentTime,
                              onClick: () => requestStatusChange(booking, 'no_show'),
                            },
                          ]}
                        />
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div></div>}

          {rescheduleRequests.length > 0 && (
            <section className="mt-6 rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
              <h2 className="font-semibold text-slate-900">Member reschedule requests</h2>
              <div className="mt-3 space-y-2">
                {rescheduleRequests.map((request) => (
                  <div key={request.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-3">
                    <p className="text-sm text-slate-800">{request.booking_code} · {request.member_name}: {formatDate(request.requested_date)} {formatTime(request.requested_start_time)}–{formatTime(request.requested_end_time)}</p>
                    <div className="flex gap-2">
                      <button type="button" disabled={processingRequestId !== null} onClick={() => setRescheduleReviewConfirmation({ id: request.id, decision: 'approve' })} className="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Approve</button>
                      <button type="button" disabled={processingRequestId !== null} onClick={() => setRescheduleReviewConfirmation({ id: request.id, decision: 'reject' })} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 disabled:opacity-50">Decline</button>
                    </div>
                  </div>
                ))}
              </div>
            </section>
          )}

        <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div><h2 className="text-lg font-semibold text-slate-900">Cancellation & no-show history</h2><p className="mt-1 text-sm text-slate-600">Includes automatic staff-response expirations and retained cancellation reasons.</p></div>
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{cancellationHistory.length} records</span>
          </div>
          <div className="mt-4 space-y-2">
            {cancellationHistory.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No cancellation or no-show events recorded.</p> : cancellationHistory.map((event) => (
              <article key={event.id} className="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm sm:grid-cols-[1fr_auto] sm:items-start">
                <div className="min-w-0">
                  <p className="font-semibold text-slate-900">{event.booking_code || `Booking #${event.booking_id}`} · {event.new_status}</p>
                  <p className="mt-1 text-xs capitalize text-slate-600">{event.cancellation_category.replaceAll('_', ' ')} · Initiated by {event.initiating_user_name || event.initiating_role}</p>
                  {event.cancellation_reason && <p className="mt-1 break-words text-xs text-slate-700">Reason: {event.cancellation_reason}</p>}
                  <p className="mt-1 text-xs text-slate-500">Refund eligibility: {Number(event.refund_eligible) ? 'Yes' : 'No'} · Credit restoration decision: {Number(event.credit_restoration_decision) ? 'Yes' : 'No'}</p>
                </div>
                <time className="text-xs text-slate-500">{formatDateTime(event.cancelled_at)}</time>
              </article>
            ))}
          </div>
        </section>
      </main>

      {/* Modal: Create Booking */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
          <div className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
            <div className="mb-5 flex items-center justify-between">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Admin Action</p>
                <h3 className="mt-1 text-2xl font-semibold text-slate-900">New Booking Request</h3>
              </div>
              <button
                onClick={() => setShowCreateModal(false)}
                className="rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-600 hover:bg-slate-100"
              >
                Close
              </button>
            </div>

            <form onSubmit={handleFormSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-700">Member</label>
                <select
                  value={formData.member_id}
                  onChange={(e) => {
                    const memberId = e.target.value
                    setFormData((prev) => ({ ...prev, member_id: memberId, start_time: '', end_time: '' }))
                  }}
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                  required
                >
                  <option value="">-- Select Member --</option>
                  {membersList.map(m => (
                    <option key={m.id} value={m.id}>{m.full_name} ({m.member_code})</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Trainer</label>
                <select
                  value={formData.trainer_id}
                  onChange={(e) => {
                    const tid = e.target.value
                    setFormData((prev) => ({ ...prev, trainer_id: tid }))
                  }}
                  required
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                >
                  <option value="">Select a trainer</option>
                  {trainersList.map((trainer) => <option key={trainer.trainer_id} value={trainer.trainer_id}>{trainer.full_name} ({trainer.trainer_code})</option>)}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Booking Date</label>
                <input
                  type="date"
                  value={formData.booking_date}
                  onChange={(e) => setFormData((prev) => ({ ...prev, booking_date: e.target.value }))}
                  min={getTodayDate()}
                  max={addDaysToDate(getTodayDate(), maxAdvanceDays)}
                  required
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Session Type</label>
                <div className="mt-2 space-y-2">
                  {sessionTypes.map((session) => {
                    const selected = formData.session_type_ids.includes(Number(session.id))
                    return (
                      <label key={session.id} className={`flex cursor-pointer items-center justify-between rounded-xl border p-3 transition ${selected ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-300' : 'border-slate-200 bg-white hover:border-violet-300'}`}>
                        <span className="flex items-center gap-3">
                          <input type="checkbox" checked={selected} onChange={(event) => setFormData((previous) => ({
                            ...previous,
                            session_type_ids: event.target.checked
                              ? [...previous.session_type_ids, Number(session.id)]
                              : previous.session_type_ids.filter((id) => id !== Number(session.id)),
                          }))} className="h-4 w-4 accent-violet-700" />
                          <span className="text-sm font-medium text-slate-800">{session.name}</span>
                        </span>
                        <span className="text-xs text-slate-500">{session.duration_minutes} min</span>
                      </label>
                    )
                  })}
                </div>
                {(() => {
                  const selectedSessions = sessionTypes.filter((session) => formData.session_type_ids.includes(Number(session.id)))
                  const totalDuration = selectedSessions.reduce((total, session) => total + Number(session.duration_minutes), 0)
                  return <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">Selected: {selectedSessions.length} {selectedSessions.length === 1 ? 'session' : 'sessions'} · Total duration: {totalDuration} minutes</p>
                })()}
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Preferred start time</label>
                <input type="time" value={formData.start_time} onChange={(event) => setFormData((prev) => ({ ...prev, start_time: event.target.value }))} required className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm" />
                <p className="mt-1 text-xs text-slate-500">The trainer will review the complete request as one appointment.</p>
              </div>

              {formData.start_time && <p className="rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">Review request: {sessionTypes.filter((session) => formData.session_type_ids.includes(Number(session.id))).map((session) => `${session.name} (${session.duration_minutes} min)`).join(', ') || 'Select sessions'}, {formData.booking_date} at {formatTime(formData.start_time)} · {sessionTypes.filter((session) => formData.session_type_ids.includes(Number(session.id))).reduce((total, session) => total + Number(session.duration_minutes), 0)} minutes · {trainersList.find((trainer) => String(trainer.trainer_id) === String(formData.trainer_id))?.full_name}</p>}

              <div>
                <label className="block text-sm font-medium text-slate-700">Notes / Instructions</label>
                <textarea
                  rows="2"
                  value={formData.notes}
                  onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                  placeholder="Optional session notes..."
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-violet-400"
                />
              </div>

              {formError && (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  {formError}
                </div>
              )}

              <div className="flex items-center justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="rounded-2xl bg-violet-700 px-5 py-2 text-sm font-medium text-white hover:bg-violet-800 disabled:opacity-70"
                >
                  {isSubmitting ? 'Creating...' : 'Create booking request'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {rescheduleBooking && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
          <form onSubmit={submitReschedule} className="w-full max-w-md space-y-4 rounded-3xl bg-white p-6 shadow-2xl">
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-slate-500">{rescheduleBooking.booking_code}</p>
              <h2 className="mt-1 text-xl font-semibold text-slate-900">Reschedule booking</h2>
            </div>
            <label className="block text-sm font-medium text-slate-700">New date
              <input type="date" min={getTodayDate()} value={rescheduleDate} onChange={(event) => {
                const date = event.target.value
                setRescheduleDate(date)
                setRescheduleStart('')
              }} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" />
            </label>
            <label className="block text-sm font-medium text-slate-700">New start time
              <input type="time" value={rescheduleStart} onChange={(event) => setRescheduleStart(event.target.value)} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" />
            </label>
            {rescheduleError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{rescheduleError}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setRescheduleBooking(null)} className="rounded-xl border border-slate-300 px-4 py-2 text-sm">Close</button>
              <button type="submit" disabled={!rescheduleStart} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save new time</button>
            </div>
          </form>
        </div>
      )}
      <ReasonDialog
        open={Boolean(rejectBooking)}
        title="Reject booking request"
        description="The member and assigned trainer will be notified. You may include an optional reason."
        label="Rejection reason"
        placeholder="Optional reason for declining this request"
        confirmText="Reject Request"
        loading={processingBookingId !== null}
        onConfirm={rejectBookingRequest}
        onCancel={() => setRejectBooking(null)}
      />
      <ConfirmationModal
        open={Boolean(statusConfirmation)}
        title={statusConfirmation?.status === 'confirmed' ? 'Confirm this booking?' : statusConfirmation?.status === 'cancelled' ? 'Cancel this booking?' : statusConfirmation?.status === 'no_show' ? 'Mark this member as no-show?' : 'Mark session completed?'}
        message={statusConfirmation?.status === 'confirmed'
          ? 'The booking will be confirmed for its scheduled time.'
          : statusConfirmation?.status === 'cancelled'
            ? <><p>The booking will be cancelled through the standard refund and credit policy.</p><label className="mt-3 block text-left text-xs font-medium text-slate-700">Cancellation reason<textarea rows={3} maxLength={1000} value={statusConfirmation.reason || ''} onChange={(event) => setStatusConfirmation((current) => ({ ...current, reason: event.target.value }))} className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm font-normal text-slate-900" /></label></>
            : statusConfirmation?.status === 'no_show'
              ? 'Only use this after the scheduled session has ended. No refund or consumed-credit restoration will be issued.'
            : 'Confirm that the scheduled session has ended before marking it complete.'}
        confirmText={statusConfirmation?.status === 'confirmed' ? 'Confirm Booking' : statusConfirmation?.status === 'cancelled' ? 'Cancel Booking' : statusConfirmation?.status === 'no_show' ? 'Mark No-show' : 'Mark Completed'}
        variant={['cancelled', 'no_show'].includes(statusConfirmation?.status) ? 'danger' : 'default'}
        loading={processingBookingId !== null}
        loadingText="Updating…"
        onConfirm={confirmStatusChange}
        onCancel={() => setStatusConfirmation(null)}
      />
      <ConfirmationModal
        open={Boolean(rescheduleReviewConfirmation)}
        title={rescheduleReviewConfirmation?.decision === 'approve' ? 'Approve reschedule request?' : 'Decline reschedule request?'}
        message={rescheduleReviewConfirmation?.decision === 'approve'
          ? 'The requested time will be checked and applied if it remains available.'
          : 'The member will be notified that the reschedule request was declined.'}
        confirmText={rescheduleReviewConfirmation?.decision === 'approve' ? 'Approve Request' : 'Decline Request'}
        variant={rescheduleReviewConfirmation?.decision === 'approve' ? 'default' : 'danger'}
        loading={processingRequestId !== null}
        loadingText="Reviewing…"
        onConfirm={() => resolveReschedule(rescheduleReviewConfirmation.id, rescheduleReviewConfirmation.decision)}
        onCancel={() => setRescheduleReviewConfirmation(null)}
      />
    </div>
  )
}
