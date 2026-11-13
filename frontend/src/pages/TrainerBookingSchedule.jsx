import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaArrowLeft, FaCalendarAlt, FaFilter, FaSyncAlt } from 'react-icons/fa'
import api from '../services/api'
import TrainerBookingCard from '../components/trainer/TrainerBookingCard.jsx'
import { useTrainerPortal } from '../components/trainer/TrainerPortalContext.js'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import ReasonDialog from '../components/ui/ReasonDialog.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'
import { getManilaDateString } from '../utils/manilaDate'

const FILTERS = [
  { value: 'all', label: 'All bookings' },
  { value: 'pending', label: 'Pending' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'completed', label: 'Completed' },
  { value: 'history', label: 'History' },
]

const STATUS_PRIORITY = {
  pending: 1,
  awaiting_member: 2,
  awaiting_payment: 3,
  confirmed: 4,
  completed: 5,
  rejected: 6,
  cancelled: 7,
  expired: 8,
  no_show: 9,
}

function compareBookings(first, second) {
  const firstStatus = String(first.status || '').toLowerCase()
  const secondStatus = String(second.status || '').toLowerCase()
  const firstPriority = STATUS_PRIORITY[firstStatus] || 99
  const secondPriority = STATUS_PRIORITY[secondStatus] || 99

  if (firstPriority !== secondPriority) {
    return firstPriority - secondPriority
  }

  // Within pending requests, sort the oldest unanswered request first
  if (firstStatus === 'pending') {
    const firstCreated = first.created_at ? new Date(first.created_at).getTime() : 0
    const secondCreated = second.created_at ? new Date(second.created_at).getTime() : 0
    if (firstCreated !== secondCreated) {
      return firstCreated - secondCreated
    }
  }

  // Within confirmed bookings, show the nearest upcoming session first
  if (firstStatus === 'confirmed') {
    const firstSession = `${String(first.booking_date || '').slice(0, 10)}T${String(first.start_time || '').slice(0, 5)}`
    const secondSession = `${String(second.booking_date || '').slice(0, 10)}T${String(second.start_time || '').slice(0, 5)}`
    const sessionCompare = firstSession.localeCompare(secondSession)
    if (sessionCompare !== 0) {
      return sessionCompare
    }
  }

  // Default ordering: nearest session date/time, then id
  const firstDate = `${String(first.booking_date || '').slice(0, 10)}T${String(first.start_time || '').slice(0, 5)}`
  const secondDate = `${String(second.booking_date || '').slice(0, 10)}T${String(second.start_time || '').slice(0, 5)}`
  const dateDiff = firstDate.localeCompare(secondDate)
  if (dateDiff !== 0) return dateDiff

  return Number(first.id || 0) - Number(second.id || 0)
}

