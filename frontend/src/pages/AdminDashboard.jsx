import { useNavigate } from 'react-router-dom'
import { useEffect, useMemo, useState } from 'react'
import api from '../services/api'
import ActionButton from '../components/ui/ActionButton.jsx'
import PageCard from '../components/ui/PageCard.jsx'
import LogoutConfirmationModal from '../components/ui/LogoutConfirmationModal.jsx'
import NotificationCenter from '../components/NotificationCenter.jsx'
import { logout } from '../utils/auth'
import { formatFullName } from '../utils/nameFormatter'
import {
  FaDumbbell,
  FaCalendarCheck,
  FaCalendarAlt,
  FaCreditCard,
  FaChartBar,
  FaUsers,
  FaQrcode,
  FaChevronRight,
  FaCircle,
  FaClipboardList,
  FaUserCircle,
  FaCog,
} from 'react-icons/fa'

const navGroups = [
  { section: 'Account', items: [
    { label: 'Admin Profile', icon: FaUserCircle, path: '/admin/profile' },
  ] },
  { section: 'Overview', items: [{ label: 'Dashboard', icon: FaChartBar, path: '/dashboard' }] },
  { section: 'Management', items: [
    { label: 'Member Management', icon: FaUsers, path: '/members' },
    { label: 'Trainer Management', icon: FaDumbbell, path: '/trainer/manage' },
  ] },
  { section: 'Attendance', items: [
    { label: 'Gym QR Display', icon: FaQrcode, path: '/attendance/qr-display' },
    { label: 'Member Attendance', icon: FaCalendarAlt, path: '/attendance/records' },
    { label: 'Trainer Attendance', icon: FaCalendarAlt, path: '/trainer/attendance/records' },
  ] },
  { section: 'Bookings', items: [{ label: 'Bookings', icon: FaCalendarCheck, path: '/bookings' }] },
  { section: 'Finance', items: [{ label: 'Payments', icon: FaCreditCard, path: '/payments' }] },
  { section: 'Feedback', items: [
    { label: 'Feedback Management', icon: FaClipboardList, path: '/feedback' },
    { label: 'Settings', icon: FaCog, path: '/admin/settings' },
  ] },
]

