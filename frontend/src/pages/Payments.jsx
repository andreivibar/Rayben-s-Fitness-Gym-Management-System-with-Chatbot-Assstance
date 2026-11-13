import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FaArrowLeft, FaCreditCard, FaSearch } from 'react-icons/fa'
import api from '../services/api'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import MemberMembershipUpgrade from '../components/MemberMembershipUpgrade.jsx'

const paymentTypes = [
  { value: 'monthly_subscription', label: 'Monthly subscription' },
  { value: 'training_session', label: 'Training session' },
  { value: 'daily_pass', label: '1 Day Pass' },
]
const paymentStatuses = ['paid', 'pending', 'failed']
const PAGE_SIZE = 15

function formatDate(value) {
  if (!value) return '—'
  const raw = String(value).slice(0, 10)
  const parsed = new Date(`${raw}T00:00:00+08:00`)
  return Number.isNaN(parsed.getTime())
    ? '—'
    : parsed.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric' })
}

function formatDateTime(value) {
  if (!value) return '—'
  const raw = String(value)
  const parsed = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  return Number.isNaN(parsed.getTime())
    ? raw
    : parsed.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })
}

function formatAmount(value) {
  return Number(value || 0).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' })
}

function transactionTimestamp(payment) {
  const value = payment.record_type === 'daily_pass'
    ? payment.verified_at || payment.created_at || payment.purchase_date || payment.payment_date
    : payment.payment_date || payment.created_at
  if (!value) return 0
  const raw = String(value)
  const parsed = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime()
}

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('user') || sessionStorage.getItem('user') || 'null')
  } catch {
    return null
  }
}

