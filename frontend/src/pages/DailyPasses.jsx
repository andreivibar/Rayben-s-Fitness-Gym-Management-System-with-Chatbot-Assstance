import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import QRCode from 'react-qr-code'
import {
  FaArrowLeft,
  FaChevronLeft,
  FaChevronRight,
  FaPlus,
  FaSearch,
  FaSyncAlt,
  FaTimes,
} from 'react-icons/fa'
import api from '../services/api.js'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import ReasonDialog from '../components/ui/ReasonDialog.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'

const PAGE_SIZE = 10
const EMPTY_FILTERS = {
  search: '',
  paymentStatus: 'all',
  verificationStatus: 'all',
  paymentMethod: 'all',
  visitDate: '',
}

function createRequestKey() {
  return crypto.randomUUID()
}

function getManilaDate() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function formatDate(value, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
  if (!value) return '—'
  const raw = String(value)
  const date = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  if (Number.isNaN(date.getTime())) return raw
  return new Intl.DateTimeFormat('en-PH', { ...options, timeZone: 'Asia/Manila' }).format(date)
}

function verificationStatus(pass) {
  if (pass.payment_status === 'paid') return 'verified'
  if (pass.payment_status === 'rejected') return 'rejected'
  if (pass.payment_status === 'unpaid') return 'awaiting confirmation'
  if (pass.payment_status === 'pending') return 'pending verification'
  return 'not available'
}

function verificationBadge(status) {
  if (status === 'verified') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (status === 'rejected') return 'border-rose-200 bg-rose-50 text-rose-700'
  if (status === 'pending verification' || status === 'awaiting confirmation') {
    return 'border-amber-200 bg-amber-50 text-amber-700'
  }
  return 'border-slate-200 bg-slate-100 text-slate-600'
}

function MetricCard({ label, value, tone = 'slate' }) {
  const tones = {
    slate: 'border-slate-200 bg-white text-slate-900',
    amber: 'border-amber-200 bg-amber-50/60 text-amber-800',
    green: 'border-emerald-200 bg-emerald-50/60 text-emerald-800',
    red: 'border-rose-200 bg-rose-50/60 text-rose-800',
  }
  return (
    <article className={`rounded-2xl border p-4 shadow-sm sm:p-5 ${tones[tone]}`}>
      <p className="text-xs font-semibold uppercase tracking-wide opacity-75">{label}</p>
      <p className="mt-2 text-2xl font-bold">{value}</p>
    </article>
  )
}

function DetailRow({ label, children }) {
  return (
    <div className="grid gap-1 border-b border-slate-100 py-3 last:border-0 sm:grid-cols-[150px_1fr] sm:gap-4">
      <dt className="text-xs font-medium text-slate-500">{label}</dt>
      <dd className="break-words text-sm font-medium text-slate-800">{children || '—'}</dd>
    </div>
  )
}