function manilaMonthKey(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(date)
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}`
}

function manilaDateKey(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function monthLabel(value) {
  const [year, month] = value.split('-').map(Number)
  return new Intl.DateTimeFormat('en-PH', { month: 'short', timeZone: 'Asia/Manila' })
    .format(new Date(Date.UTC(year, month - 1, 1)))
}

function AdminDashboard() {
  const navigate = useNavigate()
  const [totalMembers, setTotalMembers] = useState(null)
  const [checkedInCount, setCheckedInCount] = useState(null)
  const [checkedInError, setCheckedInError] = useState('')
  const [todayBookings, setTodayBookings] = useState(0)
  const [pendingBookings, setPendingBookings] = useState(0)
  const [monthlyRevenue, setMonthlyRevenue] = useState([])
  const [loadingStats, setLoadingStats] = useState(true)
  const [statsError, setStatsError] = useState('')
  const [applicationsError, setApplicationsError] = useState('')
  const [paymentsError, setPaymentsError] = useState('')
  const [registrationPaymentError, setRegistrationPaymentError] = useState('')
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false)

  const [pendingRequests, setPendingRequests] = useState([])
  const [pendingPayments, setPendingPayments] = useState([])
  const [pendingPaymentRequests, setPendingPaymentRequests] = useState([])
  const [loadingRequests, setLoadingRequests] = useState(true)
  const [loadingPayments, setLoadingPayments] = useState(true)
  const [loadingPaymentRequests, setLoadingPaymentRequests] = useState(true)
  const [bookingsError, setBookingsError] = useState('')
  const [trainersError, setTrainersError] = useState('')

  // Upcoming Bookings
  const [upcomingBookings, setUpcomingBookings] = useState([])
  const [loadingBookings, setLoadingBookings] = useState(false)

  // Trainer Availability
  const [trainers, setTrainers] = useState([])
  const [loadingTrainers, setLoadingTrainers] = useState(false)

  const [adminUser] = useState(() => {
    try {
      const raw = localStorage.getItem('user')
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  })

  useEffect(() => {
    let mounted = true
    const fetchStats = async () => {
      setLoadingStats(true)
      try {
        const res = await api.get('/stats.php')
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load dashboard statistics.')
        if (!mounted) return
        setTotalMembers(res.data.total_members ?? 0)
        setTodayBookings(Number(res.data.bookings?.today_bookings ?? 0))
        setPendingBookings(Number(res.data.bookings?.new_bookings ?? 0))
        setMonthlyRevenue(Array.isArray(res.data.monthly_revenue) ? res.data.monthly_revenue : [])
        setStatsError('')
      } catch (requestError) {
        if (mounted) setStatsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load dashboard statistics.')
      } finally {
        if (mounted) setLoadingStats(false)
      }
    }

    fetchStats()
    const id = setInterval(fetchStats, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  useEffect(() => {
    let mounted = true
    const fetchCheckedIn = async () => {
      try {
        const response = await api.get('/admin_checked_in.php')
        if (!response?.data?.success) throw new Error(response?.data?.message || 'Unable to load current attendance.')
        if (mounted) {
          setCheckedInCount(Number(response.data.counts?.members || 0) + Number(response.data.counts?.trainers || 0))
          setCheckedInError('')
        }
      } catch (requestError) {
        if (mounted) setCheckedInError(requestError?.response?.data?.message || requestError?.message || 'Unable to load currently checked-in members and trainers.')
      }
    }

    fetchCheckedIn()
    const id = setInterval(fetchCheckedIn, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  // Fetch Pending Registration Requests
  useEffect(() => {
    let mounted = true
    const fetchRequests = async () => {
      setLoadingRequests(true)
      try {
        const res = await api.get('/registration_requests.php')
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load pending applications.')
        if (mounted) {
          setPendingRequests(res.data.registration_requests || [])
          setApplicationsError('')
        }
      } catch (requestError) {
        if (mounted) setApplicationsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load pending applications.')
      } finally {
        if (mounted) setLoadingRequests(false)
      }
    }

    fetchRequests()
    const id = setInterval(fetchRequests, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  useEffect(() => {
    let mounted = true
    const fetchRegistrationPaymentReviews = async () => {
      try {
        const res = await api.get('/registration_requests.php', { params: { action: 'payment_reviews' } })
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load registration payment reviews.')
        if (mounted) {
          setPendingPaymentRequests(res.data.payment_reviews || [])
          setRegistrationPaymentError('')
        }
      } catch (requestError) {
        if (mounted) setRegistrationPaymentError(requestError?.response?.data?.message || requestError?.message || 'Unable to load registration payment reviews.')
      } finally {
        if (mounted) setLoadingPaymentRequests(false)
      }
    }

    fetchRegistrationPaymentReviews()
    const id = setInterval(fetchRegistrationPaymentReviews, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  useEffect(() => {
    let mounted = true
    const fetchPendingPayments = async () => {
      setLoadingPayments(true)
      try {
        const res = await api.get('/payments.php', { params: { status: 'pending' } })
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load pending payment reviews.')
        if (mounted) {
          const uniquePayments = new Map()
          for (const payment of res.data.payments || []) {
            const key = `${payment.record_type || 'payment'}:${payment.record_id ?? payment.id}`
            if (payment.status === 'pending') uniquePayments.set(key, payment)
          }
          setPendingPayments([...uniquePayments.values()])
          setPaymentsError('')
        }
      } catch (requestError) {
        if (mounted) setPaymentsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load pending payment reviews.')
      } finally {
        if (mounted) setLoadingPayments(false)
      }
    }

    fetchPendingPayments()
    const id = setInterval(fetchPendingPayments, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  // Fetch Upcoming Bookings
  useEffect(() => {
    let mounted = true
    const fetchBookings = async () => {
      setLoadingBookings(true)
      try {
        const today = manilaDateKey()
        const res = await api.get('/bookings.php', {
          params: { status: 'pending,confirmed', date_from: today },
        })
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load upcoming bookings.')
        if (mounted) {
          setUpcomingBookings((res.data.bookings || []).slice().sort((left, right) => (
            `${left.booking_date} ${left.start_time}`.localeCompare(`${right.booking_date} ${right.start_time}`)
          )).slice(0, 5))
          setBookingsError('')
        }
      } catch (requestError) {
        if (mounted) setBookingsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load upcoming bookings.')
      } finally {
        if (mounted) setLoadingBookings(false)
      }
    }

    fetchBookings()
    const id = setInterval(fetchBookings, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  // Fetch Trainer Availability Status
  useEffect(() => {
    let mounted = true
    const fetchTrainers = async () => {
      setLoadingTrainers(true)
      try {
        const res = await api.get('/trainers.php')
        if (!res?.data?.success) throw new Error(res?.data?.message || 'Unable to load trainer availability.')
        if (mounted) {
          setTrainers(res.data.trainers || [])
          setTrainersError('')
        }
      } catch (requestError) {
        if (mounted) setTrainersError(requestError?.response?.data?.message || requestError?.message || 'Unable to load trainer availability.')
      } finally {
        if (mounted) setLoadingTrainers(false)
      }
    }

    fetchTrainers()
    const id = setInterval(fetchTrainers, 30000)
    return () => { mounted = false; clearInterval(id) }
  }, [])

  const handleLogout = () => {
    setShowLogoutConfirm(true)
  }

  const handleConfirmLogout = async () => {
    setShowLogoutConfirm(false)
    await logout(navigate)
  }

  const formattedAdminName = adminUser ? formatFullName(adminUser) : ''

  const availableTrainers = trainers.filter((t) => t.status === 'active')
  const unavailableTrainers = trainers.filter((t) => t.status !== 'active')
  const monthlyApplications = pendingRequests.filter((request) => request.membership_type === 'monthly')
  const pendingRegistrations = pendingRequests.filter((request) => (
    (request.membership_type === 'monthly' && !request.member_id)
    || Number(request.is_initial_daily_pass_approval) === 1
  ))
  const pendingRenewals = monthlyApplications.filter((request) => (
    request.member_id && ['monthly', 'monthly_subscription', 'monthlysubscription']
      .includes(String(request.current_membership_type || '').toLowerCase().replace(/[\s-]/g, ''))
  ))
  const pendingUpgrades = monthlyApplications.filter((request) => (
    request.member_id && !pendingRenewals.includes(request)
  ))
  const registrationPaymentRequestIds = new Set(
    pendingPaymentRequests.map((request) => Number(request.id))
  )
  const initialDailyPassTransactionIds = new Set(
    pendingRequests
      .filter((request) => Number(request.is_initial_daily_pass_approval) === 1)
      .map((request) => Number(request.initial_pass_id))
      .filter((id) => id > 0)
  )
  const paymentTransactions = pendingPayments.filter((payment) => (
    !registrationPaymentRequestIds.has(Number(payment.registration_request_id))
    && !(payment.record_type === 'daily_pass' && initialDailyPassTransactionIds.has(Number(payment.record_id)))
  ))
  const pendingBookingPayments = paymentTransactions.filter((p) => p.payment_type === 'training_session')
  const pendingMembershipPayments = pendingPaymentRequests.length + paymentTransactions.filter((p) => p.payment_type === 'monthly_subscription').length
  const pendingDailyPassPayments = paymentTransactions.filter((p) => p.record_type === 'daily_pass').length
  const revenueByMonth = useMemo(() => {
    const values = new Map((monthlyRevenue || []).map((item) => [item.revenue_month, Number(item.revenue) || 0]))
    const [year, month] = manilaMonthKey().split('-').map(Number)
    return Array.from({ length: 12 }, (_, index) => {
      const monthDate = new Date(Date.UTC(year, month - 1 - 11 + index, 1))
      const key = `${monthDate.getUTCFullYear()}-${String(monthDate.getUTCMonth() + 1).padStart(2, '0')}`
      return { key, label: monthLabel(key), value: values.get(key) || 0 }
    })
  }, [monthlyRevenue])
  const paymentsThisMonth = revenueByMonth[revenueByMonth.length - 1]?.value || 0
  const maxMonthlyRevenue = Math.max(...revenueByMonth.map((month) => month.value), 1)

  const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'PHP',
      minimumFractionDigits: 0,
    }).format(value)
  }

  const formatDate = (dateStr) => {
    if (!dateStr) return ''
    try {
      const date = new Date(`${String(dateStr).slice(0, 10)}T00:00:00+08:00`)
      return date.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' })
    } catch {
      return dateStr
    }
  }

  const formatTime = (timeStr) => {
    if (!timeStr) return ''
    try {
      const [hours, minutes] = timeStr.split(':')
      const hour = parseInt(hours, 10)
      const ampm = hour >= 12 ? 'PM' : 'AM'
      const displayHour = hour % 12 || 12
      return `${displayHour}:${minutes} ${ampm}`
    } catch {
      return timeStr
    }
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3 sm:gap-4">
            <img
              src={`${import.meta.env.BASE_URL}images/gym-logo.png`}
              alt=""
              onError={(event) => { event.currentTarget.style.visibility = 'hidden' }}
              className="h-10 w-10 shrink-0 object-contain sm:h-20 sm:w-20"
            />
            <div className="min-w-0">
              <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-slate-500 sm:text-xs">
                Rayben's Gym Management System
              </p>
              <h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Admin Dashboard</h1>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <NotificationCenter />
            <div className="rounded-xl bg-slate-900 px-3 py-2 text-sm font-medium text-white">
              {formattedAdminName || 'Admin'}
            </div>
            <ActionButton variant="secondary" onClick={handleLogout}>Logout</ActionButton>
          </div>
        </div>
      </header>

      <main className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[260px_1fr]">
        <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <nav className="space-y-3" aria-label="Admin navigation">
            {navGroups.map((group) => (
              <div key={group.section} className="space-y-1">
                <p className="px-3 pt-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">{group.section}</p>
                {group.items.map((item) => {
                  const Icon = item.icon
                  const isActive = item.path === '/dashboard'
                  return (
                    <button
                      key={item.label}
                      type="button"
                      disabled={!item.path}
                      onClick={() => item.path && navigate(item.path)}
                      className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition ${isActive ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200' : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900 disabled:cursor-default disabled:text-slate-400'}`}
                    >
                      <span className="flex items-center gap-3">
                        <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${isActive ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-600'}`}>
                          <Icon />
                        </span>
                        <span className="font-medium">{item.label}</span>
                      </span>
                      {item.path && <FaChevronRight className="text-xs opacity-70" />}
                    </button>
                  )
                })}
              </div>
            ))}
          </nav>
        </aside>

        <section className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Total Members</p>
                  <p className="mt-3 text-3xl font-semibold text-slate-900">{loadingStats ? '...' : (totalMembers ?? 0)}</p>
                </div>
                <FaUsers className="text-2xl text-slate-300" />
              </div>
              <p className="mt-3 text-sm text-slate-500">All gym members</p>
            </article>
            <button type="button" onClick={() => navigate('/attendance/checked-in')} className="rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-sky-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sky-300">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Today's Checked In</p>
                  <p className="mt-3 text-3xl font-semibold text-slate-900">{checkedInCount === null ? '...' : checkedInCount}</p>
                </div>
                <FaCircle className="text-2xl text-emerald-400" />
              </div>
              <p className="mt-3 text-sm text-slate-500">Members and trainers on site · manage checkouts</p>
            </button>
            <button type="button" onClick={() => navigate('/bookings')} className="rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-sky-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sky-300">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Today's Bookings</p>
                  <p className="mt-3 text-3xl font-semibold text-slate-900">{loadingStats ? '...' : todayBookings}</p>
                </div>
                <FaCalendarCheck className="text-2xl text-blue-400" />
              </div>
              <p className="mt-3 text-sm text-slate-500">View booking schedule</p>
            </button>
            <button type="button" onClick={() => navigate('/payments')} className="rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-sky-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sky-300">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Monthly Revenue</p>
                  <p className="mt-3 text-2xl font-semibold text-slate-900">{loadingStats ? '...' : formatCurrency(paymentsThisMonth)}</p>
                </div>
                <FaCreditCard className="text-2xl text-amber-400" />
              </div>
              <p className="mt-3 text-sm text-slate-500">Paid and verified payments</p>
            </button>
          </div>

          {(statsError || checkedInError) && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{statsError || checkedInError}</p>}
          {applicationsError && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{applicationsError}</p>}
          {(paymentsError || registrationPaymentError) && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{paymentsError || registrationPaymentError}</p>}

          <PageCard title="Pending Actions" subtitle="Items requiring administrative review">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
              {[
                { label: 'New Applications', count: pendingRegistrations.length, loading: loadingRequests, error: applicationsError, path: '/members?tab=applications&category=registrations', subtitle: 'Member registrations' },
                { label: 'Renewal Requests', count: pendingRenewals.length, loading: loadingRequests, error: applicationsError, path: '/members?tab=applications&category=renewals', subtitle: 'Monthly renewals' },
                { label: 'Upgrade Requests', count: pendingUpgrades.length, loading: loadingRequests, error: applicationsError, path: '/members?tab=applications&category=upgrades', subtitle: 'Membership upgrades' },
                {
                  label: 'Pending Payments',
                  count: pendingMembershipPayments,
                  loading: loadingPayments || loadingPaymentRequests,
                  error: paymentsError || registrationPaymentError,
                  path: '/members?tab=applications&category=payment-reviews',
                  subtitle: `Membership reviews${pendingDailyPassPayments > 0 ? ` (${pendingDailyPassPayments} Daily Pass)` : ''}`,
                },
                {
                  label: 'Booking Payments',
                  count: pendingBookingPayments.length,
                  loading: loadingPayments,
                  error: paymentsError,
                  path: '/members?tab=booking-payments',
                  subtitle: 'Training session payments',
                },
                { label: 'Booking Requests', count: pendingBookings, loading: loadingStats, error: statsError, path: '/bookings?status=pending', subtitle: 'Pending bookings' },
              ].map((item) => (
                <button key={item.label} type="button" onClick={() => navigate(item.path)} className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-sky-200 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-300">
                  <p className="text-sm font-medium text-slate-600">{item.label}</p>
                  <p className="mt-2 text-2xl font-semibold text-slate-900">{item.error ? '—' : item.loading ? '...' : item.count}</p>
                  <p className="mt-1 text-xs text-slate-500">{item.subtitle}</p>
                </button>
              ))}
            </div>
          </PageCard>

          <PageCard title="Revenue Overview" subtitle="Monthly paid and verified revenue, including Daily Passes">
            <div className="grid h-48 grid-cols-6 items-end gap-2 sm:grid-cols-12" role="img" aria-label="Monthly revenue for the last 12 months">
              {revenueByMonth.map((month) => (
                <div key={month.key} className="flex h-full flex-col items-center justify-end gap-2" title={`${month.label}: ${formatCurrency(month.value)}`}>
                  <div className="flex h-full w-full items-end">
                    <div className="w-full rounded-t-md bg-sky-500 transition-all" style={{ height: `${Math.max((month.value / maxMonthlyRevenue) * 100, month.value > 0 ? 5 : 0)}%` }} />
                  </div>
                  <span className="text-[10px] text-slate-500">{month.label}</span>
                </div>
              ))}
            </div>
            <p className="mt-4 text-right text-sm font-medium text-slate-700">This month: {loadingStats ? '...' : formatCurrency(paymentsThisMonth)}</p>
          </PageCard>

          {/* Upcoming Bookings and Trainer Availability */}
          <div className="grid gap-6 xl:grid-cols-2">
            <PageCard 
              title="Upcoming Bookings" 
              subtitle="Next scheduled sessions" 
              action={<ActionButton variant="secondary" size="sm" onClick={() => navigate('/bookings')}>View all</ActionButton>}
            >
              {loadingBookings ? (
                <p className="text-sm text-slate-500">Loading...</p>
              ) : bookingsError ? (
                <p role="alert" className="text-sm text-rose-700">{bookingsError}</p>
              ) : upcomingBookings.length > 0 ? (
                <div className="space-y-3">
                  {upcomingBookings.map((booking) => (
                    <div key={booking.id} className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-4">
                      <div className="min-w-0 flex-1">
                        <p className="font-medium text-slate-900">
                          {booking.member_name || booking.booking_code} · {booking.trainer_name || 'Trainer pending'}
                        </p>
                        <p className="text-sm text-slate-500">
                          {formatDate(booking.booking_date)} at {formatTime(booking.start_time)}
                        </p>
                        <p className="text-xs text-slate-500">{booking.session_type || 'Training session'}</p>
                      </div>
                      <span className={`ml-2 shrink-0 rounded-lg px-2 py-1 text-xs font-medium ${
                        booking.status === 'confirmed' 
                          ? 'bg-green-100 text-green-700' 
                          : 'bg-yellow-100 text-yellow-700'
                      }`}>
                        {booking.status}
                      </span>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                  No upcoming bookings scheduled.
                </div>
              )}
            </PageCard>

            <PageCard 
              title="Trainer Status" 
              subtitle="Availability overview" 
              action={<ActionButton variant="secondary" size="sm" onClick={() => navigate('/trainer/manage')}>Manage</ActionButton>}
            >
              {loadingTrainers ? (
                <p className="text-sm text-slate-500">Loading...</p>
              ) : trainersError ? (
                <p role="alert" className="text-sm text-rose-700">{trainersError}</p>
              ) : trainers.length > 0 ? (
                <div className="space-y-4">
                  {availableTrainers.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-medium uppercase text-slate-500">Available ({availableTrainers.length})</p>
                      <div className="space-y-2">
                        {availableTrainers.slice(0, 3).map((trainer) => (
                          <div key={trainer.id} className="flex items-center gap-3 rounded-lg bg-emerald-50 p-3">
                            <FaCircle className="text-xs text-emerald-500" />
                            <span className="text-sm font-medium text-slate-900">
                              {trainer.full_name || formatFullName(trainer)}
                            </span>
                          </div>
                        ))}
                        {availableTrainers.length > 3 && (
                          <p className="text-xs text-slate-500">+{availableTrainers.length - 3} more available</p>
                        )}
                      </div>
                    </div>
                  )}
                  {unavailableTrainers.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-medium uppercase text-slate-500">Unavailable ({unavailableTrainers.length})</p>
                      <div className="space-y-2">
                        {unavailableTrainers.slice(0, 2).map((trainer) => (
                          <div key={trainer.id} className="flex items-center gap-3 rounded-lg bg-slate-50 p-3">
                            <FaCircle className="text-xs text-slate-400" />
                            <span className="text-sm font-medium text-slate-500">
                              {trainer.full_name || formatFullName(trainer)}
                            </span>
                          </div>
                        ))}
                        {unavailableTrainers.length > 2 && (
                          <p className="text-xs text-slate-500">+{unavailableTrainers.length - 2} more unavailable</p>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                  No trainer data available.
                </div>
              )}
            </PageCard>
          </div>
        </section>
      </main>

      <LogoutConfirmationModal
        open={showLogoutConfirm}
        onConfirm={handleConfirmLogout}
        onCancel={() => setShowLogoutConfirm(false)}
      />
    </div>
  )
}

export default AdminDashboard