export default function Payments() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const user = useMemo(() => getStoredUser(), [])
  const role = String(user?.role || '').toLowerCase()
  const isAdmin = ['admin', 'administrator', 'staff'].includes(role)
  const isRefundAdmin = ['admin', 'administrator'].includes(role)
  const [payments, setPayments] = useState([])
  const [bookingRefunds, setBookingRefunds] = useState([])
  const [refundReferences, setRefundReferences] = useState({})
  const [refundActionLoading, setRefundActionLoading] = useState(null)
  const [summary, setSummary] = useState({ total_paid: 0, pending_amount: 0, pending_count: 0, transaction_count: 0 })
  const [membership, setMembership] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState(() => {
    const requestedStatus = searchParams.get('status')
    return paymentStatuses.includes(requestedStatus) ? requestedStatus : 'all'
  })
  const [typeFilter, setTypeFilter] = useState('all')
  const [dateRange, setDateRange] = useState('')
  const [sortOrder, setSortOrder] = useState('newest')
  const [page, setPage] = useState(1)
  const [selectedPayment, setSelectedPayment] = useState(null)
  const [paymentReviewConfirmation, setPaymentReviewConfirmation] = useState(null)
  const [paymentReviewLoading, setPaymentReviewLoading] = useState(false)
  const [proofDownloadLoading, setProofDownloadLoading] = useState(false)
  const [showMemberUpgrade, setShowMemberUpgrade] = useState(false)
  const [notice, setNotice] = useState('')

  const fetchPayments = useCallback(async () => {
    setLoading(true)
    try {
      const params = isAdmin
        ? {
            search: search || undefined,
            status: statusFilter === 'all' ? undefined : statusFilter,
            payment_type: typeFilter === 'all' ? undefined : typeFilter,
            date_range: dateRange || undefined,
          }
        : undefined
      const response = await api.get('/payments.php', { params })
      if (!response?.data?.success) throw new Error(response?.data?.message || 'Unable to load payment history.')
      setPayments(response.data.payments || [])
      setSummary(response.data.summary || { total_paid: 0, pending_amount: 0, pending_count: 0, transaction_count: 0 })
      setMembership(response.data.membership || null)
      if (isRefundAdmin) {
        const refundResponse = await api.get('/payments.php', { params: { view: 'booking_refunds' } })
        if (!refundResponse.data?.success) throw new Error(refundResponse.data?.message || 'Unable to load booking refunds.')
        setBookingRefunds(refundResponse.data.refunds || [])
      }
      setError('')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load payment history.')
    } finally {
      setLoading(false)
    }
  }, [dateRange, isAdmin, isRefundAdmin, search, statusFilter, typeFilter])

  const handleRefundAction = async (refund, action) => {
    if (refundActionLoading) return
    const externalReference = String(refundReferences[refund.id] || '').trim()
    if (action === 'process_booking_refund' && !externalReference) {
      setError('Enter the actual Cash receipt or GCash refund transaction reference before processing.')
      return
    }
    setRefundActionLoading(refund.id)
    setError('')
    try {
      const response = await api.post('/payments.php', {
        action,
        refund_id: refund.id,
        ...(action === 'process_booking_refund'
          ? { external_reference: externalReference, confirm_external_refund: true }
          : {}),
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to update the refund.')
      setNotice(response.data.message || 'Refund status updated.')
      await fetchPayments()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to update the refund.')
    } finally {
      setRefundActionLoading(null)
    }
  }

  const handleMonthlyPaymentReview = async () => {
    if (!selectedPayment || !paymentReviewConfirmation || paymentReviewLoading) return
    setPaymentReviewLoading(true)
    setError('')
    try {
      const isBookingPayment = selectedPayment.payment_type === 'training_session'
      const action = isBookingPayment
        ? 'review_booking_payment'
        : selectedPayment.payment_method === 'cash'
          ? 'review_monthly_cash'
          : 'review_monthly_gcash'
      const payload = new FormData()
      payload.append('action', action)
      payload.append(isBookingPayment ? 'payment_id' : 'id', String(selectedPayment.record_id || selectedPayment.id))
      if (isBookingPayment || paymentReviewConfirmation.decision !== 'cash') {
        payload.append('decision', paymentReviewConfirmation.decision)
      }
      const response = await api.post('/payments.php', payload)
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to save the monthly payment review.')
      }
      setPaymentReviewConfirmation(null)
      setSelectedPayment(null)
      setNotice(response.data.message || 'Monthly payment review saved.')
      await fetchPayments()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save the payment review.')
    } finally {
      setPaymentReviewLoading(false)
    }
  }

  const downloadPaymentProof = async () => {
    if (!selectedPayment || proofDownloadLoading) return
    setProofDownloadLoading(true)
    setError('')
    try {
      const paymentId = selectedPayment.record_id || selectedPayment.id
      const response = await api.get('/payment_proof.php', {
        params: { id: paymentId },
        responseType: 'blob',
      })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = `membership-payment-proof-${paymentId}`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to download the payment proof.')
    } finally {
      setProofDownloadLoading(false)
    }
  }

  useEffect(() => {
    if (!user || (!isAdmin && role !== 'member')) {
      navigate('/login', { replace: true })
      return
    }
    Promise.resolve().then(fetchPayments)
  }, [fetchPayments, isAdmin, navigate, role, user])

  const isDailyPassMember = ['dailypass', 'daypass', 'walkin', 'walkinmembership'].includes(
    String(membership?.membership_type || '').toLowerCase().replace(/[^a-z0-9]+/g, '')
  )
  const latestDailyPass = isDailyPassMember
    ? payments.find((payment) => payment.payment_type === 'daily_pass')
    : null
  const selectedIsPendingMonthlyPayment = isAdmin
    && selectedPayment?.record_type !== 'daily_pass'
    && selectedPayment?.payment_type === 'monthly_subscription'
    && selectedPayment?.status === 'pending'
    && selectedPayment?.verification_status === 'pending'
    && !selectedPayment?.registration_request_id
  const selectedIsPendingBookingPayment = isAdmin
    && selectedPayment?.payment_type === 'training_session'
    && selectedPayment?.status === 'pending'
    && selectedPayment?.verification_status === 'pending'
  const sortedPayments = useMemo(() => {
    const uniquePayments = new Map()
    for (const payment of payments) {
      const key = payment.record_type === 'daily_pass'
        ? `daily-pass:${payment.record_id || payment.id}`
        : `payment:${payment.record_id || payment.id}`
      uniquePayments.set(key, payment)
    }
    return [...uniquePayments.values()].sort((left, right) => {
    const dateDifference = transactionTimestamp(right) - transactionTimestamp(left)
    const orderDifference = sortOrder === 'newest' ? dateDifference : -dateDifference
    return orderDifference || Number(right.record_id || right.id) - Number(left.record_id || left.id)
    })
  }, [payments, sortOrder])
  const pageCount = Math.max(1, Math.ceil(sortedPayments.length / PAGE_SIZE))
  const currentPage = Math.min(page, pageCount)
  const visiblePayments = sortedPayments.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  if (!user || (!isAdmin && role !== 'member')) return null

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div className="flex items-center gap-4">
            <Link to={isAdmin ? '/dashboard' : '/member/dashboard'} className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700" title="Back">
              <FaArrowLeft />
            </Link>
            <div>
              <p className="text-[10px] font-medium uppercase tracking-[0.28em] text-slate-500">Finance</p>
              <h1 className="mt-1 text-xl font-semibold sm:text-2xl">{isAdmin ? 'Payment History' : 'My Payments'}</h1>
            </div>
          </div>
          {role === 'member' && membership && (
            <button type="button" onClick={() => setShowMemberUpgrade(true)} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white">
              Purchase or renew monthly subscription
            </button>
          )}
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        {notice && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
        {isAdmin && (
          <p className="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
            Review pending monthly subscription and booking payments from transaction details below. Public registration payments remain in Applications, and Daily Pass payments are reviewed in Member Management → Daily Passes.
          </p>
        )}

        {isRefundAdmin && (
          <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div><h2 className="text-lg font-semibold">Booking refund requests</h2><p className="mt-1 text-sm text-slate-600">Confirm the actual external Cash or GCash refund before entering its reference.</p></div>
              <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-800">{bookingRefunds.length} records</span>
            </div>
            <div className="mt-4 space-y-3">
              {bookingRefunds.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No booking refund requests.</p> : bookingRefunds.map((refund) => (
                <article key={refund.id} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-semibold text-slate-900">{refund.booking_code} · {refund.member_name} ({refund.member_code})</p>
                      <p className="mt-1 text-xs text-slate-600">Payment #{refund.payment_id} · Original {String(refund.original_payment_method || refund.payment_method).toUpperCase()} · {formatAmount(refund.original_amount)} · Refund {formatAmount(refund.amount)}</p>
                      <p className="mt-1 break-words text-xs text-slate-600">Reason: {refund.request_reason || refund.cancellation_reason || 'Cancellation policy'}</p>
                      <p className="mt-1 text-xs text-slate-500">Requested {formatDateTime(refund.created_at)}{refund.approved_at ? ` · Approved by ${refund.approved_by_name || 'Admin'} ${formatDateTime(refund.approved_at)}` : ''}{refund.processed_at ? ` · Processed by ${refund.processed_by_name || 'Admin'} ${formatDateTime(refund.processed_at)}` : ''}</p>
                      {refund.external_reference && <p className="mt-1 break-all text-xs font-medium text-slate-700">External refund reference: {refund.external_reference}</p>}
                    </div>
                    <StatusBadge status={refund.status} />
                  </div>
                  {refund.status === 'pending' && <button type="button" disabled={refundActionLoading !== null} onClick={() => void handleRefundAction(refund, 'approve_booking_refund')} className="min-h-11 w-full rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 sm:w-auto">Approve full refund</button>}
                  {refund.status === 'approved' && (
                    <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                      <label className="min-w-0 text-xs font-medium text-slate-700">Actual {String(refund.original_payment_method || refund.payment_method).toLowerCase() === 'cash' ? 'Cash receipt/reconciliation' : 'GCash refund transaction'} reference
                        <input value={refundReferences[refund.id] || ''} maxLength={100} onChange={(event) => setRefundReferences((current) => ({ ...current, [refund.id]: event.target.value }))} placeholder="Enter completed external refund reference" className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal" />
                      </label>
                      <button type="button" disabled={refundActionLoading !== null || !String(refundReferences[refund.id] || '').trim()} onClick={() => void handleRefundAction(refund, 'process_booking_refund')} className="min-h-11 self-end rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{refundActionLoading === refund.id ? 'Saving…' : 'Confirm external refund completed'}</button>
                    </div>
                  )}
                </article>
              ))}
            </div>
          </section>
        )}

        <section className="grid gap-4 sm:grid-cols-3">
          <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-sm text-slate-500">Total payments received</p>
            <p className="mt-3 text-2xl font-semibold">{formatAmount(summary.total_paid)}</p>
          </article>
          <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-sm text-slate-500">Pending amount</p>
            <p className="mt-3 text-2xl font-semibold">{formatAmount(summary.pending_amount)}</p>
          </article>
          <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-sm text-slate-500">{isAdmin ? 'Transactions shown' : 'Membership status'}</p>
            {isAdmin
              ? <p className="mt-3 text-2xl font-semibold">{sortedPayments.length.toLocaleString()}</p>
              : <div className="mt-3"><StatusBadge status={membership?.status || 'unknown'} /></div>}
          </article>
        </section>

        {!isAdmin && membership && (
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3"><FaCreditCard className="text-violet-700" /><h2 className="font-semibold">Membership details</h2></div>
            <div className="mt-4 grid gap-4 text-sm sm:grid-cols-3">
              <div><p className="text-slate-500">Type</p><p className="mt-1 font-medium">{latestDailyPass ? '1 Day Pass' : membership.membership_type}</p></div>
              {latestDailyPass ? (
                <>
                  <div><p className="text-slate-500">Pass start date</p><p className="mt-1 font-medium">{formatDate(latestDailyPass.pass_start_date)}</p></div>
                  <div><p className="text-slate-500">Pass end date</p><p className="mt-1 font-medium">{formatDate(latestDailyPass.pass_end_date)}</p></div>
                  <div><p className="text-slate-500">Purchase date</p><p className="mt-1 font-medium">{formatDate(latestDailyPass.purchase_date)}</p></div>
                  <div><p className="text-slate-500">Payment status</p><p className="mt-1 font-medium">{latestDailyPass.display_status}</p></div>
                  <div><p className="text-slate-500">Access eligibility</p><p className="mt-1 font-medium">{latestDailyPass.access_eligible ? 'Eligible for the pass date' : 'Not eligible'}</p></div>
                </>
              ) : (
                <>
                  <div><p className="text-slate-500">Start date</p><p className="mt-1 font-medium">{formatDate(membership.membership_start)}</p></div>
                  <div><p className="text-slate-500">Expiration date</p><p className="mt-1 font-medium">{formatDate(membership.membership_end)}</p></div>
                </>
              )}
            </div>
          </section>
        )}
        {!isAdmin && latestDailyPass?.entitlement_status === 'scheduled' && (
          <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This paid pass is scheduled for {formatDate(latestDailyPass.pass_start_date)}. Gym access is not available before that date.</p>
        )}

        {isAdmin && (
          <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[minmax(220px,1fr)_repeat(4,minmax(130px,auto))]">
            <label className="relative min-w-0">
              <span className="sr-only">Search transactions</span>
              <FaSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Search member or reference" className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-10 pr-3 text-sm" />
            </label>
            <select aria-label="Filter by payment status" value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
              <option value="all">All statuses</option>{paymentStatuses.map((status) => <option key={status} value={status}>{status}</option>)}
            </select>
            <select aria-label="Filter by payment type" value={typeFilter} onChange={(event) => { setTypeFilter(event.target.value); setPage(1) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
              <option value="all">All payment types</option>{paymentTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
            </select>
            <label className="sr-only" htmlFor="payment-date-range">Filter by month</label>
            <input id="payment-date-range" aria-label="Filter by transaction month" type="month" value={dateRange} onChange={(event) => { setDateRange(event.target.value); setPage(1) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
            <select aria-label="Sort by transaction date" value={sortOrder} onChange={(event) => { setSortOrder(event.target.value); setPage(1) }} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
              <option value="newest">Newest first</option><option value="oldest">Oldest first</option>
            </select>
          </section>
        )}

        {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-5 py-3">Date</th>
                  {isAdmin && <th className="px-5 py-3">Member</th>}
                  <th className="px-5 py-3">Type</th><th className="px-5 py-3">Method</th><th className="px-5 py-3">Amount</th><th className="px-5 py-3">Reference / ID</th><th className="px-5 py-3">Status</th><th className="px-5 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr><td colSpan={isAdmin ? 8 : 7} className="px-5 py-10 text-center text-slate-500">Loading payment history…</td></tr>
                ) : error ? (
                  <tr><td colSpan={isAdmin ? 8 : 7} className="px-5 py-10 text-center text-rose-600">Payment history could not be loaded.</td></tr>
                ) : visiblePayments.length === 0 ? (
                  <tr><td colSpan={isAdmin ? 8 : 7} className="px-5 py-10 text-center text-slate-500">No transactions match these filters.</td></tr>
                ) : visiblePayments.map((payment) => (
                  <tr key={`${payment.record_type || 'payment'}-${payment.record_id || payment.id}`} className="text-slate-700">
                    <td className="px-5 py-4">{formatDate(!isAdmin && payment.purchase_date ? payment.purchase_date : payment.payment_date)}{!isAdmin && payment.purchase_date && <p className="mt-1 text-xs text-slate-500">Purchase date</p>}</td>
                    {isAdmin && <td className="px-5 py-4"><p className="font-medium">{payment.member_name || 'Walk-in customer'}</p><p className="text-xs text-slate-500">{payment.member_code ? `${payment.member_code} · ID ${payment.member_id}` : payment.member_id ? `Member ID ${payment.member_id}` : 'No linked member account'}</p></td>}
                    <td className="px-5 py-4">{payment.payment_type === 'walk_in_fee' ? '1 Day Pass (legacy)' : paymentTypes.find((type) => type.value === payment.payment_type)?.label || payment.payment_type}{payment.booking_code && <p className="mt-1 text-xs text-slate-500">Booking {payment.booking_code}</p>}{payment.payment_type === 'monthly_subscription' && <p className="mt-1 text-xs text-slate-500">{Number(payment.duration_months || 1)} month{Number(payment.duration_months || 1) === 1 ? '' : 's'}</p>}{payment.payment_type === 'daily_pass' && <p className="mt-1 text-xs text-slate-500">Pass: {formatDate(payment.pass_start_date)} – {formatDate(payment.pass_end_date)}</p>}{payment.scheduled_start_date && <p className="mt-1 text-xs text-slate-500">Scheduled: {formatDate(payment.scheduled_start_date)} – {formatDate(payment.scheduled_end_date)}</p>}</td>
                    <td className="px-5 py-4 capitalize">{payment.payment_method || 'Legacy'}</td>
                    <td className="px-5 py-4 font-medium">{formatAmount(payment.amount)}</td>
                    <td className="px-5 py-4"><p>{payment.record_reference || payment.id}</p>{payment.reference_no && <p className="mt-1 text-xs text-slate-500">Ref: {payment.reference_no}</p>}</td>
                    <td className="px-5 py-4"><StatusBadge status={payment.status} /><p className="mt-1 text-xs text-slate-600">{payment.display_status || payment.verification_status || 'Legacy'}</p>{payment.refund_status && <p className="mt-1 text-xs font-medium text-violet-800">Refund: {payment.refund_status} · {formatAmount(payment.refund_amount)}</p>}{payment.record_type === 'daily_pass' && (payment.verified_by_name || payment.recorded_by_name) && <p className="mt-1 text-xs text-slate-500">{payment.verified_by_name ? `Verified by ${payment.verified_by_name}` : `Received by ${payment.recorded_by_name}`}</p>}</td>
                    <td className="px-5 py-4 text-right"><button type="button" onClick={() => setSelectedPayment(payment)} className="font-medium text-violet-700 hover:underline">View details</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!loading && !error && sortedPayments.length > 0 && (
            <footer className="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-xs text-slate-500">Showing {(currentPage - 1) * PAGE_SIZE + 1}–{Math.min(currentPage * PAGE_SIZE, sortedPayments.length)} of {sortedPayments.length} transactions</p>
              <div className="flex items-center justify-end gap-2">
                <button type="button" disabled={currentPage <= 1} onClick={() => setPage((current) => current - 1)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40">Previous</button>
                <span className="px-2 text-xs text-slate-600">Page {currentPage} of {pageCount}</span>
                <button type="button" disabled={currentPage >= pageCount} onClick={() => setPage((current) => current + 1)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40">Next</button>
              </div>
            </footer>
          )}
        </section>
      </main>

      {showMemberUpgrade && membership && (
        <MemberMembershipUpgrade
          member={membership}
          onClose={() => setShowMemberUpgrade(false)}
          onSubmitted={(message) => { setShowMemberUpgrade(false); setNotice(message); fetchPayments() }}
        />
      )}
      {selectedPayment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" onClick={() => setSelectedPayment(null)}>
          <section role="dialog" aria-modal="true" aria-labelledby="payment-details-title" className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-6" onClick={(event) => event.stopPropagation()}>
            <h2 id="payment-details-title" className="text-lg font-semibold">Transaction details</h2>
            <dl className="mt-4 grid gap-x-6 sm:grid-cols-2">
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Member</dt><dd className="mt-1 text-sm font-medium">{selectedPayment.member_name || 'You'}{selectedPayment.member_id ? ` · ID ${selectedPayment.member_id}` : ''}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Transaction ID</dt><dd className="mt-1 text-sm font-medium">{selectedPayment.record_reference || selectedPayment.id}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Amount</dt><dd className="mt-1 text-sm font-medium">{formatAmount(selectedPayment.amount)}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Type</dt><dd className="mt-1 text-sm font-medium">{paymentTypes.find((type) => type.value === selectedPayment.payment_type)?.label || selectedPayment.payment_type}</dd></div>
              {selectedPayment.payment_type === 'monthly_subscription' && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Duration</dt><dd className="mt-1 text-sm font-medium">{Number(selectedPayment.duration_months || 1)} month{Number(selectedPayment.duration_months || 1) === 1 ? '' : 's'}</dd></div>}
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Method</dt><dd className="mt-1 text-sm font-medium capitalize">{selectedPayment.payment_method || 'Legacy'}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Payment date</dt><dd className="mt-1 text-sm font-medium">{formatDate(selectedPayment.payment_date)}</dd></div>
              {selectedPayment.purchase_date && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Purchase/submission date</dt><dd className="mt-1 text-sm font-medium">{formatDateTime(selectedPayment.purchase_date)}</dd></div>}
              {selectedPayment.booking_code && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Booking</dt><dd className="mt-1 text-sm font-medium">{selectedPayment.booking_code} · ID {selectedPayment.booking_id}</dd></div>}
              {selectedPayment.payment_type === 'training_session' && selectedPayment.refund_status && <div className="border-b border-slate-100 py-3 sm:col-span-2"><dt className="text-xs text-slate-500">Separate refund transaction</dt><dd className="mt-1 text-sm font-medium">{formatAmount(selectedPayment.refund_amount)} · {selectedPayment.refund_status} · {String(selectedPayment.refund_method || selectedPayment.payment_method || '').toUpperCase()}</dd>{selectedPayment.refund_reference && <dd className="mt-1 break-all text-xs text-slate-600">External reference: {selectedPayment.refund_reference}</dd>}{selectedPayment.refund_processed_at && <dd className="mt-1 text-xs text-slate-600">Processed: {formatDateTime(selectedPayment.refund_processed_at)}</dd>}</div>}
              {selectedPayment.scheduled_start_date && <div className="border-b border-slate-100 py-3 sm:col-span-2"><dt className="text-xs text-slate-500">Scheduled membership period</dt><dd className="mt-1 text-sm font-medium">{formatDate(selectedPayment.scheduled_start_date)} – {formatDate(selectedPayment.scheduled_end_date)}</dd></div>}
              {selectedPayment.pass_start_date && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Pass start date</dt><dd className="mt-1 text-sm font-medium">{formatDate(selectedPayment.pass_start_date)}</dd></div>}
              {selectedPayment.pass_end_date && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Pass end date</dt><dd className="mt-1 text-sm font-medium">{formatDate(selectedPayment.pass_end_date)}</dd></div>}
              {selectedPayment.member_discount_status && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Discount verification</dt><dd className="mt-1 text-sm font-medium capitalize">{selectedPayment.member_discount_status}</dd></div>}
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Reference</dt><dd className="mt-1 break-all text-sm font-medium">{selectedPayment.reference_no || '—'}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Status</dt><dd className="mt-1 flex items-center gap-2 text-sm"><StatusBadge status={selectedPayment.status} />{selectedPayment.display_status || selectedPayment.verification_status || 'Legacy'}</dd></div>
              <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Verification</dt><dd className="mt-1 text-sm font-medium capitalize">{selectedPayment.verification_status || 'Legacy'}</dd></div>
              {selectedPayment.recorded_by_name && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Received by</dt><dd className="mt-1 text-sm font-medium">{selectedPayment.recorded_by_name}</dd></div>}
              {selectedPayment.verified_by_name && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Verified by</dt><dd className="mt-1 text-sm font-medium">{selectedPayment.verified_by_name}</dd></div>}
              {selectedPayment.verified_at && <div className="border-b border-slate-100 py-3"><dt className="text-xs text-slate-500">Verification date</dt><dd className="mt-1 text-sm font-medium">{formatDateTime(selectedPayment.verified_at)}</dd></div>}
            </dl>
            {isAdmin && selectedPayment.payment_method === 'gcash' && selectedPayment.proof_original_name && (
              <button type="button" disabled={proofDownloadLoading} onClick={downloadPaymentProof} className="mt-5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 disabled:opacity-50">
                {proofDownloadLoading ? 'Preparing receipt…' : 'Download GCash receipt'}
              </button>
            )}
            {selectedIsPendingMonthlyPayment && (
              <div className="mt-5 flex flex-wrap gap-2">
                {selectedPayment.payment_method === 'cash' ? (
                  <button type="button" disabled={paymentReviewLoading} onClick={() => setPaymentReviewConfirmation({ decision: 'cash' })} className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    Confirm cash received
                  </button>
                ) : (
                  <>
                    <button type="button" disabled={paymentReviewLoading || !selectedPayment.proof_original_name} onClick={() => setPaymentReviewConfirmation({ decision: 'verified' })} className="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                      Verify GCash receipt
                    </button>
                    <button type="button" disabled={paymentReviewLoading} onClick={() => setPaymentReviewConfirmation({ decision: 'rejected' })} className="rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50">
                      Reject GCash payment
                    </button>
                  </>
                )}
              </div>
            )}
            {selectedIsPendingBookingPayment && (
              <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p className="font-semibold">Booking Payment Verification</p>
                <p className="mt-0.5">Booking payment verification and GCash reviews are managed in Member Management under the <strong>Booking Payments</strong> tab.</p>
                <Link to="/members?tab=booking-payments" className="mt-2 inline-block font-semibold text-cyan-800 hover:underline">
                  Go to Booking Payments →
                </Link>
              </div>
            )}
            <button type="button" onClick={() => setSelectedPayment(null)} className="mt-5 rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white">Close</button>
          </section>
        </div>
      )}
      {paymentReviewConfirmation && selectedPayment && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/60 p-4">
          <section role="dialog" aria-modal="true" aria-labelledby="payment-review-title" className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
            <h2 id="payment-review-title" className="text-lg font-semibold text-slate-900">
              {selectedPayment.payment_type === 'training_session'
                ? paymentReviewConfirmation.decision === 'verified'
                  ? selectedPayment.payment_method === 'cash' ? 'Confirm booking cash receipt' : 'Verify booking GCash payment'
                  : 'Reject booking GCash payment'
                : paymentReviewConfirmation.decision === 'cash'
                ? 'Confirm cash receipt'
                : paymentReviewConfirmation.decision === 'verified'
                  ? 'Verify GCash payment'
                  : 'Reject GCash payment'}
            </h2>
            <p className="mt-2 text-sm text-slate-600">
              {selectedPayment.payment_type === 'training_session'
                ? paymentReviewConfirmation.decision === 'verified'
                  ? 'Confirm only after staff has received the exact booking amount. The booking will then be confirmed.'
                  : 'Reject this GCash proof. The booking remains payable only until its original deadline.'
                : paymentReviewConfirmation.decision === 'cash'
                ? 'Confirm only after staff has physically received the cash. This activates or schedules the monthly subscription.'
                : paymentReviewConfirmation.decision === 'verified'
                  ? 'Confirm that the submitted GCash receipt matches a received payment. This activates or schedules the monthly subscription.'
                  : 'Reject this GCash payment. The monthly subscription will not be activated.'}
            </p>
            {error && <p role="alert" className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" disabled={paymentReviewLoading} onClick={() => setPaymentReviewConfirmation(null)} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 disabled:opacity-50">Cancel</button>
              <button type="button" disabled={paymentReviewLoading} onClick={handleMonthlyPaymentReview} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                {paymentReviewLoading ? 'Saving…' : 'Confirm review'}
              </button>
            </div>
          </section>
        </div>
      )}
    </div>
  )
}
