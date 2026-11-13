import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  FaCheck,
  FaClock,
  FaDownload,
  FaFileInvoiceDollar,
  FaMoneyBillWave,
  FaRedo,
  FaSearch,
  FaTimes,
  FaUserCheck,
} from 'react-icons/fa'
import api from '../services/api'
import StatusBadge from './ui/StatusBadge.jsx'
import ConfirmationModal from './ui/ConfirmationModal.jsx'
import { useFeedback } from './ui/FeedbackContext.js'

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

export default function BookingPaymentsTab({ onCountChange }) {
  const { showSuccess, showRequestError } = useFeedback()
  const [payments, setPayments] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('pending')
  const [methodFilter, setMethodFilter] = useState('all')
  const [reviewConfirmation, setReviewConfirmation] = useState(null)
  const [reviewLoading, setReviewLoading] = useState(false)
  const [proofLoadingId, setProofLoadingId] = useState(null)
  const [previewProof, setPreviewProof] = useState(null)
  const [loadingPreview, setLoadingPreview] = useState(false)

  const fetchBookingPayments = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/payments.php', {
        params: { payment_type: 'training_session' },
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to load booking payments.')
      }
      const list = response.data.payments || []
      setPayments(list)
      const pendingCount = list.filter((p) => p.status === 'pending' && p.verification_status === 'pending').length
      onCountChange?.(pendingCount)
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Unable to load booking payments.')
    } finally {
      setLoading(false)
    }
  }, [onCountChange])

  useEffect(() => {
    let active = true
    api.get('/payments.php', { params: { payment_type: 'training_session' } })
      .then((response) => {
        if (!active) return
        if (response.data?.success) {
          const list = response.data.payments || []
          setPayments(list)
          const count = list.filter((p) => p.status === 'pending' && p.verification_status === 'pending').length
          onCountChange?.(count)
        }
      })
      .catch((err) => {
        if (!active) return
        setError(err?.response?.data?.message || err?.message || 'Unable to load booking payments.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [onCountChange])

  const filteredPayments = useMemo(() => {
    return payments.filter((item) => {
      const query = search.trim().toLowerCase()
      const matchesSearch = !query ||
        String(item.booking_code || '').toLowerCase().includes(query) ||
        String(item.member_name || '').toLowerCase().includes(query) ||
        String(item.member_code || '').toLowerCase().includes(query) ||
        String(item.trainer_name || '').toLowerCase().includes(query) ||
        String(item.reference_no || '').toLowerCase().includes(query)

      const matchesStatus = statusFilter === 'all'
        ? true
        : statusFilter === 'pending'
          ? item.status === 'pending' && item.verification_status === 'pending'
          : statusFilter === 'paid'
            ? item.status === 'paid' && item.verification_status === 'verified'
            : item.status === 'failed' || item.verification_status === 'rejected'

      const matchesMethod = methodFilter === 'all'
        ? true
        : String(item.payment_method || '').toLowerCase() === methodFilter

      return matchesSearch && matchesStatus && matchesMethod
    })
  }, [payments, search, statusFilter, methodFilter])

  const pendingCount = useMemo(() => {
    return payments.filter((p) => p.status === 'pending' && p.verification_status === 'pending').length
  }, [payments])

  const handleReview = async () => {
    if (!reviewConfirmation || reviewLoading) return
    const { payment, decision } = reviewConfirmation
    setReviewLoading(true)
    try {
      const payload = new FormData()
      payload.append('action', 'review_booking_payment')
      payload.append('payment_id', String(payment.id))
      payload.append('decision', decision)

      const response = await api.post('/payments.php', payload)
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to process review.')
      }
      showSuccess(response.data.message || (decision === 'verified' ? 'Payment confirmed.' : 'Payment rejected.'))
      setReviewConfirmation(null)
      await fetchBookingPayments()
    } catch (err) {
      showRequestError(err, 'Unable to review booking payment.', 'Review failed')
    } finally {
      setReviewLoading(false)
    }
  }

  const downloadProof = async (payment) => {
    if (proofLoadingId) return
    setProofLoadingId(payment.id)
    try {
      const response = await api.get('/payment_proof.php', {
        params: { id: payment.id },
        responseType: 'blob',
      })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = `booking-payment-proof-${payment.booking_code || payment.id}`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (err) {
      showRequestError(err, 'Unable to download GCash receipt.', 'Download failed')
    } finally {
      setProofLoadingId(null)
    }
  }

  const openPreview = async (payment) => {
    setLoadingPreview(true)
    setPreviewProof({ payment, url: null, mimeType: null })
    try {
      const response = await api.get('/payment_proof.php', {
        params: { id: payment.id },
        responseType: 'blob',
      })
      const mime = response.data.type || 'image/jpeg'
      const url = URL.createObjectURL(response.data)
      setPreviewProof({ payment, url, mimeType: mime })
    } catch (err) {
      showRequestError(err, 'Unable to preview receipt.', 'Preview failed')
      setPreviewProof(null)
    } finally {
      setLoadingPreview(false)
    }
  }

  const closePreview = () => {
    if (previewProof?.url) {
      URL.revokeObjectURL(previewProof.url)
    }
    setPreviewProof(null)
  }

  return (
    <div className="space-y-6">
      {/* Header and Stats */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold text-slate-900">Booking Payments Verification</h2>
          <p className="mt-0.5 text-xs text-slate-500">
            Verify member payments for personal training and coaching sessions independently from membership dues.
          </p>
        </div>
        <button
          type="button"
          onClick={fetchBookingPayments}
          disabled={loading}
          className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
        >
          <FaRedo className={`text-xs ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {error && (
        <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
          {error}
        </div>
      )}

      {/* Filter / Search Bar */}
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="relative min-w-0 flex-1 sm:min-w-64">
          <FaSearch className="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search booking ref, member, trainer, reference..."
            className="w-full rounded-xl border border-slate-200 bg-slate-50/50 py-2 pl-9 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-cyan-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20"
          />
        </div>

        <div className="flex items-center gap-2">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-cyan-500/20"
          >
            <option value="pending">Pending Review ({pendingCount})</option>
            <option value="paid">Verified / Paid</option>
            <option value="failed">Rejected / Failed</option>
            <option value="all">All Payments</option>
          </select>

          <select
            value={methodFilter}
            onChange={(e) => setMethodFilter(e.target.value)}
            className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-cyan-500/20"
          >
            <option value="all">All Payment Methods</option>
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
          </select>
        </div>
      </div>

      {/* Payments List / Table */}
      {loading ? (
        <div className="rounded-2xl border border-slate-200 bg-white p-12 text-center text-sm text-slate-500">
          Loading booking payment records...
        </div>
      ) : filteredPayments.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
          <FaFileInvoiceDollar className="mx-auto text-3xl text-slate-300" />
          <p className="mt-3 text-sm font-semibold text-slate-700">No booking payments found</p>
          <p className="mt-1 text-xs text-slate-400">
            {statusFilter === 'pending'
              ? 'No pending training session payments require review right now.'
              : 'Try clearing search filters to see older payment history.'}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {filteredPayments.map((item) => {
            const isPending = item.status === 'pending' && item.verification_status === 'pending'
            const isCash = item.payment_method === 'cash'
            const isGcash = item.payment_method === 'gcash'
            const hasProof = Boolean(item.proof_original_name)

            return (
              <article
                key={item.id}
                className={`rounded-2xl border p-4 sm:p-5 transition shadow-2xs ${
                  isPending
                    ? 'border-amber-200 bg-amber-50/20'
                    : 'border-slate-200 bg-white'
                }`}
              >
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                  {/* Left Column: Booking & Member Details */}
                  <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-mono text-xs font-bold text-violet-700">
                        {item.booking_code || `Booking #${item.booking_id}`}
                      </span>
                      <span className="text-slate-300">·</span>
                      <StatusBadge status={item.status} />
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-slate-600">
                        {item.payment_method}
                      </span>
                      {isPending && (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                          Awaiting Verification
                        </span>
                      )}
                    </div>

                    <div className="grid gap-2 text-xs sm:grid-cols-2 md:grid-cols-3">
                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Member</span>
                        <p className="font-semibold text-slate-800">{item.member_name}</p>
                        <p className="font-mono text-[10px] text-slate-500">{item.member_code}</p>
                      </div>

                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Assigned Trainer</span>
                        <p className="font-semibold text-slate-800">{item.trainer_name || 'Unassigned'}</p>
                      </div>

                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Session Date & Time</span>
                        <p className="font-semibold text-slate-800">
                          {formatDate(item.booking_date)}
                        </p>
                        <p className="text-slate-500">
                          {item.booking_start_time} – {item.booking_end_time} ({item.booking_duration_minutes || 60}m)
                        </p>
                      </div>

                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Session Types</span>
                        <p className="font-medium text-slate-700">
                          {item.booking_session_type || 'Personal Training'}
                        </p>
                      </div>

                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Pricing Breakdown</span>
                        <p className="text-slate-600">
                          Subtotal: <span className="font-medium">{formatAmount(item.booking_subtotal || item.amount)}</span>
                        </p>
                        {Number(item.booking_credit_amount) > 0 && (
                          <p className="text-emerald-700">
                            Credit: -{formatAmount(item.booking_credit_amount)}
                          </p>
                        )}
                        <p className="font-bold text-slate-900">
                          Due: {formatAmount(item.amount)}
                        </p>
                      </div>

                      <div>
                        <span className="block text-[11px] font-medium text-slate-400">Payment Deadline</span>
                        <p className="flex items-center gap-1 font-medium text-slate-700">
                          <FaClock className="text-slate-400 text-[10px]" />
                          {item.booking_payment_deadline_at ? formatDateTime(item.booking_payment_deadline_at) : '—'}
                        </p>
                      </div>
                    </div>

                    {/* Verification info if completed */}
                    {item.verified_by_name && (
                      <p className="text-[11px] text-slate-400 flex items-center gap-1.5 pt-1">
                        <FaUserCheck className="text-slate-400 text-xs" />
                        Verified by {item.verified_by_name} on {formatDateTime(item.verified_at)}
                      </p>
                    )}
                  </div>

                  {/* Right Column: Actions & Proof */}
                  <div className="flex shrink-0 flex-col items-start gap-2.5 border-t border-slate-100 pt-3 lg:border-t-0 lg:border-l lg:pl-5 lg:pt-0">
                    {/* GCash Proof actions */}
                    {isGcash && (
                      <div className="flex items-center gap-2">
                        {hasProof ? (
                          <>
                            <button
                              type="button"
                              onClick={() => openPreview(item)}
                              className="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-100 transition"
                            >
                              View Receipt
                            </button>
                            <button
                              type="button"
                              onClick={() => downloadProof(item)}
                              disabled={proofLoadingId === item.id}
                              className="rounded-lg border border-slate-200 bg-white p-1.5 text-xs text-slate-600 hover:bg-slate-50 transition"
                              title="Download Proof"
                            >
                              <FaDownload className={proofLoadingId === item.id ? 'animate-bounce' : ''} />
                            </button>
                          </>
                        ) : (
                          <span className="text-xs text-slate-400 italic">No proof uploaded</span>
                        )}
                      </div>
                    )}

                    {/* Pending Verification Actions */}
                    {isPending && (
                      <div className="flex items-center gap-2">
                        {isCash && (
                          <button
                            type="button"
                            onClick={() => setReviewConfirmation({ payment: item, decision: 'verified' })}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 transition"
                          >
                            <FaMoneyBillWave />
                            Confirm Cash Received
                          </button>
                        )}

                        {isGcash && (
                          <>
                            <button
                              type="button"
                              disabled={!hasProof}
                              onClick={() => setReviewConfirmation({ payment: item, decision: 'verified' })}
                              className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 transition disabled:opacity-50"
                            >
                              <FaCheck />
                              Approve GCash
                            </button>
                            <button
                              type="button"
                              onClick={() => setReviewConfirmation({ payment: item, decision: 'rejected' })}
                              className="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 transition"
                            >
                              <FaTimes />
                              Reject
                            </button>
                          </>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      )}

      {/* Confirmation Modal */}
      {reviewConfirmation && (
        <ConfirmationModal
          open={Boolean(reviewConfirmation)}
          title={
            reviewConfirmation.decision === 'verified'
              ? reviewConfirmation.payment.payment_method === 'cash'
                ? 'Confirm Booking Cash Receipt'
                : 'Approve Booking GCash Payment'
              : 'Reject Booking GCash Payment'
          }
          message={
            reviewConfirmation.decision === 'verified'
              ? reviewConfirmation.payment.payment_method === 'cash'
                ? `Confirm cash receipt of ${formatAmount(reviewConfirmation.payment.amount)} for booking ${reviewConfirmation.payment.booking_code}? This will confirm the booking.`
                : `Approve the submitted GCash receipt of ${formatAmount(reviewConfirmation.payment.amount)} for booking ${reviewConfirmation.payment.booking_code}? This will confirm the booking.`
              : `Reject this GCash payment proof for booking ${reviewConfirmation.payment.booking_code}? The member may submit a replacement proof prior to the payment deadline.`
          }
          confirmText={reviewConfirmation.decision === 'verified' ? 'Confirm Payment' : 'Reject Proof'}
          variant={reviewConfirmation.decision === 'verified' ? 'default' : 'danger'}
          loading={reviewLoading}
          loadingText="Processing..."
          onCancel={() => setReviewConfirmation(null)}
          onConfirm={handleReview}
        />
      )}

      {/* Proof Preview Modal */}
      {previewProof && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
          onClick={closePreview}
        >
          <div
            className="w-full max-w-2xl rounded-2xl bg-white p-5 shadow-2xl space-y-4 max-h-[90vh] flex flex-col"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="font-semibold text-slate-800 text-sm">
                  GCash Payment Proof · {previewProof.payment.booking_code}
                </h3>
                <p className="text-xs text-slate-500">
                  {previewProof.payment.proof_original_name || 'Receipt document'}
                </p>
              </div>
              <button
                type="button"
                onClick={closePreview}
                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
              >
                <FaTimes />
              </button>
            </div>

            <div className="flex-1 overflow-auto rounded-xl border border-slate-200 bg-slate-50 p-2 flex items-center justify-center min-h-64">
              {loadingPreview ? (
                <p className="text-xs text-slate-400">Loading receipt preview...</p>
              ) : previewProof.mimeType === 'application/pdf' ? (
                <iframe
                  title="Payment Proof PDF"
                  src={previewProof.url}
                  className="h-96 w-full rounded-lg border-0"
                />
              ) : previewProof.url ? (
                <img
                  src={previewProof.url}
                  alt="Payment Proof"
                  className="max-h-[60vh] max-w-full rounded-lg object-contain"
                />
              ) : (
                <p className="text-xs text-slate-400">Unable to load receipt preview.</p>
              )}
            </div>

            <div className="flex items-center justify-between pt-2 border-t border-slate-100">
              <button
                type="button"
                onClick={() => downloadProof(previewProof.payment)}
                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              >
                <FaDownload className="text-xs" />
                Download Original
              </button>
              <button
                type="button"
                onClick={closePreview}
                className="rounded-xl bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
