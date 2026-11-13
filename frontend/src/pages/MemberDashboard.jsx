import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import {
  FaCalendarCheck,
  FaCalendarPlus,
  FaClock,
  FaBell,
  FaCheck,
} from 'react-icons/fa'
import api from '../services/api'
import MemberQRCode from '../components/MemberQRCode.jsx'
import MemberMembershipUpgrade from '../components/MemberMembershipUpgrade.jsx'
import MemberDailyPassPurchase from '../components/MemberDailyPassPurchase.jsx'
import MemberFitness from '../components/MemberFitness.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import { formatFullName } from '../utils/nameFormatter'
import { getStoredUser } from '../utils/authState'
import { logout } from '../utils/auth'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'

// ─── Helpers ────────────────────────────────────────────────────────────────

function formatDate(dateStr) {
  if (!dateStr) return ''
  try {
    const d = new Date(`${String(dateStr).slice(0, 10)}T00:00:00+08:00`)
    return d.toLocaleDateString('en-PH', {
      timeZone: 'Asia/Manila',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  } catch {
    return dateStr
  }
}

function formatNotificationDate(dateTime) {
  if (!dateTime) return ''
  try {
    const value = String(dateTime)
    const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}+08:00`
    return new Date(normalized).toLocaleString('en-PH', {
      timeZone: 'Asia/Manila',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    })
  } catch {
    return dateTime
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

function formatBookingAmount(value, currency = 'PHP') {
  if (value === null || value === undefined || value === '') return 'Price unavailable'
  return Number(value).toLocaleString('en-PH', { style: 'currency', currency })
}

function bookingStartTimestamp(booking) {
  const date = String(booking?.booking_date || '').slice(0, 10)
  const time = String(booking?.start_time || '').slice(0, 5)
  if (!date || !time) return 0
  const timestamp = new Date(`${date}T${time}:00+08:00`).getTime()
  return Number.isNaN(timestamp) ? 0 : timestamp
}

function canMemberCancelBooking(booking, now) {
  const remainingMs = bookingStartTimestamp(booking) - now
  if (remainingMs <= 0) return false
  return booking.status !== 'awaiting_payment' || remainingMs >= 24 * 60 * 60 * 1000
}

function getGymDate(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function addDaysToDate(date, days) {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

function getGymTime(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date)
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.hour}:${values.minute}`
}

function normalizeMembershipType(value) {
  if (!value) return ''
  const k = value.toString().toLowerCase().replace(/[^a-z0-9]+/g, '')
  if (k === 'monthly' || k === 'monthlysubscription') return 'Monthly Subscription'
  if (k === 'dailypass' || k === 'daypass') return '1 Day Pass'
  if (k === 'walkin' || k === 'walkinmembership') return '1 Day Pass'
  return value
}

function normalizeStatusBadge(status) {
  const s = (status || '').toString().toLowerCase()
  if (s === 'active') return { label: 'Active', color: 'bg-emerald-100 text-emerald-700' }
  if (s === 'expired') return { label: 'Expired', color: 'bg-rose-100 text-rose-700' }
  return { label: status || 'Inactive', color: 'bg-slate-100 text-slate-700' }
}

// ─── Component ───────────────────────────────────────────────────────────────

export default function MemberDashboard() {
  const navigate = useNavigate()
  const { showSuccess, showRequestError } = useFeedback()
  const { pathname } = useLocation()
  const currentPage = pathname.split('/').pop()

  // Core data state
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [profileAttempt, setProfileAttempt] = useState(0)
  const [member, setMember] = useState(null)
  const [upgradeOpen, setUpgradeOpen] = useState(false)
  const [dailyPassPurchaseOpen, setDailyPassPurchaseOpen] = useState(false)
  const [upgradeNotice, setUpgradeNotice] = useState('')
  const [gymStats, setGymStats] = useState({ checked_in: 0, total_today: 0, total_members: 0 })
  const [statsLoading, setStatsLoading] = useState(true)
  const [statsError, setStatsError] = useState('')

  // UI state
  const [qrOpen, setQrOpen] = useState(false)
  const [notifications, setNotifications] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [notificationsLoading, setNotificationsLoading] = useState(true)
  const [notificationsError, setNotificationsError] = useState('')
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [markingNotificationId, setMarkingNotificationId] = useState(null)

  // Bookings state
  const [myBookings, setMyBookings] = useState([])
  const [bookingsError, setBookingsError] = useState('')
  const [attendanceSummary, setAttendanceSummary] = useState(null)
  const [attendanceSummaryLoading, setAttendanceSummaryLoading] = useState(true)
  const [bookingsLoading, setBookingsLoading] = useState(false)
  const [bookingFilter, setBookingFilter] = useState('all')
  const [bookingModalOpen, setBookingModalOpen] = useState(false)
  const [trainersList, setTrainersList] = useState([])
  const [sessionTypes, setSessionTypes] = useState([])
  const [bookingPriceVersions, setBookingPriceVersions] = useState([])
  const [creditSummary, setCreditSummary] = useState(null)
  const [bookingPaymentFiles, setBookingPaymentFiles] = useState({})
  const [maxAdvanceDays, setMaxAdvanceDays] = useState(90)
  const [availableSlots, setAvailableSlots] = useState([])
  const [loadingSlots, setLoadingSlots] = useState(false)
  const [availabilityMessage, setAvailabilityMessage] = useState('')
  const [rescheduleBooking, setRescheduleBooking] = useState(null)
  const [rescheduleDate, setRescheduleDate] = useState('')
  const [rescheduleStart, setRescheduleStart] = useState('')
  const [rescheduleError, setRescheduleError] = useState('')
  const [bookingForm, setBookingForm] = useState({
    trainer_id: '',
    booking_date: getGymDate(),
    start_time: '',
    end_time: '',
    session_type_ids: [],
    notes: '',
  })
  const [bookingFormError, setBookingFormError] = useState('')
  const [isSubmittingBooking, setIsSubmittingBooking] = useState(false)
  const [bookingConfirmation, setBookingConfirmation] = useState(null)
  const [bookingActionLoading, setBookingActionLoading] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)

  // ─── Data fetching ──────────────────────────────────────────────────────

  useEffect(() => {
    const updateTime = () => setCurrentTime(Date.now())
    updateTime()
    const interval = window.setInterval(updateTime, 60_000)
    return () => window.clearInterval(interval)
  }, [])

  const fetchMyBookings = async (email) => {
    setBookingsLoading(true)
    setBookingsError('')
    try {
      const res = await api.get('/bookings.php', { params: { role: 'member', email } })
      if (res?.data?.success) {
        setMyBookings(res.data.bookings || [])
      } else {
        setBookingsError(res?.data?.message || 'Unable to load your bookings.')
      }
    } catch (requestError) {
      setBookingsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load your bookings.')
    } finally {
      setBookingsLoading(false)
    }
  }

  useEffect(() => {
    const u = getStoredUser()
    const role = (u?.role || '').toString().toLowerCase().trim()
    const email = (u?.email || '').toString().trim()
    if (!u || role !== 'member') {
      navigate('/login', { replace: true })
      return
    }

    let active = true
    let hasLoadedNotifications = false

    const fetchProfile = async () => {
      setLoading(true)
      setError('')
      try {
        const res = await api.post('/member_profile.php', email ? { email } : {})
        if (!active) return
        if (res?.data?.success && res.data.member) {
          setMember(res.data.member)
          fetchMyBookings(email)
          const today = getGymDate()
          api.get('/member_attendance_history.php', {
            params: { month: Number(today.slice(5, 7)), year: Number(today.slice(0, 4)) },
          }).then((attendanceResponse) => {
            if (attendanceResponse?.data?.success) {
              setAttendanceSummary(attendanceResponse.data.summary || null)
            }
          }).catch((attendanceError) => {
            if (active) setAttendanceSummary({ error: attendanceError?.response?.data?.message || 'Unable to load attendance summary.' })
          }).finally(() => {
            if (active) setAttendanceSummaryLoading(false)
          })
        } else {
          setError(res?.data?.message || 'Unable to load your member information.')
        }
      } catch (err) {
        if (active) setError(err?.response?.data?.message || 'Unable to load your member information.')
      } finally {
        if (active) setLoading(false)
      }
    }

    const fetchNotifications = async () => {
      if (!hasLoadedNotifications) setNotificationsLoading(true)
      try {
        const res = await api.get('/notifications.php', { params: { action: 'list' } })
        if (!res?.data?.success) {
          throw new Error(res?.data?.message || 'Unable to load notifications.')
        }
        if (!active) return
        setNotifications(res.data.notifications || [])
        setUnreadCount(Number(res.data.unread_count || 0))
        setNotificationsError('')
        hasLoadedNotifications = true
      } catch (err) {
        if (active) {
          setNotificationsError(err?.response?.data?.message || err?.message || 'Unable to load notifications.')
        }
      } finally {
        if (active) setNotificationsLoading(false)
      }
    }

    const fetchGymStats = async () => {
      try {
        const res = await api.get('/stats.php')
        if (res?.data?.success) {
          setGymStats({
            checked_in: Number(res.data.summary?.checked_in || 0),
            total_today: Number(res.data.summary?.total_today || 0),
            total_members: Number(res.data.total_members || 0),
          })
          setStatsError('')
        } else {
          setStatsError(res?.data?.message || 'Unable to load live gym occupancy.')
        }
      } catch (err) {
        setStatsError(err?.response?.data?.message || 'Unable to load live gym occupancy.')
      } finally {
        setStatsLoading(false)
      }
    }

    fetchProfile()
    fetchGymStats()
    fetchNotifications()

    const interval = setInterval(fetchGymStats, 30000)
    const notificationInterval = setInterval(fetchNotifications, 60000)
    window.addEventListener('focus', fetchNotifications)
    return () => {
      active = false
      clearInterval(interval)
      clearInterval(notificationInterval)
      window.removeEventListener('focus', fetchNotifications)
    }
  }, [navigate, profileAttempt])

  useEffect(() => {
    if (!bookingModalOpen || !bookingForm.session_type_ids.length || !bookingForm.booking_date) {
      return undefined
    }
    let active = true
    api.get('/bookings.php', {
      params: {
        action: 'credit-summary',
        booking_date: bookingForm.booking_date,
        session_type_ids: bookingForm.session_type_ids.join(','),
      },
    }).then((response) => {
      if (active && response.data?.success) setCreditSummary(response.data)
    }).catch(() => {
      if (active) setCreditSummary(null)
    })
    return () => { active = false }
  }, [bookingModalOpen, bookingForm.booking_date, bookingForm.session_type_ids])

  const retryProfile = () => {
    setError('')
    setLoading(true)
    setProfileAttempt((attempt) => attempt + 1)
  }

  // ─── Booking handlers ───────────────────────────────────────────────────

  const loadAvailableSlots = async (trainerId, dateStr, sessionType, excludeBookingId = 0) => {
    setAvailableSlots([])
    setAvailabilityMessage('')
    if (!trainerId || !dateStr || !sessionType) return
    setLoadingSlots(true)
    try {
      const res = await api.get('/bookings.php', {
        params: { action: 'available_slots', trainer_id: trainerId, date: dateStr, session_type: sessionType, ...(excludeBookingId ? { exclude_booking_id: excludeBookingId } : {}) }
      })
      if (res?.data?.success) {
        const slots = res.data.available_slots || []
        setAvailableSlots(slots)
        if (slots.length === 0) setAvailabilityMessage('No bookable time slots are available for this trainer, date, and session duration.')
      } else {
        setAvailabilityMessage(res?.data?.message || 'Unable to load available time slots.')
      }
    } catch (requestError) {
      setAvailabilityMessage(requestError?.response?.data?.message || 'Unable to load available time slots.')
    } finally {
      setLoadingSlots(false)
    }
  }

  const openBookingModal = async () => {
    setBookingFormError('')
    setBookingForm({
      trainer_id: '',
      booking_date: getGymDate(),
      start_time: '',
      end_time: '',
      session_type_ids: [],
      notes: '',
    })
    setAvailableSlots([])
    setAvailabilityMessage('')
    setBookingModalOpen(true)

    try {
      const [trainerResponse, settingsResponse] = await Promise.all([
        api.get('/trainers.php', { params: { action: 'options' } }),
        api.get('/booking_settings.php'),
      ])
      if (!trainerResponse.data?.success) throw new Error(trainerResponse.data?.message || 'Unable to load trainers.')
      if (!settingsResponse.data?.success) throw new Error(settingsResponse.data?.message || 'Unable to load session types.')
      setTrainersList((trainerResponse.data.trainers || []).filter((trainer) => (trainer.status || '').toLowerCase() === 'active'))
      const activeTypes = (settingsResponse.data.session_types || []).filter((session) => Number(session.is_active) === 1)
      setSessionTypes(activeTypes)
      setBookingPriceVersions(settingsResponse.data.price_versions || [])
      setMaxAdvanceDays(Number(settingsResponse.data.max_advance_days) || 90)
      if (activeTypes.length === 0) setBookingFormError('Booking is not configured yet. Contact the gym administrator.')
    } catch (requestError) {
      setBookingFormError(requestError?.response?.data?.message || requestError?.message || 'Unable to load booking options.')
    }
  }

  const submitBookingPayment = async (booking, method) => {
    if (bookingActionLoading) return
    setBookingActionLoading(true)
    try {
      const idempotencyKey = window.crypto?.randomUUID?.()
      if (!idempotencyKey) throw new Error('Secure payment retry keys are unavailable in this browser.')
      let payload
      if (method === 'gcash') {
        const proof = bookingPaymentFiles[booking.id]
        if (!proof) throw new Error('Choose a GCash payment proof file.')
        payload = new FormData()
        payload.append('action', 'submit_booking_gcash')
        payload.append('booking_id', String(booking.id))
        payload.append('idempotency_key', idempotencyKey)
        payload.append('payment_proof', proof)
      } else {
        payload = {
          action: 'submit_booking_cash',
          booking_id: Number(booking.id),
          idempotency_key: idempotencyKey,
        }
      }
      const response = await api.post('/payments.php', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to submit booking payment.')
      showSuccess(response.data.message || 'Booking payment request submitted.')
      setBookingPaymentFiles((current) => ({ ...current, [booking.id]: null }))
      await fetchMyBookings(member.email)
    } catch (requestError) {
      showRequestError(requestError, requestError?.message || 'Unable to submit booking payment.', 'Payment request failed')
    } finally {
      setBookingActionLoading(false)
    }
  }

  const handleCreateBookingSubmit = async (e) => {
    e.preventDefault()
    setBookingFormError('')
    if (!bookingForm.trainer_id || bookingForm.session_type_ids.length === 0 || !bookingForm.booking_date || !bookingForm.start_time) {
      setBookingFormError('Select at least one session type, a trainer, preferred date, and start time.')
      return
    }
    const combinedDuration = sessionTypes
      .filter((session) => bookingForm.session_type_ids.includes(Number(session.id)))
      .reduce((total, session) => total + Number(session.duration_minutes), 0)
    if (combinedDuration > 480) {
      setBookingFormError('The combined session duration cannot exceed 480 minutes.')
      return
    }
    const today = getGymDate()
    const maxDate = addDaysToDate(today, maxAdvanceDays)
    if (bookingForm.booking_date < today || (bookingForm.booking_date === today && bookingForm.start_time <= getGymTime())) {
      setBookingFormError('Choose a future booking date and time.')
      return
    }
    if (bookingForm.booking_date > maxDate) {
      setBookingFormError(`Choose a date within the next ${maxAdvanceDays} days.`)
      return
    }
    setIsSubmittingBooking(true)

    try {
      const res = await api.post('/bookings.php?action=create', {
        ...bookingForm,
        trainer_id: Number(bookingForm.trainer_id),
        session_type_ids: bookingForm.session_type_ids.map(Number),
      })

      if (res?.data?.success) {
        setBookingModalOpen(false)
        fetchMyBookings(member.email)
      } else {
        setBookingFormError(res?.data?.message || 'Failed to submit booking request.')
      }
    } catch (err) {
      setBookingFormError(err?.response?.data?.message || 'Failed to submit booking request.')
    } finally {
      setIsSubmittingBooking(false)
    }
  }

  const respondToAlternative = async (proposalId, decision) => {
    if (bookingActionLoading) return
    setBookingActionLoading(true)
    try {
      const response = await api.post('/bookings.php?action=member-alternative-response', {
        proposal_id: proposalId,
        decision,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to respond to the alternative.')
      showSuccess(response.data.message || (decision === 'accept' ? 'Alternative time accepted.' : 'Alternative time declined.'))
      setBookingConfirmation(null)
      await fetchMyBookings(member.email)
    } catch (requestError) {
      showRequestError(requestError, 'Unable to respond to the alternative. Refresh your bookings and try again.', 'Booking response failed')
    } finally {
      setBookingActionLoading(false)
    }
  }

  const handleCancelBooking = async (bookingId, reason = '') => {
    if (bookingActionLoading) return
    setBookingActionLoading(true)
    try {
      const res = await api.post('/bookings.php?action=cancel', { id: bookingId, reason })
      if (!res?.data?.success) throw new Error('Booking cancellation was not confirmed.')
      showSuccess(res.data.message || 'Booking cancelled.')
      setBookingConfirmation(null)
      await fetchMyBookings(member.email)
    } catch (requestError) {
      showRequestError(requestError, 'Unable to cancel this booking. Refresh your bookings and try again.', 'Cancellation failed')
    } finally {
      setBookingActionLoading(false)
    }
  }

  const confirmBookingAction = () => {
    if (!bookingConfirmation || bookingActionLoading) return
    if (bookingConfirmation.type === 'cancel') {
      void handleCancelBooking(bookingConfirmation.bookingId, bookingConfirmation.reason || '')
      return
    }
    void respondToAlternative(bookingConfirmation.proposalId, bookingConfirmation.decision)
  }

  const openRescheduleRequest = (booking) => {
    setRescheduleBooking(booking)
    setRescheduleDate('')
    setRescheduleStart('')
    setRescheduleError('')
    setAvailableSlots([])
    setAvailabilityMessage('')
  }

  const submitRescheduleRequest = async (event) => {
    event.preventDefault()
    if (!rescheduleBooking || !rescheduleDate || !rescheduleStart) {
      setRescheduleError('Choose a new date and an available time.')
      return
    }
    const slot = availableSlots.find((item) => item.start_time === rescheduleStart)
    if (!slot) {
      setRescheduleError('Select one of the currently available time slots.')
      return
    }
    setRescheduleError('')
    try {
      const response = await api.post('/bookings.php?action=request-reschedule', {
        id: rescheduleBooking.id,
        booking_date: rescheduleDate,
        start_time: slot.start_time,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to request reschedule.')
      setRescheduleBooking(null)
      fetchMyBookings(member.email)
    } catch (requestError) {
      setRescheduleError(requestError?.response?.data?.message || requestError?.message || 'Unable to request reschedule.')
    }
  }

  const handleMarkNotificationRead = async (notificationId) => {
    setMarkingNotificationId(notificationId)
    setNotificationsError('')
    try {
      const res = await api.post('/notifications.php?action=mark-read', {
        notification_id: notificationId,
      })
      if (!res?.data?.success) {
        throw new Error(res?.data?.message || 'Unable to mark the notification as read.')
      }
      setNotifications((current) => current.map((notification) => (
        notification.id === notificationId ? { ...notification, is_read: true } : notification
      )))
      setUnreadCount((count) => Math.max(0, count - 1))
    } catch (err) {
      setNotificationsError(err?.response?.data?.message || err?.message || 'Unable to mark the notification as read.')
    } finally {
      setMarkingNotificationId(null)
    }
  }

  // ─── Loading / Error screens ────────────────────────────────────────────

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-100 text-slate-900">
        <div className="mx-auto max-w-3xl px-6 py-20 text-center">
          <p className="text-sm text-slate-500">Loading your dashboard...</p>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="min-h-screen bg-slate-100 text-slate-900">
        <div className="mx-auto max-w-3xl px-6 py-20 text-center">
          <p className="mb-6 text-lg font-semibold">Unable to load your member information.</p>
          <p className="mb-6 text-sm text-slate-600">{error}</p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            <button onClick={retryProfile} className="rounded-2xl bg-violet-700 px-5 py-3 text-white">Retry</button>
            <button onClick={() => logout(navigate)} className="rounded-2xl border border-slate-200 px-5 py-3">Logout</button>
            <button onClick={() => logout(navigate, '/')} className="rounded-2xl border border-slate-200 px-5 py-3">Return to public page</button>
          </div>
        </div>
      </div>
    )
  }

  // ─── Computed values ────────────────────────────────────────────────────

  const membershipTypeLabel = normalizeMembershipType(member.membership_type)
  const isGymAccessRestricted = String(member.gym_access_status || 'allowed').toLowerCase() === 'restricted'
  const isLegacyDayPass = ['walkin', 'walkinmembership'].includes(
    String(member.membership_type || '').toLowerCase().replace(/[^a-z0-9]+/g, '')
  )
  const isDailyPassMember = ['dailypass', 'daypass'].includes(
    String(member.membership_type || '').toLowerCase().replace(/[^a-z0-9]+/g, '')
  )
  const isPassAccount = isDailyPassMember || isLegacyDayPass
  const nextSubscriptionPeriod = (member.subscription_periods || [])
    .filter((period) => period.status === 'scheduled')
    .sort((left, right) => left.starts_on.localeCompare(right.starts_on))[0]
  const passStatus = member.latest_daily_pass?.entitlement_status || 'no pass on record'
  const statusBadge = isPassAccount
    ? passStatus === 'active'
      ? { label: 'Pass active', color: 'bg-emerald-100 text-emerald-700' }
      : passStatus === 'scheduled'
        ? { label: 'Pass scheduled', color: 'bg-amber-100 text-amber-700' }
      : { label: `Pass ${String(passStatus).replaceAll('_', ' ')}`, color: passStatus === 'no pass on record' ? 'bg-slate-100 text-slate-700' : 'bg-rose-100 text-rose-700' }
    : normalizeStatusBadge(member.status)
  const qrValue = isGymAccessRestricted || !isPassAccount
    ? ''
    : member.latest_daily_pass?.access_eligible ? member.latest_daily_pass.pass_code : ''
  const occupancyCount = gymStats.checked_in
  const capacityLimit = 30
  const gymCapacityStatus = occupancyCount >= capacityLimit ? 'Gym is full' : occupancyCount >= 21 ? 'Gym is crowded' : 'Gym has space'
  const gymCapacityColor = occupancyCount >= capacityLimit ? 'bg-rose-50 text-rose-700 border-rose-200' : occupancyCount >= 21 ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'
  const occupancyBarColor = occupancyCount >= capacityLimit ? 'bg-rose-500' : occupancyCount >= 21 ? 'bg-amber-500' : 'bg-emerald-500'
  const occupancyPercent = Math.min(100, Math.round((occupancyCount / capacityLimit) * 100))
  const currentCapacityLabel = `${occupancyCount} / ${capacityLimit}`
  const isOverview = currentPage === 'dashboard'
  const pageTitles = {
    membership: 'Membership',
    bookings: 'Bookings',
    'workout-plan': 'Workout Plan',
    progress: 'My Progress',
  }
  const upcomingBookings = myBookings.filter((booking) => (
    ['pending', 'confirmed', 'awaiting_member', 'awaiting_payment'].includes(String(booking.status).toLowerCase())
    && String(booking.booking_date).slice(0, 10) >= getGymDate()
  ))
  const visibleBookings = myBookings.filter((booking) => {
    const status = String(booking.status).toLowerCase()
    if (bookingFilter === 'upcoming') return upcomingBookings.includes(booking)
    if (bookingFilter === 'pending') return ['pending', 'awaiting_member', 'awaiting_payment'].includes(status)
    if (bookingFilter === 'completed') return status === 'completed'
    if (bookingFilter === 'cancelled') return ['cancelled', 'canceled', 'rejected'].includes(status)
    return true
  })
  const membershipExpiration = isPassAccount
    ? member.latest_daily_pass?.pass_end_date
    : member.membership_end

  // ─── Main render ────────────────────────────────────────────────────────

  return (
    <div className="w-full">
      <main className="min-w-0">

            <header className="mb-6 flex items-start justify-between gap-4">
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Member portal</p>
                <h1 className="mt-1 truncate text-2xl font-semibold text-slate-900">
                  {isOverview ? `Welcome back, ${formatFullName(member)}` : pageTitles[currentPage] || 'Member portal'}
                </h1>
                {isOverview && <p className="mt-1 text-sm text-slate-600">Your membership and activity at a glance.</p>}
              </div>
              <div className="relative shrink-0">
                <button
                  type="button"
                  onClick={() => setNotificationsOpen((open) => !open)}
                  aria-label={`Notifications, ${unreadCount} unread`}
                  aria-expanded={notificationsOpen}
                  className="relative flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                  <FaBell className="text-lg" />
                  {unreadCount > 0 && (
                    <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-4 text-white">
                      {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                  )}
                </button>
                {notificationsOpen && (
                  <section className="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                      <div>
                        <h2 className="text-sm font-semibold text-slate-900">Notifications</h2>
                        <p className="text-xs text-slate-500">{unreadCount} unread</p>
                      </div>
                      <button
                        type="button"
                        onClick={() => setNotificationsOpen(false)}
                        className="rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100"
                      >
                        Close
                      </button>
                    </div>
                    {notificationsError && (
                      <div className="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-700">
                        {notificationsError}
                      </div>
                    )}
                    <div className="max-h-[min(65vh,28rem)] overflow-y-auto">
                      {notificationsLoading ? (
                        <p className="px-4 py-8 text-center text-sm text-slate-500">Loading notifications...</p>
                      ) : notificationsError ? (
                        <p className="px-4 py-8 text-center text-sm text-rose-700">Notifications could not be loaded. Please try again later.</p>
                      ) : notifications.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-slate-500">You have no notifications.</p>
                      ) : (
                        <ul className="divide-y divide-slate-100">
                          {notifications.map((notification) => (
                            <li
                              key={notification.id}
                              className={`px-4 py-3 ${notification.is_read ? 'bg-white' : 'bg-violet-50/60'}`}
                            >
                              <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                  <p className="text-sm font-semibold text-slate-900">{notification.title}</p>
                                  <p className="mt-1 text-xs leading-relaxed text-slate-600">{notification.message}</p>
                                  <p className="mt-2 text-[11px] text-slate-400">{formatNotificationDate(notification.created_at)}</p>
                                  <p className={`mt-1 text-[11px] font-medium ${notification.is_read ? 'text-slate-400' : 'text-violet-700'}`}>
                                    {notification.is_read ? 'Read' : 'Unread'}
                                  </p>
                                </div>
                                {!notification.is_read && (
                                  <button
                                    type="button"
                                    disabled={markingNotificationId === notification.id}
                                    onClick={() => handleMarkNotificationRead(notification.id)}
                                    aria-label="Mark notification as read"
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-violet-200 text-violet-700 hover:bg-violet-100 disabled:cursor-wait disabled:opacity-50"
                                  >
                                    <FaCheck className="text-xs" />
                                  </button>
                                )}
                              </div>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </section>
                )}
              </div>
            </header>

            {isOverview && (
              <>
                <section aria-label="Member summary" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                  <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs text-slate-500">Membership status</p>
                    <div className="mt-2"><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadge.color}`}>{statusBadge.label}</span></div>
                  </article>
                  <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs text-slate-500">Visits this month</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">
                      {attendanceSummaryLoading ? '…' : attendanceSummary?.monthly_check_ins ?? '—'}
                    </p>
                    {attendanceSummary?.error && <p role="status" className="mt-1 text-xs text-rose-700">{attendanceSummary.error}</p>}
                  </article>
                  <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs text-slate-500">Upcoming bookings</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">{bookingsLoading ? '…' : upcomingBookings.length}</p>
                  </article>
                  <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs text-slate-500">Membership expiration</p>
                    <p className="mt-2 text-lg font-semibold text-slate-900">{membershipExpiration ? formatDate(membershipExpiration) : '—'}</p>
                  </article>
                </section>

                <section className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(260px,0.7fr)]">
                  <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Membership overview</p>
                        <h2 className="mt-2 text-lg font-semibold text-slate-900">{membershipTypeLabel || 'Membership'}</h2>
                        <p className="mt-1 text-sm text-slate-600">{statusBadge.label}</p>
                      </div>
                      <Link to="/member/membership" className="inline-flex min-h-10 items-center rounded-xl border border-cyan-200 px-3 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                        View membership
                      </Link>
                    </div>
                    <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                      <p><span className="text-slate-500">Started</span><br /><span className="font-medium">{formatDate(member.membership_start) || '—'}</span></p>
                      <p><span className="text-slate-500">Expires</span><br /><span className="font-medium">{formatDate(membershipExpiration) || '—'}</span></p>
                    </div>
                  </article>

                  <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-semibold text-slate-900">Quick actions</h2>
                    <div className="mt-3 grid gap-2">
                      <Link to="/member/attendance" className="flex min-h-11 items-center justify-center rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Scan Gym QR Code</Link>
                      <Link to="/member/bookings" className="flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Book a Session</Link>
                      <Link to="/member/payments" className="flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">View Payments</Link>
                      <Link to="/member/feedback" className="flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Submit Feedback</Link>
                    </div>
                  </article>
                </section>

                <section className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                  <div className="flex items-center justify-between gap-3">
                    <h2 className="font-semibold text-slate-900">Recent activity</h2>
                    <button type="button" onClick={() => setNotificationsOpen(true)} className="text-sm font-semibold text-cyan-800 hover:underline">View notifications</button>
                  </div>
                  {notificationsLoading ? (
                    <p className="mt-3 text-sm text-slate-500">Loading activity…</p>
                  ) : notificationsError ? (
                    <p role="alert" className="mt-3 text-sm text-rose-700">{notificationsError}</p>
                  ) : notifications.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-500">No recent activity.</p>
                  ) : (
                    <ul className="mt-3 divide-y divide-slate-100">
                      {notifications.slice(0, 3).map((notification) => (
                        <li key={notification.id} className="flex flex-wrap items-start justify-between gap-2 py-3">
                          <div>
                            <p className="text-sm font-medium text-slate-900">{notification.title}</p>
                            <p className="mt-1 text-sm text-slate-600">{notification.message}</p>
                          </div>
                          <time className="text-xs text-slate-500">{formatNotificationDate(notification.created_at)}</time>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              </>
            )}

            {/* ── ROW 1: 3-column cards ─────────────────────────────────── */}
            <div className={currentPage === 'membership' ? 'space-y-4' : 'hidden'}>

              {/* Card 1: Membership */}
              <div className="rounded-2xl bg-white p-5 shadow-sm">
                <p className="text-xs font-medium uppercase tracking-[0.24em] text-slate-500">
                  Membership
                </p>
                <div className="mt-3">
                  <p className="truncate text-base font-semibold text-slate-900">{formatFullName(member)}</p>
                  <p className="mt-0.5 text-sm text-slate-500 font-mono">{member.member_code}</p>
                  <p className="mt-2 text-sm font-medium text-slate-800">{membershipTypeLabel}</p>
                  <div className="mt-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${statusBadge.color}`}>
                      <span className="h-1.5 w-1.5 rounded-full bg-current inline-block" />
                      {statusBadge.label}
                    </span>
                  </div>
                  {isGymAccessRestricted && (
                    <div role="status" className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
                      <p className="font-semibold">Gym access is restricted</p>
                      <p className="mt-1">{member.gym_access_reason?.reason || 'Please contact gym staff for details.'}</p>
                    </div>
                  )}
                  {isPassAccount && <p className="mt-2 text-xs text-slate-500">Member account: <span className="font-semibold text-emerald-700">Active</span></p>}
                  {!isPassAccount && member.membership_start && (
                    <div className="mt-3 space-y-0.5 text-xs text-slate-500">
                      <div><span className="font-medium text-slate-700">Start:</span> {formatDate(member.membership_start)}</div>
                      <div><span className="font-medium text-slate-700">Expires:</span> {formatDate(member.membership_end)}</div>
                    </div>
                  )}
                  <div className="mt-3 space-y-2">
                    <button type="button" onClick={() => setUpgradeOpen(true)} className="w-full rounded-xl bg-violet-700 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-800">
                      Purchase or renew monthly subscription
                    </button>
                    <button type="button" onClick={() => setDailyPassPurchaseOpen(true)} className="w-full rounded-xl border border-violet-200 bg-white px-3 py-2 text-xs font-semibold text-violet-700 hover:bg-violet-50">
                      Purchase 1 Day Pass — ₱100
                    </button>
                    <Link to="/member/payments" className="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-center text-xs font-semibold text-slate-700 hover:bg-slate-50">
                      View payment history
                    </Link>
                  </div>
                  {!isPassAccount && (nextSubscriptionPeriod || member.pending_monthly_renewal) && (
                    <div className="mt-3 space-y-1 rounded-xl bg-violet-50 p-3 text-xs text-violet-900">
                      <p className="font-semibold">Upcoming monthly renewal</p>
                      {nextSubscriptionPeriod && (
                        <p>Scheduled period: {formatDate(nextSubscriptionPeriod.starts_on)} – {formatDate(nextSubscriptionPeriod.ends_on)}</p>
                      )}
                      {member.pending_monthly_renewal && (
                        <>
                          <p>Proposed period: {formatDate(member.pending_monthly_renewal.scheduled_start_date)} – {formatDate(member.pending_monthly_renewal.scheduled_end_date)}</p>
                          <p>₱{Number(member.pending_monthly_renewal.amount).toFixed(2)} · <span className="capitalize">{member.pending_monthly_renewal.payment_method}</span> · awaiting {member.pending_monthly_renewal.payment_method === 'cash' ? 'staff confirmation' : 'GCash verification'}</p>
                        </>
                      )}
                    </div>
                  )}
                  {isLegacyDayPass && (
                    <div className="mt-3 text-xs text-slate-500">
                      <p>This is a historical account label, not a current paid pass. A valid 1 Day Pass transaction is required for entry.</p>
                    </div>
                  )}
                  {upgradeNotice && <p role="status" className="mt-2 text-xs text-emerald-700">{upgradeNotice}</p>}
                  {member.latest_membership_request && (
                    <div className="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-xs text-slate-700">
                      <p className="font-semibold text-slate-900">Latest monthly request</p>
                      <p>
                        {String(member.latest_membership_request.member_category || 'regular').toUpperCase()}
                        {' · '}
                        {member.latest_membership_request.requested_duration_months || '—'} month(s)
                        {' · '}
                        ₱{Number(member.latest_membership_request.payment_amount || 0).toFixed(2)}
                      </p>
                      <p>Request: <span className="capitalize">{String(member.latest_membership_request.status).replaceAll('_', ' ')}</span></p>
                      <p>Promo eligibility: <span className="capitalize">{String(member.latest_membership_request.discount_status).replaceAll('_', ' ')}</span></p>
                      <p>Payment: <span className="capitalize">{String(member.latest_membership_request.payment_method || 'not selected')} · {String(member.latest_membership_request.payment_status).replaceAll('_', ' ')}</span></p>
                    </div>
                  )}
                  {!isPassAccount && member.subscription_periods?.length > 0 && (
                    <details className="mt-4 border-t border-slate-100 pt-3">
                      <summary className="cursor-pointer text-xs font-semibold text-violet-700">
                        Subscription history ({member.subscription_periods.length})
                      </summary>
                      <ul className="mt-2 max-h-40 space-y-2 overflow-y-auto">
                        {member.subscription_periods.map((period) => (
                          <li key={period.id} className="rounded-lg bg-slate-50 p-2 text-[11px] text-slate-600">
                            <p className="font-semibold text-slate-800">
                              {period.plan_name || `${period.duration_months} month${Number(period.duration_months) === 1 ? '' : 's'}`}
                            </p>
                            <p>{formatDate(period.starts_on)} – {formatDate(period.ends_on)}</p>
                            <p className="capitalize">{period.status} · {String(period.source).replaceAll('_', ' ')}</p>
                            {period.payment_amount && <p>Paid: ₱{Number(period.payment_amount).toFixed(2)}</p>}
                            {period.payment_method && <p className="capitalize">{period.payment_method} · {period.payment_status || 'paid'}</p>}
                            {period.discount_status && period.discount_status !== 'not_required' && <p className="capitalize">Discount verification: {String(period.discount_status).replaceAll('_', ' ')}</p>}
                            {period.verified_at && <p>Verified {formatNotificationDate(period.verified_at)}</p>}
                          </li>
                        ))}
                      </ul>
                    </details>
                  )}
                  {!isPassAccount && member.daily_passes?.length > 0 && (
                    <details className="mt-4 border-t border-slate-100 pt-3">
                      <summary className="cursor-pointer text-xs font-semibold text-violet-700">
                        Previous 1 Day Pass history ({member.daily_passes.length})
                      </summary>
                      <ul className="mt-2 max-h-40 space-y-2 overflow-y-auto">
                        {member.daily_passes.map((pass) => (
                          <li key={pass.id} className="rounded-lg bg-slate-50 p-2 text-[11px] text-slate-600">
                            {formatDate(pass.created_at)} · 1 Day Pass · ₱{Number(pass.amount).toFixed(2)} · {formatDate(pass.visit_date)} – {formatDate(pass.visit_date)} · <span className="capitalize">{pass.payment_method} · {pass.payment_status}</span>
                            {pass.verified_at && <span> · Verified {formatNotificationDate(pass.verified_at)}</span>}
                          </li>
                        ))}
                      </ul>
                    </details>
                  )}
                </div>
              </div>

            </div>
            {currentPage === 'membership' && isPassAccount && (
              <section className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="font-semibold text-slate-900">1 Day Pass access</h2>
                <div className="mt-4 flex flex-col items-center gap-3">
                  <div className="flex h-40 w-40 items-center justify-center rounded-xl border border-slate-200 bg-slate-50">
                    {qrValue ? <MemberQRCode qrValue={qrValue} size={140} /> : <p className="px-4 text-center text-xs text-slate-500">No active paid pass QR</p>}
                  </div>
                  <p className="text-center text-sm text-slate-600">
                    {isGymAccessRestricted ? 'Entry QR is unavailable while gym access is restricted.' : 'Pass QR is available only while your verified pass is valid today.'}
                  </p>
                  <button type="button" onClick={() => setQrOpen(true)} disabled={!qrValue} className="min-h-11 w-full max-w-xs rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                    View Pass QR Code
                  </button>
                </div>
              </section>
            )}

            <div className={isOverview ? 'mt-4 grid grid-cols-1 gap-4' : 'hidden'}>
              <div className="rounded-2xl bg-white p-5 shadow-sm">
                <p className="text-xs font-medium uppercase tracking-[0.24em] text-slate-500">
                  Gym Live Status
                </p>
                <div className="mt-3">
                  <div className="flex items-end justify-between gap-3">
                    <div>
                      <p className="text-3xl font-bold text-slate-900">{gymStats.checked_in}</p>
                      <p className="mt-0.5 text-xs text-slate-500">members checked in</p>
                    </div>
                    <span className={`inline-flex shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold ${gymCapacityColor}`}>
                      {gymCapacityStatus}
                    </span>
                  </div>

                  <div className="mt-3 flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                    <span className="text-slate-500 uppercase tracking-wider">Capacity</span>
                    <span className="font-semibold text-slate-900">{currentCapacityLabel}</span>
                  </div>

                  {/* Progress bar — min width 4px so it's always visible */}
                  <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                      className={`h-full rounded-full transition-all ${occupancyBarColor}`}
                      style={{ width: `max(${occupancyPercent}%, 4px)` }}
                    />
                  </div>

                  <div className="mt-2 flex items-center justify-between text-xs text-slate-400">
                    <span>{occupancyCount} in gym</span>
                    <span>{gymStats.total_today} total today</span>
                  </div>

                  {statsLoading && <p className="mt-2 text-xs text-slate-400">Updating...</p>}
                  {statsError && <p className="mt-2 text-xs text-rose-600">{statsError}</p>}
                </div>
              </div>
            </div>

            {/* ── ROW 2: Schedule (wide) + QR Preview (narrow) ─────────── */}
            <div className={currentPage === 'bookings' ? 'mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3' : 'hidden'}>

              {/* My Training Sessions — takes 2/3 on desktop */}
              <div className="rounded-2xl bg-white p-5 shadow-sm lg:col-span-2">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <p className="text-xs font-medium uppercase tracking-[0.24em] text-slate-500">My Schedule</p>
                    <h2 className="mt-1 text-base font-semibold text-slate-900">My Training Sessions</h2>
                  </div>
                  {/* This is the ONE primary CTA on the page */}
                  <button
                    onClick={openBookingModal}
                    className="inline-flex shrink-0 min-h-[44px] items-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white hover:bg-violet-800 shadow-sm transition"
                  >
                    <FaCalendarPlus />
                    <span className="hidden sm:inline">Book Session</span>
                    <span className="sm:hidden">Book</span>
                  </button>
                </div>

                <label className="mt-4 block text-sm font-medium text-slate-700">
                  Booking status
                  <select
                    value={bookingFilter}
                    onChange={(event) => setBookingFilter(event.target.value)}
                    className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm sm:w-auto"
                  >
                    <option value="all">All bookings</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled or rejected</option>
                  </select>
                </label>

                {bookingsError && <p role="alert" className="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{bookingsError}</p>}
                <div className="mt-4 space-y-3">
                  {bookingsLoading ? (
                    <p className="py-6 text-center text-sm text-slate-400">Loading sessions...</p>
                  ) : visibleBookings.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-6 py-10 text-center">
                      <FaCalendarPlus className="mx-auto text-2xl text-slate-300" />
                      <p className="mt-3 text-sm font-medium text-slate-700">{myBookings.length === 0 ? 'No sessions scheduled yet' : 'No bookings in this status'}</p>
                      {myBookings.length === 0 && <p className="mt-1 text-xs text-slate-500">Book your first training session to get started.</p>}
                      <button
                        onClick={openBookingModal}
                        className="mt-4 inline-flex min-h-[44px] items-center gap-2 rounded-xl border border-violet-300 px-4 py-2 text-sm font-medium text-violet-700 hover:bg-violet-50 transition"
                      >
                        <FaCalendarPlus /> Schedule a session
                      </button>
                    </div>
                  ) : (
                    visibleBookings.map((bkg) => (
                      <div
                        key={bkg.id}
                        className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4 sm:flex-row sm:items-center sm:justify-between"
                      >
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold text-slate-900">{bkg.session_type}</span>
                            <StatusBadge status={bkg.status} />
                          </div>
                          {bkg.session_items?.length > 0 && <ul className="mt-1 space-y-0.5 text-xs text-slate-600">{bkg.session_items.map((session, index) => <li key={`${bkg.id}-${session.session_type_id ?? index}`}>{session.session_name} · {session.duration_minutes} min{session.unit_price !== null && session.unit_price !== undefined ? ` · ${formatBookingAmount(session.unit_price, session.currency || 'PHP')}` : ' · price unknown'}</li>)}</ul>}
                          {bkg.subtotal !== null && bkg.subtotal !== undefined && (
                            <div className="mt-2 text-xs text-slate-600">
                              <p>Booking subtotal: {formatBookingAmount(bkg.subtotal, bkg.price_currency || 'PHP')}</p>
                              {Number(bkg.credit_amount) > 0 && <p className="font-medium text-emerald-700">Free Personal Training credit {bkg.credit_event_type === 'reserved' ? 'reserved' : bkg.credit_event_type === 'released' ? 'released' : bkg.credit_event_type === 'expired' ? 'expired' : bkg.credit_event_type === 'consumed' ? 'applied' : 'not applied'}: −{formatBookingAmount(bkg.credit_amount, bkg.price_currency || 'PHP')}</p>}
                              <p className="font-semibold text-slate-800">Amount due: {formatBookingAmount(bkg.amount_due, bkg.price_currency || 'PHP')}</p>
                              {Number(bkg.amount_due) > 0 && bkg.latest_payment_status && <p>Original payment: {bkg.latest_payment_method || 'method unavailable'} · {bkg.latest_payment_status}</p>}
                            </div>
                          )}
                          {bkg.status === 'cancelled' && (
                            <div className="mt-2 rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">
                              <p>{bkg.refund_eligible ? 'Refund eligible' : 'No refund eligible'}{bkg.refund_amount ? ` · ${formatBookingAmount(bkg.refund_amount, bkg.price_currency || 'PHP')}` : ''}{bkg.refund_status ? ` · ${bkg.refund_status}` : ''}</p>
                              {Number(bkg.credit_amount) > 0 && <p className="mt-1">Credit status: {bkg.credit_event_type === 'released' ? 'restored to its original subscription' : bkg.credit_event_type === 'expired' ? 'expired and cannot be reused' : bkg.credit_event_type === 'consumed' ? 'consumed / forfeited' : 'not restored'}.</p>}
                              {bkg.cancellation_reason && <p className="mt-1">Reason: {bkg.cancellation_reason}</p>}
                            </div>
                          )}
                          {bkg.payment_deadline_at && bkg.status === 'awaiting_payment' && (
                            <p className="mt-2 text-xs font-medium text-amber-800">Payment due by {formatNotificationDate(bkg.payment_deadline_at)}. The time slot is held until then.</p>
                          )}
                          {bkg.status === 'expired' && <p className="mt-2 text-xs font-medium text-rose-700">Payment deadline expired; this time slot is no longer reserved.</p>}
                          {bkg.status === 'awaiting_payment' && Number(bkg.amount_due) > 0 && (
                            <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                              {bkg.latest_payment_status === 'pending' ? (
                                <p className="text-xs font-medium text-amber-900">
                                  {bkg.latest_payment_method === 'gcash'
                                    ? 'GCash proof is awaiting Admin review; your time slot remains held.'
                                    : 'Cash payment request is awaiting staff confirmation.'}
                                </p>
                              ) : (
                                <>
                                  {bkg.latest_payment_status === 'failed' && <p className="mb-2 text-xs text-rose-700">Previous payment attempt was rejected. You may retry before the original deadline.</p>}
                                  <div className="flex flex-wrap items-center gap-2">
                                    <button type="button" disabled={bookingActionLoading} onClick={() => void submitBookingPayment(bkg, 'cash')} className="min-h-10 rounded-lg bg-violet-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
                                      {bookingActionLoading ? 'Submitting…' : 'Pay by cash'}
                                    </button>
                                    <label className="flex min-h-10 cursor-pointer items-center rounded-lg border border-violet-300 bg-white px-3 py-2 text-xs font-semibold text-violet-800">
                                      Choose GCash proof
                                      <input type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" className="sr-only" onChange={(event) => setBookingPaymentFiles((current) => ({ ...current, [bkg.id]: event.target.files?.[0] || null }))} />
                                    </label>
                                    <button type="button" disabled={bookingActionLoading || !bookingPaymentFiles[bkg.id]} onClick={() => void submitBookingPayment(bkg, 'gcash')} className="min-h-10 rounded-lg border border-violet-300 bg-white px-3 py-2 text-xs font-semibold text-violet-800 disabled:opacity-50">
                                      {bookingActionLoading ? 'Submitting…' : 'Submit GCash proof'}
                                    </button>
                                  </div>
                                  {bookingPaymentFiles[bkg.id] && <p className="mt-1 text-xs text-slate-600">Selected: {bookingPaymentFiles[bkg.id].name}</p>}
                                </>
                              )}
                              {bkg.status === 'pending' && bkg.response_deadline && <p className="mt-2 text-xs font-medium text-amber-800">Trainer/Admin response due by {formatNotificationDate(bkg.response_deadline)}. This is separate from the payment deadline and Member cancellation cutoff.</p>}
                            </div>
                          )}
                          <p className="mt-1 text-xs text-slate-500 truncate">
                            Code: <strong className="text-slate-700">{bkg.booking_code}</strong>
                            {bkg.trainer_name && (
                              <span> · Trainer: <strong className="text-slate-700">{bkg.trainer_name}</strong></span>
                            )}
                          </p>
                          <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-600">
                            <span className="flex items-center gap-1">
                              <FaCalendarCheck className="text-violet-500 shrink-0" />
                              {formatDate(bkg.booking_date)}
                            </span>
                            <span className="flex items-center gap-1">
                              <FaClock className="text-violet-500 shrink-0" />
                              {formatTime(bkg.start_time)} – {formatTime(bkg.end_time)}
                            </span>
                            <span>{bkg.duration_minutes} min</span>
                          </div>
                          {bkg.status === 'pending' && !bkg.within_normal_shift && <p className="mt-1 text-xs font-medium text-amber-700">Requested time is outside this trainer’s normal shift and awaits review.</p>}
                          {bkg.notes && (
                            <p className="mt-1.5 text-xs text-slate-400 italic">"{bkg.notes}"</p>
                          )}
                          {bkg.rejection_reason && <p className="mt-1 text-xs text-rose-700">Reason: {bkg.rejection_reason}</p>}
                          {bkg.alternative_proposal && (
                            <div className="mt-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-xs text-violet-900">
                              <p className="font-semibold">Alternative ({bkg.alternative_proposal.status}): {formatDate(bkg.alternative_proposal.proposed_date)} at {formatTime(bkg.alternative_proposal.proposed_start_time)}</p>
                              <p className="mt-1">Original request: {formatDate(bkg.alternative_proposal.original_date)} at {formatTime(bkg.alternative_proposal.original_start_time)}</p>
                              {bkg.alternative_proposal.trainer_note && <p className="mt-1">{bkg.alternative_proposal.trainer_note}</p>}
                              {bkg.alternative_proposal.status === 'pending' && (
                                <div className="mt-2 flex gap-2">
                                  <button type="button" disabled={bookingActionLoading} onClick={() => setBookingConfirmation({ type: 'alternative', proposalId: bkg.alternative_proposal.id, decision: 'accept' })} className="rounded-lg bg-violet-700 px-3 py-2 font-semibold text-white disabled:opacity-50">Accept time</button>
                                  <button type="button" disabled={bookingActionLoading} onClick={() => setBookingConfirmation({ type: 'alternative', proposalId: bkg.alternative_proposal.id, decision: 'decline' })} className="rounded-lg border border-violet-300 bg-white px-3 py-2 font-semibold disabled:opacity-50">Decline</button>
                                </div>
                              )}
                            </div>
                          )}
                        </div>

                        {(['pending', 'confirmed', 'awaiting_member'].includes(bkg.status)
                          || (bkg.status === 'completed' && Number(bkg.trainer_id) > 0)) && (
                          <div className="flex shrink-0 flex-wrap gap-2">
                            {bkg.status === 'completed' && Number(bkg.trainer_id) > 0 && (
                              bkg.trainer_feedback_id ? (
                                <span className="inline-flex min-h-[40px] items-center rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                  Trainer rating submitted
                                </span>
                              ) : (
                                <button
                                  type="button"
                                  onClick={() => navigate('/member/feedback', { state: { bookingId: String(bkg.id) } })}
                                  className="min-h-[40px] rounded-xl bg-violet-700 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-800 transition"
                                >
                                  Rate Trainer
                                </button>
                              )
                            )}
                            {['pending', 'confirmed', 'awaiting_member', 'awaiting_payment'].includes(bkg.status) && currentTime > 0 && canMemberCancelBooking(bkg, currentTime) && (
                              <>
                                {bkg.booking_date >= new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' }) && (
                                  <button type="button" onClick={() => openRescheduleRequest(bkg)} className="min-h-[40px] rounded-xl border border-violet-200 bg-white px-3 py-2 text-xs font-medium text-violet-700 hover:bg-violet-50 transition">
                                    Request reschedule
                                  </button>
                                )}
                                <button
                                  type="button"
                                  onClick={() => setBookingConfirmation({ type: 'cancel', bookingId: bkg.id, booking: bkg, reason: '' })}
                                  disabled={bookingActionLoading}
                                  className="min-h-[40px] rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100 transition disabled:opacity-50"
                                >
                                  Cancel booking
                                </button>
                              </>
                            )}
                          </div>
                        )}
                      </div>
                    ))
                  )}
                </div>
              </div>

            </div>

            {(currentPage === 'workout-plan' || currentPage === 'progress') && (
              <MemberFitness member={member} sections={currentPage === 'workout-plan' ? 'workout' : 'progress'} />
            )}

      </main>

      {/* ── QR Code Modal ───────────────────────────────────────────────── */}
      {qrOpen && isPassAccount && qrValue && !isGymAccessRestricted && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/40" onClick={() => setQrOpen(false)} />
          <div className="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-lg">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-slate-500">My 1 Day Pass QR</p>
                <p className="mt-1 text-lg font-semibold text-slate-900 truncate">{member.full_name}</p>
              </div>
              <button
                onClick={() => setQrOpen(false)}
                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
              >
                Close
              </button>
            </div>
            <div className="mt-6 flex flex-col items-center gap-4">
              <div className="rounded-xl bg-slate-50 p-4">
                <MemberQRCode qrValue={qrValue} size={240} />
              </div>
              <p className="text-sm text-slate-600">Verified 1 Day Pass code</p>
              <p className="text-xs text-slate-500">Show this code to staff for entry. It expires at the end of the visit date.</p>
            </div>
          </div>
        </div>
      )}

      {/* ── Booking Session Modal ───────────────────────────────────────── */}
      {bookingModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
          <div className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="mb-5 flex items-center justify-between">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Session Booking</p>
                <h3 className="mt-1 text-xl font-semibold text-slate-900">Book Training Session</h3>
              </div>
              <button
                onClick={() => setBookingModalOpen(false)}
                className="rounded-full border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100"
              >
                Close
              </button>
            </div>

            <form onSubmit={handleCreateBookingSubmit} className="space-y-4">
              <fieldset>
                <legend className="block text-sm font-medium text-slate-700">Choose one or more sessions</legend>
                <div className="mt-2 space-y-2">
                  {sessionTypes.map((session) => {
                    const selected = bookingForm.session_type_ids.includes(Number(session.id))
                    return (
                      <label key={session.id} className={`flex cursor-pointer items-center justify-between rounded-xl border p-3 transition ${selected ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-300' : 'border-slate-200 bg-white hover:border-violet-300'}`}>
                        <span className="flex items-center gap-3">
                          <input
                            type="checkbox"
                            checked={selected}
                            onChange={(event) => {
                              setCreditSummary(null)
                              setBookingForm((previous) => ({
                                ...previous,
                                session_type_ids: event.target.checked
                                  ? [...previous.session_type_ids, Number(session.id)]
                                  : previous.session_type_ids.filter((id) => id !== Number(session.id)),
                              }))
                            }}
                            className="h-4 w-4 accent-violet-700"
                          />
                          <span className="text-sm font-medium text-slate-800">{session.name}</span>
                        </span>
                        <span className="text-xs text-slate-500">{session.duration_minutes} min</span>
                        <span className="text-xs font-medium text-slate-700">
                          {(() => {
                            const price = bookingPriceVersions.find((version) => Number(version.session_type_id) === Number(session.id) && Number(version.is_current) === 1)
                            return price ? formatBookingAmount(price.amount, price.currency) : 'Price not configured'
                          })()}
                        </span>
                      </label>
                    )
                  })}
                </div>
                {(() => {
                  const selectedSessions = sessionTypes.filter((session) => bookingForm.session_type_ids.includes(Number(session.id)))
                  const totalDuration = selectedSessions.reduce((total, session) => total + Number(session.duration_minutes), 0)
                  const selectedPrices = selectedSessions.map((session) => bookingPriceVersions.find((version) => Number(version.session_type_id) === Number(session.id) && Number(version.is_current) === 1))
                  const totalPrice = selectedPrices.reduce((total, price) => total + (price ? Number(price.amount) : 0), 0)
                  const missingPrice = selectedPrices.some((price) => !price)
                  return (
                    <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                      Selected: {selectedSessions.length} {selectedSessions.length === 1 ? 'session' : 'sessions'} · Total duration: {totalDuration} minutes
                      <span className="mt-1 block font-medium">Price estimate: {missingPrice ? 'Unavailable until all prices are configured' : formatBookingAmount(totalPrice)}</span>
                      {!missingPrice && creditSummary?.eligible_session_type && (
                        <span className="mt-1 block">
                          {creditSummary.credit_available
                            ? 'An eligible Personal Training credit will be applied automatically if still available when you submit.'
                            : 'No eligible free Personal Training credit is available for this date.'}
                        </span>
                      )}
                      <span className="mt-1 block text-xs text-slate-500">The server confirms the final price and credit when the booking is submitted.</span>
                    </p>
                  )
                })()}
              </fieldset>

              <div>
                <label className="block text-sm font-medium text-slate-700">Trainer</label>
                <select
                  value={bookingForm.trainer_id}
                  onChange={(e) => {
                    const trainerId = e.target.value
                    setBookingForm((prev) => ({ ...prev, trainer_id: trainerId }))
                  }}
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                  required
                >
                  <option value="">Select a trainer</option>
                  {trainersList.map((trainer) => <option key={trainer.trainer_id} value={trainer.trainer_id}>{trainer.full_name} ({trainer.trainer_code})</option>)}
                </select>
                {trainersList.length === 0 && <p className="mt-1 text-xs text-slate-500">No active trainers are available to book.</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Booking Date</label>
                <input
                  type="date"
                  min={getGymDate()}
                  max={addDaysToDate(getGymDate(), maxAdvanceDays)}
                  value={bookingForm.booking_date}
                  onChange={(e) => {
                    setCreditSummary(null)
                    setBookingForm((prev) => ({ ...prev, booking_date: e.target.value }))
                  }}
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Preferred start time</label>
                <input
                  type="time"
                  value={bookingForm.start_time}
                  onChange={(event) => setBookingForm((prev) => ({ ...prev, start_time: event.target.value }))}
                  required
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-violet-400"
                />
                <p className="mt-1 text-xs text-slate-500">
                  Choose any preferred date within {maxAdvanceDays} days; your trainer will review the combined session request.
                </p>
              </div>

              {bookingForm.start_time && bookingForm.booking_date && (
                <div className="rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">
                  Review request: {sessionTypes.filter((session) => bookingForm.session_type_ids.includes(Number(session.id))).map((session) => `${session.name} (${session.duration_minutes} min)`).join(', ') || 'Select sessions'}, {bookingForm.booking_date} at {formatTime(bookingForm.start_time)} · {sessionTypes.filter((session) => bookingForm.session_type_ids.includes(Number(session.id))).reduce((total, session) => total + Number(session.duration_minutes), 0)} minutes with {trainersList.find((trainer) => String(trainer.trainer_id) === String(bookingForm.trainer_id))?.full_name || 'your selected trainer'}.
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-slate-700">Special Notes or Goals</label>
                <textarea
                  rows="2"
                  value={bookingForm.notes}
                  onChange={(e) => setBookingForm(prev => ({ ...prev, notes: e.target.value }))}
                  placeholder="E.g., Legs workout, cardio warm up..."
                  className="mt-1.5 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-violet-400"
                />
              </div>

              {bookingFormError && (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  {bookingFormError}
                </div>
              )}

              <div className="flex items-center justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setBookingModalOpen(false)}
                  className="min-h-[44px] rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmittingBooking || sessionTypes.some((session) => bookingForm.session_type_ids.includes(Number(session.id)) && !bookingPriceVersions.some((price) => Number(price.session_type_id) === Number(session.id) && Number(price.is_current) === 1))}
                  className="min-h-[44px] rounded-2xl bg-violet-700 px-5 py-2 text-sm font-medium text-white hover:bg-violet-800 disabled:opacity-70 transition"
                >
                  {isSubmittingBooking ? 'Submitting...' : 'Request Booking'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {rescheduleBooking && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
          <form onSubmit={submitRescheduleRequest} className="w-full max-w-md space-y-4 rounded-3xl bg-white p-6 shadow-2xl">
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Booking {rescheduleBooking.booking_code}</p>
              <h3 className="mt-1 text-xl font-semibold text-slate-900">Request a new time</h3>
              <p className="mt-1 text-sm text-slate-600">The current reservation remains in place until an administrator reviews your request.</p>
            </div>
            <label className="block text-sm font-medium text-slate-700">New date
              <input type="date" min={new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })} value={rescheduleDate} onChange={(event) => {
                const date = event.target.value
                setRescheduleDate(date)
                setRescheduleStart('')
                loadAvailableSlots(rescheduleBooking.trainer_id, date, rescheduleBooking.session_type, rescheduleBooking.id)
              }} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" />
            </label>
            <label className="block text-sm font-medium text-slate-700">Available time
              <select value={rescheduleStart} onChange={(event) => setRescheduleStart(event.target.value)} required disabled={loadingSlots || !availableSlots.length} className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 disabled:opacity-60">
                <option value="">{loadingSlots ? 'Checking availability…' : 'Select a time'}</option>
                {availableSlots.map((slot) => <option key={slot.start_time} value={slot.start_time}>{formatTime(slot.start_time)}–{formatTime(slot.end_time)}</option>)}
              </select>
            </label>
            {availabilityMessage && <p className="text-xs text-slate-600">{availabilityMessage}</p>}
            {rescheduleError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{rescheduleError}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setRescheduleBooking(null)} className="rounded-xl border border-slate-300 px-4 py-2 text-sm">Close</button>
              <button type="submit" disabled={!rescheduleStart} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Send request</button>
            </div>
          </form>
        </div>
      )}

      {upgradeOpen && member && (
        <MemberMembershipUpgrade
          member={member}
          onClose={() => setUpgradeOpen(false)}
          onSubmitted={(message) => {
            setUpgradeNotice(message)
            setUpgradeOpen(false)
            setProfileAttempt((attempt) => attempt + 1)
          }}
        />
      )}
      {dailyPassPurchaseOpen && member && (
        <MemberDailyPassPurchase
          onClose={() => setDailyPassPurchaseOpen(false)}
          onSubmitted={(message) => {
            setUpgradeNotice(message)
            setDailyPassPurchaseOpen(false)
            setProfileAttempt((attempt) => attempt + 1)
          }}
        />
      )}
      <ConfirmationModal
        open={Boolean(bookingConfirmation)}
        title={bookingConfirmation?.type === 'cancel'
          ? 'Cancel this booking?'
          : bookingConfirmation?.decision === 'accept' ? 'Accept the proposed time?' : 'Decline the proposed time?'}
        message={bookingConfirmation?.type === 'cancel'
          ? (
            <>
              <p>{bookingConfirmation.booking?.status === 'awaiting_payment'
                ? 'This approved booking is still unpaid. Cancellation is available only at least 24 hours before the session.'
                : bookingStartTimestamp(bookingConfirmation.booking) - currentTime >= 24 * 60 * 60 * 1000
                  ? 'Cancelling at least 24 hours before the session qualifies for a full refund of a paid booking and eligible credit restoration.'
                  : 'This is less than 24 hours before the session. No refund or consumed-credit restoration will be available.'}</p>
              <label className="mt-3 block text-left text-xs font-medium text-slate-700">
                Cancellation reason (optional)
                <textarea
                  rows={3}
                  maxLength={1000}
                  value={bookingConfirmation.reason || ''}
                  onChange={(event) => setBookingConfirmation((current) => ({ ...current, reason: event.target.value }))}
                  className="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm font-normal text-slate-900"
                />
              </label>
            </>
          )
          : bookingConfirmation?.decision === 'accept'
            ? 'The booking will move to the proposed date and time if it remains valid.'
            : 'The booking request will be declined and the trainer will be notified.'}
        confirmText={bookingConfirmation?.type === 'cancel'
          ? 'Cancel Booking'
          : bookingConfirmation?.decision === 'accept' ? 'Accept Time' : 'Decline Time'}
        variant={bookingConfirmation?.type === 'cancel' || bookingConfirmation?.decision === 'decline' ? 'danger' : 'default'}
        loading={bookingActionLoading}
        loadingText="Updating…"
        onConfirm={confirmBookingAction}
        onCancel={() => setBookingConfirmation(null)}
      />

    </div>
  )
}