export default function TrainerBookingSchedule() {
  const { trainer, loading: profileLoading } = useTrainerPortal()
  const { showSuccess, showRequestError } = useFeedback()
  const [bookings, setBookings] = useState([])
  const [loadingBookings, setLoadingBookings] = useState(true)
  const [activeFilter, setActiveFilter] = useState('all')
  const [processingBookingId, setProcessingBookingId] = useState(null)
  const [statusConfirmation, setStatusConfirmation] = useState(null)
  const [rejectBooking, setRejectBooking] = useState(null)
  const [proposalBooking, setProposalBooking] = useState(null)
  const [proposalDate, setProposalDate] = useState('')
  const [proposalStart, setProposalStart] = useState('')
  const [proposalNote, setProposalNote] = useState('')
  const [proposalError, setProposalError] = useState('')
  const [proposalSubmitting, setProposalSubmitting] = useState(false)
  const actionRef = useRef(false)
  const proposalRef = useRef(false)
  const bookingsRequestRef = useRef(null)
  const trainerId = trainer?.trainer_id

  const fetchBookings = useCallback(async (showLoading = false, isActive = () => true) => {
    if (!isActive()) return
    if (profileLoading) return
    if (!trainerId) {
      if (isActive()) setLoadingBookings(false)
      return
    }
    if (showLoading && isActive()) setLoadingBookings(true)
    let request = bookingsRequestRef.current
    if (!request) {
      request = api.get('/bookings.php', { params: { role: 'trainer' } })
      bookingsRequestRef.current = request
    }
    try {
      const response = await request
      if (!response.data?.success) throw new Error('Unable to load assigned bookings.')
      if (isActive()) setBookings(response.data.bookings || [])
    } catch (requestError) {
      if (isActive()) showRequestError(requestError, 'Unable to load assigned bookings. Try refreshing the list.', 'Bookings could not be loaded')
    } finally {
      if (bookingsRequestRef.current === request) bookingsRequestRef.current = null
      if (isActive()) setLoadingBookings(false)
    }
  }, [profileLoading, showRequestError, trainerId])

  useEffect(() => {
    let active = true
    if (profileLoading) return () => { active = false }
    if (!trainerId) {
      void Promise.resolve().then(() => {
        if (active) setLoadingBookings(false)
      })
      return () => { active = false }
    }
    void Promise.resolve().then(() => fetchBookings(false, () => active))
    return () => { active = false }
  }, [fetchBookings, profileLoading, trainerId])

  const runStatusChange = async (id, status, reason = '') => {
    if (actionRef.current) return false
    actionRef.current = true
    setProcessingBookingId(id)
    try {
      const action = status === 'cancelled' ? 'cancel' : 'status'
      const response = await api.post(`/bookings.php?action=${action}`, { id, status, reason })
      if (!response.data?.success) throw new Error('Booking status update was not confirmed.')
      showSuccess(response.data.message || 'Booking status updated.')
      await fetchBookings(true)
      return true
    } catch (requestError) {
      showRequestError(requestError, 'Unable to update this booking. Refresh the list and try again.', 'Booking update failed')
      return false
    } finally {
      actionRef.current = false
      setProcessingBookingId(null)
    }
  }

  const confirmStatusChange = async () => {
    if (!statusConfirmation) return
    if (statusConfirmation.status === 'no_show') {
      if (actionRef.current) return
      actionRef.current = true
      setProcessingBookingId(statusConfirmation.id)
      try {
        const response = await api.post('/bookings.php?action=mark-no-show', { id: statusConfirmation.id })
        if (!response.data?.success) throw new Error(response.data?.message || 'No-show action was not confirmed.')
        showSuccess(response.data.message || 'Booking marked as no-show.')
        setStatusConfirmation(null)
        await fetchBookings(true)
      } catch (requestError) {
        showRequestError(requestError, 'Unable to mark this booking as no-show. Refresh and try again.', 'No-show update failed')
      } finally {
        actionRef.current = false
        setProcessingBookingId(null)
      }
      return
    }
    if (await runStatusChange(statusConfirmation.id, statusConfirmation.status)) setStatusConfirmation(null)
  }

  const rejectBookingRequest = async (reason) => {
    if (!rejectBooking) return
    if (await runStatusChange(rejectBooking.id, 'rejected', reason)) setRejectBooking(null)
  }

  const openProposal = (booking) => {
    setProposalBooking(booking)
    setProposalDate('')
    setProposalStart('')
    setProposalNote('')
    setProposalError('')
  }

  const submitProposal = async (event) => {
    event.preventDefault()
    if (!proposalBooking || !proposalDate || !proposalStart) {
      setProposalError('Choose an alternative date and time.')
      return
    }
    const proposedAt = new Date(`${proposalDate}T${proposalStart}:00+08:00`)
    if (Number.isNaN(proposedAt.getTime()) || proposedAt.getTime() <= Date.now()) {
      setProposalError('Choose a future date and time in Asia/Manila.')
      return
    }
    if (proposalRef.current) return
    proposalRef.current = true
    setProposalSubmitting(true)
    setProposalError('')
    try {
      const response = await api.post('/bookings.php?action=trainer-propose-alternative', {
        id: proposalBooking.id,
        proposed_date: proposalDate,
        proposed_start_time: proposalStart,
        trainer_note: proposalNote,
      })
      if (!response.data?.success) throw new Error('The alternative proposal was not confirmed.')
      showSuccess(response.data.message || 'Alternative time sent to the member.')
      setProposalBooking(null)
      await fetchBookings(true)
    } catch (requestError) {
      showRequestError(requestError, 'Unable to send this proposal. Refresh the booking and try again.', 'Proposal failed')
    } finally {
      proposalRef.current = false
      setProposalSubmitting(false)
    }
  }

  const filteredBookings = useMemo(() => {
    const selected = bookings.filter((booking) => {
      if (activeFilter === 'history') return ['rejected', 'cancelled', 'expired', 'no_show'].includes(String(booking.status).toLowerCase())
      return activeFilter === 'all' || String(booking.status).toLowerCase() === activeFilter
    })
    return [...selected].sort(compareBookings)
  }, [activeFilter, bookings])

  const counts = useMemo(() => Object.fromEntries(
    FILTERS.map(({ value }) => [value, value === 'all'
      ? bookings.length
      : value === 'history'
        ? bookings.filter((booking) => ['rejected', 'cancelled', 'expired', 'no_show'].includes(String(booking.status).toLowerCase())).length
        : bookings.filter((booking) => String(booking.status).toLowerCase() === value).length])
  ), [bookings])

  if (profileLoading) {
    return <div className="p-6 text-center text-sm text-slate-500">Loading your schedule…</div>
  }
  if (!trainer) return <div role="alert" className="p-6 text-center text-sm text-rose-700">Trainer profile is unavailable.</div>

  return (
    <div className="min-h-screen overflow-x-hidden pb-24 sm:pb-8">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            <Link to="/trainer" aria-label="Back to dashboard" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50"><FaArrowLeft /></Link>
            <div className="min-w-0"><p className="text-xs font-medium text-cyan-800">Trainer workspace</p><h1 className="truncate text-lg font-bold text-slate-900 sm:text-xl">Schedule & bookings</h1></div>
          </div>
          <button type="button" onClick={() => fetchBookings(true)} disabled={loadingBookings} aria-label="Refresh bookings" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-50"><FaSyncAlt className={loadingBookings ? 'animate-spin' : ''} /></button>
        </div>
      </header>

      <main className="mx-auto max-w-6xl space-y-4 px-4 py-5 sm:px-6">
        <section className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
          <div className="flex items-start gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-50 text-cyan-800"><FaCalendarAlt aria-hidden="true" /></span>
            <div><h2 className="font-semibold text-slate-900">Your assigned bookings</h2><p className="mt-1 text-sm leading-5 text-slate-600">Requests and sessions assigned to you. Updates are limited to the actions allowed for each status.</p></div>
          </div>
          <div className="mt-4 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Filter bookings by status">
            {FILTERS.map((filter) => (
              <button key={filter.value} type="button" role="tab" aria-selected={activeFilter === filter.value} onClick={() => setActiveFilter(filter.value)} className={`min-h-10 shrink-0 rounded-xl px-3 text-xs font-semibold sm:text-sm ${activeFilter === filter.value ? 'bg-cyan-800 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'}`}>
                {filter.label}<span className="ml-1.5 opacity-75">{counts[filter.value]}</span>
              </button>
            ))}
          </div>
        </section>

        <section aria-live="polite">
          {loadingBookings ? (
            <p role="status" className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Loading assigned bookings…</p>
          ) : filteredBookings.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center">
              <FaFilter aria-hidden="true" className="mx-auto text-xl text-slate-400" />
              <p className="mt-2 text-sm font-semibold text-slate-700">{activeFilter === 'all' ? 'No assigned bookings yet' : `No ${FILTERS.find((filter) => filter.value === activeFilter)?.label.toLowerCase()} bookings`}</p>
              <p className="mt-1 text-sm text-slate-500">Bookings assigned to your trainer account will appear here.</p>
            </div>
          ) : (
            <div className="space-y-2.5">
              {filteredBookings.map((booking) => (
                <TrainerBookingCard
                  key={booking.id}
                  booking={booking}
                  processing={processingBookingId !== null}
                  onAccept={(item) => setStatusConfirmation({ id: item.id, status: 'confirmed' })}
                  onReject={setRejectBooking}
                  onPropose={openProposal}
                  onCancel={(item) => setStatusConfirmation({ id: item.id, status: 'cancelled' })}
                  onComplete={(item) => setStatusConfirmation({ id: item.id, status: 'completed' })}
                  onNoShow={(item) => setStatusConfirmation({ id: item.id, status: 'no_show' })}
                />
              ))}
            </div>
          )}
        </section>
      </main>

      {proposalBooking && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4">
          <form onSubmit={submitProposal} className="my-auto w-full max-w-md space-y-4 rounded-2xl bg-white p-5 sm:p-6">
            <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{proposalBooking.booking_code}</p><h2 className="mt-1 text-lg font-bold text-slate-900">Propose an alternative</h2><p className="mt-1 text-sm leading-5 text-slate-600">The member’s requested time remains unchanged until they accept.</p></div>
            <label className="block text-sm font-medium text-slate-700">Alternative date<input type="date" min={getManilaDateString()} value={proposalDate} onChange={(event) => setProposalDate(event.target.value)} required className="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2.5" /></label>
            <label className="block text-sm font-medium text-slate-700">Alternative start time<input type="time" value={proposalStart} onChange={(event) => setProposalStart(event.target.value)} required className="mt-1.5 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2.5" /></label>
            <label className="block text-sm font-medium text-slate-700">Optional note<textarea value={proposalNote} onChange={(event) => setProposalNote(event.target.value)} maxLength={1000} rows={3} className="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5" /></label>
            {proposalError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{proposalError}</p>}
            <div className="grid grid-cols-2 gap-2"><button type="button" onClick={() => setProposalBooking(null)} disabled={proposalSubmitting} className="min-h-11 rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-700 disabled:opacity-50">Cancel</button><button type="submit" disabled={proposalSubmitting} className="min-h-11 rounded-xl bg-cyan-800 px-3 text-sm font-semibold text-white disabled:opacity-50">{proposalSubmitting ? 'Sending…' : 'Send proposal'}</button></div>
          </form>
        </div>
      )}

      <ReasonDialog open={Boolean(rejectBooking)} title="Reject booking request" description="The member will be notified that the request was declined. You may include an optional reason." label="Rejection reason" placeholder="Optional reason for declining this request" confirmText="Reject Request" onConfirm={rejectBookingRequest} onCancel={() => setRejectBooking(null)} loading={processingBookingId !== null} />
      <ConfirmationModal
        open={Boolean(statusConfirmation)}
        title={statusConfirmation?.status === 'confirmed' ? 'Accept booking request?' : statusConfirmation?.status === 'cancelled' ? 'Cancel this booking?' : statusConfirmation?.status === 'no_show' ? 'Mark this member as no-show?' : 'Mark session completed?'}
        message={statusConfirmation?.status === 'confirmed' ? 'This confirms the requested session time for the member.' : statusConfirmation?.status === 'cancelled' ? 'The booking will be cancelled through the standard refund and credit rules.' : statusConfirmation?.status === 'no_show' ? 'Only use this after the scheduled session has ended. No refund or consumed-credit restoration will be issued.' : 'Confirm that this scheduled session has ended before marking it complete.'}
        confirmText={statusConfirmation?.status === 'confirmed' ? 'Accept Booking' : statusConfirmation?.status === 'cancelled' ? 'Cancel Booking' : statusConfirmation?.status === 'no_show' ? 'Mark No-show' : 'Mark Completed'}
        variant={['cancelled', 'no_show'].includes(statusConfirmation?.status) ? 'danger' : 'default'}
        loading={processingBookingId !== null}
        loadingText="Updating…"
        onConfirm={confirmStatusChange}
        onCancel={() => setStatusConfirmation(null)}
      />
    </div>
  )
}