export default function DailyPasses({ embedded = false }) {
  const { showSuccess, showRequestError } = useFeedback()
  const [passes, setPasses] = useState([])
  const [form, setForm] = useState({ customer_name: '', customer_phone: '' })
  const [cashKey, setCashKey] = useState(createRequestKey)
  const [selectedPass, setSelectedPass] = useState(null)
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [cashModalOpen, setCashModalOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [processing, setProcessing] = useState('')
  const [proofUrl, setProofUrl] = useState('')
  const [proofType, setProofType] = useState('')
  const [proofLoading, setProofLoading] = useState(false)
  const [proofError, setProofError] = useState('')
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [rejectingPass, setRejectingPass] = useState(null)
  const [reviewConfirmation, setReviewConfirmation] = useState(null)

  const loadPasses = useCallback(async () => {
    setError('')
    try {
      const response = await api.get('/daily_passes.php')
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load 1 Day Pass transactions.')
      const uniquePasses = new Map()
      for (const pass of response.data.daily_passes || []) uniquePasses.set(String(pass.id), pass)
      const nextPasses = [...uniquePasses.values()]
      setPasses(nextPasses)
      return nextPasses
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load 1 Day Pass transactions.')
      return null
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { Promise.resolve().then(loadPasses) }, [loadPasses])

  const selectedPassId = selectedPass?.id
  const selectedProofName = selectedPass?.proof_original_name

  useEffect(() => {
    if (!selectedPassId || !selectedProofName) return undefined

    let active = true
    let objectUrl = ''
    const controller = new AbortController()
    api.get(`/daily_pass_proof.php?id=${encodeURIComponent(selectedPassId)}`, {
      responseType: 'blob',
      signal: controller.signal,
    })
      .then((response) => {
        if (!active) return
        objectUrl = URL.createObjectURL(response.data)
        setProofUrl(objectUrl)
        setProofType(response.data.type || '')
      })
      .catch((requestError) => {
        if (active) setProofError(requestError?.response?.data?.message || 'Unable to retrieve payment proof.')
      })
      .finally(() => {
        if (active) setProofLoading(false)
      })
    return () => {
      active = false
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [selectedPassId, selectedProofName])

  const filteredPasses = useMemo(() => {
    const search = filters.search.trim().toLowerCase()
    return passes.filter((pass) => {
      const id = String(pass.id || '')
      const idLabel = `dp-${id}`
      const numericIdSearch = search.replace(/^dp-?/, '')
      const searchMatches = !search
        || String(pass.customer_name || '').toLowerCase().includes(search)
        || String(pass.member_code || '').toLowerCase().includes(search)
        || idLabel.includes(search)
        || (numericIdSearch !== '' && id.includes(numericIdSearch))
      return searchMatches
        && (filters.paymentStatus === 'all' || pass.payment_status === filters.paymentStatus)
        && (filters.verificationStatus === 'all' || verificationStatus(pass) === filters.verificationStatus)
        && (filters.paymentMethod === 'all' || pass.payment_method === filters.paymentMethod)
        && (!filters.visitDate || pass.visit_date === filters.visitDate)
    })
  }, [passes, filters])

  const summary = useMemo(() => ({
    total: passes.length,
    pending: passes.filter((pass) => ['pending', 'unpaid'].includes(pass.payment_status)).length,
    verified: passes.filter((pass) => pass.payment_status === 'paid').length,
    rejected: passes.filter((pass) => pass.payment_status === 'rejected').length,
  }), [passes])

  const pageCount = Math.max(1, Math.ceil(filteredPasses.length / PAGE_SIZE))
  const currentPage = Math.min(page, pageCount)
  const visiblePasses = filteredPasses.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  const changeFilter = (name, value) => {
    setFilters((current) => ({ ...current, [name]: value }))
    setPage(1)
  }

  const clearFilters = () => {
    setFilters(EMPTY_FILTERS)
    setPage(1)
  }

  const openPassDetails = (pass) => {
    setProofUrl('')
    setProofType('')
    setProofError('')
    setProofLoading(Boolean(pass.proof_original_name))
    setSelectedPass(pass)
  }

  const recordCash = async (event) => {
    event.preventDefault()
    if (processing === 'cash') return
    setProcessing('cash')
    setFormError('')
    setError('')
    try {
      const response = await api.post('/daily_passes.php', {
        action: 'record_cash',
        customer_name: form.customer_name.trim(),
        customer_phone: form.customer_phone,
        idempotency_key: cashKey,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to record cash payment.')
      showSuccess(`₱100 cash purchase recorded. Transaction DP-${response.data.pass_id}.`)
      setForm({ customer_name: '', customer_phone: '' })
      setCashKey(createRequestKey())
      setCashModalOpen(false)
      setPage(1)
      await loadPasses()
    } catch (requestError) {
      showRequestError(requestError, 'Unable to record the cash purchase. Check the transaction list before retrying.', 'Cash purchase failed')
    } finally {
      setProcessing('')
    }
  }

  const reviewPayment = async (pass, action) => {
    if (processing) return
    if (action === 'reject') {
      setRejectingPass(pass)
      return
    }
    setReviewConfirmation({ pass, action, note: '' })
  }

  const confirmPaymentRejection = (note) => {
    if (!rejectingPass) return
    setReviewConfirmation({ pass: rejectingPass, action: 'reject', note })
    setRejectingPass(null)
  }

  const submitReview = async (pass, action, note) => {
    if (processing) return false
    setProcessing(String(pass.id))
    setError('')
    try {
      const response = await api.post('/daily_passes.php', { action, id: Number(pass.id), note })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to review payment.')
      showSuccess(response.data.message || 'Payment review saved.')
      setSelectedPass((current) => current && String(current.id) === String(pass.id)
        ? { ...current, payment_status: response.data.payment_status }
        : current)
      const refreshedPasses = await loadPasses()
      const refreshedPass = refreshedPasses?.find((item) => String(item.id) === String(pass.id))
      if (refreshedPass) setSelectedPass(refreshedPass)
      setReviewConfirmation(null)
      return true
    } catch (requestError) {
      showRequestError(requestError, 'Unable to review this payment. Check its current status before retrying.', 'Payment review failed')
      return false
    } finally {
      setProcessing('')
    }
  }

  const verificationLabel = verificationStatus(selectedPass || {})
  const Container = embedded ? 'section' : 'main'

  return (
    <Container className={`${embedded ? '' : 'min-h-screen '}bg-slate-100 px-4 py-6 text-slate-900 sm:px-6`}>
      <div className="mx-auto max-w-7xl space-y-5">
        {!embedded && (
          <header className="flex items-center gap-4">
            <Link to="/payments" className="rounded-xl border border-slate-200 bg-white p-3 text-slate-700" aria-label="Back to payments"><FaArrowLeft /></Link>
            <div><p className="text-xs font-semibold uppercase tracking-widest text-sky-700">Cashier</p><h1 className="text-2xl font-bold">1 Day Passes</h1></div>
          </header>
        )}

        <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          <MetricCard label="Total Transactions" value={summary.total} />
          <MetricCard label="Pending Verification" value={summary.pending} tone="amber" />
          <MetricCard label="Verified Payments" value={summary.verified} tone="green" />
          <MetricCard label="Rejected Transactions" value={summary.rejected} tone="red" />
        </section>

        {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <header className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div>
              <h2 className="text-base font-semibold text-slate-900">Transaction history</h2>
              <p className="mt-1 text-xs text-slate-500">Review payment status, verify submissions, and manage Daily Pass purchases.</p>
            </div>
            <div className="flex shrink-0 gap-2">
              <button type="button" onClick={loadPasses} disabled={loading} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" aria-label="Refresh transactions">
                <FaSyncAlt className={loading ? 'animate-spin' : ''} /><span className="hidden sm:inline">Refresh</span>
              </button>
              <button type="button" onClick={() => { setFormError(''); setCashModalOpen(true) }} className="inline-flex items-center gap-2 rounded-xl bg-sky-700 px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-300 sm:px-4">
                <FaPlus /><span>Record Cash Purchase</span>
              </button>
            </div>
          </header>

          <div className="space-y-3 border-b border-slate-100 bg-slate-50/60 p-4 sm:p-5">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              <label className="relative sm:col-span-2 xl:col-span-1">
                <span className="sr-only">Search member or transaction</span>
                <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" />
                <input type="search" value={filters.search} onChange={(event) => changeFilter('search', event.target.value)} placeholder="Member or transaction ID" className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-400" />
              </label>
              <label>
                <span className="sr-only">Filter by payment status</span>
                <select value={filters.paymentStatus} onChange={(event) => changeFilter('paymentStatus', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-sky-400">
                  <option value="all">All payment statuses</option><option value="pending">Pending</option><option value="unpaid">Unpaid</option><option value="paid">Paid</option><option value="rejected">Rejected</option>
                </select>
              </label>
              <label>
                <span className="sr-only">Filter by verification status</span>
                <select value={filters.verificationStatus} onChange={(event) => changeFilter('verificationStatus', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-sky-400">
                  <option value="all">All verification states</option><option value="pending verification">Pending verification</option><option value="awaiting confirmation">Awaiting cash confirmation</option><option value="verified">Verified</option><option value="rejected">Rejected</option>
                </select>
              </label>
              <label>
                <span className="sr-only">Filter by payment method</span>
                <select value={filters.paymentMethod} onChange={(event) => changeFilter('paymentMethod', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-sky-400">
                  <option value="all">All payment methods</option><option value="cash">Cash</option><option value="gcash">GCash</option>
                </select>
              </label>
              <label>
                <span className="sr-only">Filter by access date</span>
                <input type="date" value={filters.visitDate} onChange={(event) => changeFilter('visitDate', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-sky-400" />
              </label>
            </div>
            <div className="flex items-center justify-between gap-3">
              <p className="text-xs text-slate-500">{filteredPasses.length} {filteredPasses.length === 1 ? 'transaction' : 'transactions'}</p>
              <button type="button" onClick={clearFilters} disabled={Object.entries(EMPTY_FILTERS).every(([key, value]) => filters[key] === value)} className="text-xs font-semibold text-sky-700 hover:text-sky-900 disabled:cursor-default disabled:text-slate-400">Clear filters</button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full table-auto text-left text-sm">
              <thead className="bg-white text-[10px] uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="w-[18%] px-3 py-3 sm:px-5">Transaction / Member</th>
                  <th className="hidden w-[14%] px-3 py-3 md:table-cell">Access date</th>
                  <th className="hidden w-[10%] px-3 py-3 lg:table-cell">Method</th>
                  <th className="w-[15%] px-2 py-3 sm:px-3">Amount / Payment</th>
                  <th className="hidden w-[16%] px-3 py-3 sm:table-cell">Verification</th>
                  <th className="hidden w-[14%] px-3 py-3 xl:table-cell">Submitted</th>
                  <th className="w-[18%] px-2 py-3 text-right sm:px-5">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr><td colSpan="7" className="px-5 py-12 text-center text-sm text-slate-500">Loading transactions…</td></tr>
                ) : visiblePasses.length === 0 ? (
                  <tr><td colSpan="7" className="px-5 py-12 text-center text-sm text-slate-500">{passes.length ? 'No transactions match these filters.' : 'No Daily Pass transactions found.'}</td></tr>
                ) : visiblePasses.map((pass) => {
                  const verification = verificationStatus(pass)
                  return (
                    <tr key={pass.id} className="align-top hover:bg-slate-50/70">
                      <td className="px-3 py-3 sm:px-5">
                        <p className="truncate font-semibold text-slate-900">DP-{pass.id}</p>
                        <p className="mt-1 truncate text-xs text-slate-700">{pass.customer_name || 'Guest'}</p>
                        <p className="mt-0.5 truncate text-[11px] text-slate-500">{pass.member_id ? `Member #${pass.member_id}` : 'Guest pass'}</p>
                      </td>
                      <td className="hidden px-3 py-3 text-xs text-slate-600 md:table-cell">{formatDate(pass.visit_date)}</td>
                      <td className="hidden px-3 py-3 text-xs capitalize text-slate-600 lg:table-cell">{pass.payment_method || '—'}</td>
                      <td className="px-2 py-3 sm:px-3">
                        <p className="font-semibold text-slate-800">₱{Number(pass.amount).toFixed(2)}</p>
                        <div className="mt-1"><StatusBadge status={pass.payment_status} /></div>
                      </td>
                      <td className="hidden px-3 py-3 sm:table-cell">
                        <span className={`inline-flex max-w-full rounded-full border px-2.5 py-1 text-[10px] font-semibold ${verificationBadge(verification)}`}>{verification}</span>
                        {pass.payment_method === 'gcash' && pass.proof_original_name && <p className="mt-1 truncate text-[10px] text-slate-500">Proof attached</p>}
                      </td>
                      <td className="hidden px-3 py-3 text-xs text-slate-600 xl:table-cell">{formatDate(pass.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</td>
                      <td className="px-2 py-3 text-right sm:px-5">
                        <button type="button" onClick={() => openPassDetails(pass)} className="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-700 hover:border-sky-300 hover:text-sky-800 sm:px-3">View details</button>
                        {Number(pass.requires_initial_approval) === 1 ? (
                          <p className="mt-2 max-w-36 text-right text-[10px] text-amber-800">Confirm with account approval under Applications</p>
                        ) : pass.payment_method === 'gcash' && pass.payment_status === 'pending' ? (
                          <span className="mt-2 flex justify-end gap-1">
                            <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(pass, 'verify')} className="rounded-lg bg-emerald-600 px-2 py-1.5 text-[10px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Verify</button>
                            <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(pass, 'reject')} className="rounded-lg border border-rose-200 bg-white px-2 py-1.5 text-[10px] font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">Reject</button>
                          </span>
                        ) : pass.payment_method === 'cash' && pass.payment_status === 'unpaid' ? (
                          <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(pass, 'confirm_cash')} className="mt-2 rounded-lg bg-emerald-600 px-2 py-1.5 text-[10px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Confirm cash</button>
                        ) : null}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {!loading && filteredPasses.length > 0 && (
            <footer className="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
              <p className="text-xs text-slate-500">Showing {(currentPage - 1) * PAGE_SIZE + 1}–{Math.min(currentPage * PAGE_SIZE, filteredPasses.length)} of {filteredPasses.length}</p>
              <div className="flex items-center justify-end gap-2">
                <button type="button" disabled={currentPage <= 1} onClick={() => setPage((current) => current - 1)} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40"><FaChevronLeft /> Previous</button>
                <span className="px-2 text-xs text-slate-600">Page {currentPage} of {pageCount}</span>
                <button type="button" disabled={currentPage >= pageCount} onClick={() => setPage((current) => current + 1)} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40">Next <FaChevronRight /></button>
              </div>
            </footer>
          )}
        </section>
      </div>

      {cashModalOpen && (
        <div role="presentation" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" onMouseDown={(event) => { if (event.target === event.currentTarget && processing !== 'cash') setCashModalOpen(false) }}>
          <section role="dialog" aria-modal="true" aria-labelledby="record-cash-title" className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6">
            <header className="mb-5 flex items-start justify-between gap-4">
              <div><h2 id="record-cash-title" className="text-lg font-semibold text-slate-900">Record Cash Purchase</h2><p className="mt-1 text-sm text-slate-500">Fixed price ₱100. Staff confirmation records the cash payment.</p></div>
              <button type="button" disabled={processing === 'cash'} onClick={() => setCashModalOpen(false)} aria-label="Close cash purchase modal" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"><FaTimes /></button>
            </header>
            {formError && <p role="alert" className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{formError}</p>}
            <form onSubmit={recordCash} className="space-y-4">
              <label className="block text-sm font-medium text-slate-700">Customer name
                <input required maxLength={150} value={form.customer_name} onChange={(event) => setForm((current) => ({ ...current, customer_name: event.target.value }))} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-400" />
              </label>
              <label className="block text-sm font-medium text-slate-700">Phone number <span className="font-normal text-slate-400">(optional)</span>
                <input inputMode="numeric" maxLength={11} pattern="[0-9]{11}" title="Enter an 11-digit phone number or leave blank." value={form.customer_phone} onChange={(event) => setForm((current) => ({ ...current, customer_phone: event.target.value.replace(/\D/g, '').slice(0, 11) }))} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-400" />
              </label>
              <div className="rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-600">Visit date <span className="float-right font-semibold text-slate-900">{getManilaDate()} · Asia/Manila</span></div>
              <footer className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" disabled={processing === 'cash'} onClick={() => setCashModalOpen(false)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Cancel</button>
                <button type="submit" disabled={processing === 'cash'} className="rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50">{processing === 'cash' ? 'Recording…' : 'Confirm ₱100 Cash Payment'}</button>
              </footer>
            </form>
          </section>
        </div>
      )}

      {selectedPass && (
        <div role="presentation" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelectedPass(null) }}>
          <section role="dialog" aria-modal="true" aria-labelledby="daily-pass-details-title" className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6">
            <header className="mb-4 flex items-start justify-between gap-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-sky-700">Transaction details</p>
                <h2 id="daily-pass-details-title" className="mt-1 text-xl font-bold text-slate-900">DP-{selectedPass.id}</h2>
              </div>
              <button type="button" onClick={() => setSelectedPass(null)} aria-label="Close transaction details" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><FaTimes /></button>
            </header>

            {error && <p role="alert" className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}

            <dl className="rounded-xl border border-slate-200 px-4">
              <DetailRow label="Member name">{selectedPass.customer_name}{!selectedPass.member_id ? ' · Guest' : ''}</DetailRow>
              <DetailRow label="Member ID">{selectedPass.member_id ? `#${selectedPass.member_id}${selectedPass.member_code ? ` · ${selectedPass.member_code}` : ''}` : 'No linked member account'}</DetailRow>
              <DetailRow label="Contact">{selectedPass.customer_phone || '—'}</DetailRow>
              <DetailRow label="Access date">{formatDate(selectedPass.visit_date)} · One-day access</DetailRow>
              <DetailRow label="Payment method">{selectedPass.payment_method}</DetailRow>
              <DetailRow label="Amount">₱{Number(selectedPass.amount).toFixed(2)}</DetailRow>
              <DetailRow label="Payment status"><StatusBadge status={selectedPass.payment_status} /></DetailRow>
              <DetailRow label="Verification status"><span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${verificationBadge(verificationLabel)}`}>{verificationLabel}</span></DetailRow>
              <DetailRow label="Submitted">{formatDate(selectedPass.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</DetailRow>
              <DetailRow label="Verified / reviewed">{selectedPass.verified_at ? formatDate(selectedPass.verified_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Not reviewed'}</DetailRow>
              <DetailRow label={selectedPass.payment_status === 'rejected' ? 'Rejected by' : selectedPass.payment_status === 'paid' ? 'Verified by' : 'Reviewed by'}>{selectedPass.verified_by_name || '—'}</DetailRow>
              {selectedPass.reference_no && <DetailRow label="Reference number">{selectedPass.reference_no}</DetailRow>}
            </dl>

            {selectedPass.payment_method === 'gcash' && (
              <section className="mt-5 rounded-xl border border-slate-200 p-4">
                <h3 className="text-sm font-semibold text-slate-900">GCash payment proof</h3>
                {selectedPass.proof_original_name ? (
                  <>
                    <p className="mt-1 text-xs text-slate-500">{selectedPass.proof_original_name}</p>
                    {proofLoading ? <p className="py-6 text-center text-sm text-slate-500">Loading proof…</p>
                      : proofError ? <p role="alert" className="mt-3 text-sm text-rose-700">{proofError}</p>
                        : proofType === 'application/pdf' && proofUrl ? <iframe title="GCash payment proof" src={proofUrl} className="mt-3 h-80 w-full rounded-lg border border-slate-200" />
                          : proofUrl ? <img src={proofUrl} alt={`GCash payment proof for transaction DP-${selectedPass.id}`} className="mt-3 max-h-96 w-full rounded-lg border border-slate-200 bg-slate-50 object-contain" />
                            : <p className="mt-3 text-sm text-slate-500">Payment proof preview is unavailable.</p>}
                    {proofUrl && <a href={proofUrl} target="_blank" rel="noreferrer" className="mt-3 inline-block text-xs font-semibold text-sky-700 underline">Open proof in a new tab</a>}
                  </>
                ) : <p className="mt-2 text-sm text-slate-500">No proof file is attached.</p>}
                <p className="mt-3 text-xs text-slate-500">A proof file alone does not verify payment. Review the submitted transaction before taking action.</p>
              </section>
            )}
            {Number(selectedPass.requires_initial_approval) === 1 && (
              <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This is the initial registration pass. Confirm its payment only as part of approving the member account in Member Management → Applications.</p>
            )}

            {selectedPass.payment_status === 'paid' && selectedPass.pass_code && (
              <section className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                <h3 className="text-sm font-semibold text-slate-900">Pass access code</h3>
                <div className="mt-3 flex flex-col items-center gap-3 sm:flex-row sm:items-center">
                  <div className="rounded-lg bg-white p-3"><QRCode value={selectedPass.pass_code} size={112} /></div>
                  <div><p className="text-xs text-slate-500">Valid for the assigned access date above.</p><p className="mt-1 break-all font-mono text-xs text-slate-700">{selectedPass.pass_code}</p></div>
                </div>
              </section>
            )}

            <footer className="mt-5 flex flex-wrap justify-end gap-2">
              {Number(selectedPass.requires_initial_approval) !== 1 && selectedPass.payment_method === 'cash' && selectedPass.payment_status === 'unpaid' && (
                <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(selectedPass, 'confirm_cash')} className="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">{processing ? 'Processing…' : 'Confirm Cash Payment'}</button>
              )}
              {Number(selectedPass.requires_initial_approval) !== 1 && selectedPass.payment_method === 'gcash' && selectedPass.payment_status === 'pending' && (
                <>
                  <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(selectedPass, 'reject')} className="rounded-xl border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">{processing ? 'Processing…' : 'Reject Payment'}</button>
                  <button type="button" disabled={Boolean(processing)} onClick={() => reviewPayment(selectedPass, 'verify')} className="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">{processing ? 'Processing…' : 'Verify Payment'}</button>
                </>
              )}
              <button type="button" onClick={() => setSelectedPass(null)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Close</button>
            </footer>
          </section>
        </div>
      )}
      <ReasonDialog
        open={Boolean(rejectingPass)}
        title="Reject GCash payment"
        description="This payment decision cannot be reversed. Enter the reason that will be recorded with the rejection."
        label="Rejection reason"
        placeholder="Explain why this payment is being rejected"
        required
        maxLength={500}
        confirmText="Continue to confirmation"
        loading={Boolean(processing)}
        onConfirm={confirmPaymentRejection}
        onCancel={() => setRejectingPass(null)}
      />
      <ConfirmationModal
        open={Boolean(reviewConfirmation)}
        title={reviewConfirmation?.action === 'reject' ? 'Confirm payment rejection?' : reviewConfirmation?.action === 'confirm_cash' ? 'Confirm cash payment received?' : 'Verify GCash payment?'}
        message={reviewConfirmation?.action === 'reject'
          ? 'The payment will be marked rejected. This decision cannot be reversed.'
          : reviewConfirmation?.action === 'confirm_cash'
            ? 'Confirm that staff received the fixed ₱100 cash payment.'
            : 'Confirm that the submitted GCash transaction was received and verified.'}
        confirmText={reviewConfirmation?.action === 'reject' ? 'Reject Payment' : reviewConfirmation?.action === 'confirm_cash' ? 'Confirm Cash' : 'Verify Payment'}
        variant={reviewConfirmation?.action === 'reject' ? 'danger' : 'default'}
        loading={Boolean(processing)}
        loadingText="Saving…"
        onConfirm={() => submitReview(reviewConfirmation.pass, reviewConfirmation.action, reviewConfirmation.note)}
        onCancel={() => setReviewConfirmation(null)}
      />
    </Container>
  )
}
