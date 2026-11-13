import { useCallback, useEffect, useState } from 'react'
import { FaCheck, FaExclamationTriangle, FaTimes } from 'react-icons/fa'
import api from '../services/api.js'

function isAdminUser() {
  try {
    const user = JSON.parse(localStorage.getItem('user') || sessionStorage.getItem('user') || 'null')
    return String(user?.role || '').toLowerCase().trim() === 'admin'
  } catch {
    return false
  }
}

function displayDate(value) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('+') ? '' : '+08:00'))
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Manila',
    }).format(date)
}

function Detail({ label, children }) {
  return (
    <div>
      <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 break-words text-sm text-slate-800">{children || '—'}</dd>
    </div>
  )
}

export default function DuplicateNameExceptionReviews() {
  const [view, setView] = useState('pending')
  const [cases, setCases] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [selectedCase, setSelectedCase] = useState(null)
  const [decision, setDecision] = useState('')
  const [reason, setReason] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [processing, setProcessing] = useState(false)

  const fetchCases = useCallback(async () => {
    const response = await api.get('duplicate_name_exceptions.php', {
      params: { action: view === 'history' ? 'history' : 'list' },
    })
    if (!response?.data?.success) {
      throw new Error(response?.data?.message || 'Unable to load duplicate-name review cases.')
    }
    return response.data.duplicate_name_exceptions || []
  }, [view])

  useEffect(() => {
    let active = true
    fetchCases()
      .then((rows) => {
        if (active) {
          setCases(rows)
          setError('')
        }
      })
      .catch((requestError) => {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load duplicate-name review cases.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [fetchCases])

  const loadCases = async () => {
    setLoading(true)
    setError('')
    try {
      setCases(await fetchCases())
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load duplicate-name review cases.')
    } finally {
      setLoading(false)
    }
  }

  const startReview = (caseItem, nextDecision) => {
    setSelectedCase(caseItem)
    setDecision(nextDecision)
    setReason('')
    setConfirmed(false)
    setError('')
    setSuccess('')
  }

  const submitReview = async () => {
    if (!selectedCase || !['authorize', 'reject'].includes(decision) || !reason.trim() || !confirmed || processing) {
      return
    }

    setProcessing(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.post('duplicate_name_exceptions.php', {
        id: Number(selectedCase.id),
        action: decision,
        reason: reason.trim(),
        confirm: true,
      })
      if (!response?.data?.success) {
        throw new Error(response?.data?.message || 'Unable to save the duplicate-name review.')
      }
      setSuccess(response.data.message || 'Duplicate-name review saved.')
      setSelectedCase(null)
      setReason('')
      setConfirmed(false)
      await loadCases()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save the duplicate-name review.')
    } finally {
      setProcessing(false)
    }
  }

  if (!isAdminUser()) {
    return <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">Only administrators can review duplicate-name exceptions.</p>
  }

  return (
    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <header className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Duplicate-name registration review</h2>
          <p className="mt-1 text-sm text-slate-500">Review identity details before allowing an applicant with a matching first and last name to resubmit.</p>
        </div>
        <div className="flex gap-2" aria-label="Duplicate-name case views">
          <button type="button" onClick={() => setView('pending')} aria-pressed={view === 'pending'} className={`rounded-full px-3 py-2 text-xs font-semibold ${view === 'pending' ? 'bg-sky-700 text-white' : 'bg-slate-100 text-slate-600'}`}>Pending review</button>
          <button type="button" onClick={() => setView('history')} aria-pressed={view === 'history'} className={`rounded-full px-3 py-2 text-xs font-semibold ${view === 'history' ? 'bg-sky-700 text-white' : 'bg-slate-100 text-slate-600'}`}>Reviewed history</button>
        </div>
      </header>

      {error && <p role="alert" className="m-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {success && <p role="status" className="m-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{success}</p>}

      {loading ? (
        <p className="px-5 py-12 text-center text-sm text-slate-500">Loading duplicate-name cases…</p>
      ) : cases.length === 0 ? (
        <p className="px-5 py-12 text-center text-sm text-slate-500">{view === 'pending' ? 'No duplicate-name cases are awaiting review.' : 'No reviewed duplicate-name cases found.'}</p>
      ) : (
        <div className="divide-y divide-slate-100">
          {cases.map((caseItem) => (
            <article key={caseItem.id} className="p-4 sm:p-5">
              <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold text-slate-900">{caseItem.full_name}</h3>
                    <span className={`rounded-full border px-2.5 py-1 text-[10px] font-semibold ${caseItem.status === 'authorized' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : caseItem.status === 'rejected' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>{caseItem.status}</span>
                    <span className="text-xs text-slate-500">Case #{caseItem.id}</span>
                  </div>
                  <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Detail label="Username">{caseItem.username}</Detail>
                    <Detail label="Email">{caseItem.email}</Detail>
                    <Detail label="Phone">{caseItem.phone}</Detail>
                    <Detail label="Date of birth">{caseItem.date_of_birth}</Detail>
                    <Detail label="Address">{caseItem.address}</Detail>
                    <Detail label="Registration">{caseItem.membership_type === 'daily_pass' ? 'Daily Pass' : 'Monthly Subscription'}</Detail>
                    <Detail label="Category / duration">{`${String(caseItem.member_category || 'regular').toUpperCase()} · ${caseItem.duration_months} month(s)`}</Detail>
                    <Detail label="Payment">{`${String(caseItem.payment_method || '').toUpperCase()} · ₱${Number(caseItem.payment_amount).toFixed(2)} (not verified)`}</Detail>
                    {caseItem.payment_proof_original_name && <Detail label="Payment proof">{caseItem.payment_proof_original_name}</Detail>}
                    {caseItem.discount_proof_original_name && <Detail label="Eligibility document">{caseItem.discount_proof_original_name}</Detail>}
                    <Detail label="Submitted">{displayDate(caseItem.created_at)}</Detail>
                    {caseItem.authorization_reason && <Detail label={`Authorized by ${caseItem.authorized_by_name || 'administrator'}`}>{`${caseItem.authorization_reason} · ${displayDate(caseItem.authorized_at)}`}</Detail>}
                    {caseItem.status === 'authorized' && <Detail label="Registration status">{caseItem.registration_request_id ? `Submitted · application #${caseItem.registration_request_id}` : 'Authorized · waiting for applicant to resubmit'}</Detail>}
                    {caseItem.rejection_reason && <Detail label={`Rejected by ${caseItem.rejected_by_name || 'administrator'}`}>{`${caseItem.rejection_reason} · ${displayDate(caseItem.rejected_at)}`}</Detail>}
                  </dl>
                </div>
                {view === 'pending' && (
                  <div className="flex shrink-0 flex-wrap gap-2 xl:justify-end">
                    <button type="button" onClick={() => startReview(caseItem, 'reject')} className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"><FaTimes /> Decline exception</button>
                    <button type="button" onClick={() => startReview(caseItem, 'authorize')} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800"><FaCheck /> Authorize registration</button>
                  </div>
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      {selectedCase && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !processing) setSelectedCase(null)
        }}>
          <section role="dialog" aria-modal="true" aria-labelledby="duplicate-review-title" className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
            <header className="flex items-start gap-3 border-b border-slate-200 p-5">
              <FaExclamationTriangle className={`mt-1 shrink-0 ${decision === 'authorize' ? 'text-amber-600' : 'text-rose-600'}`} />
              <div>
                <h3 id="duplicate-review-title" className="font-semibold text-slate-900">{decision === 'authorize' ? 'Authorize duplicate-name registration?' : 'Decline duplicate-name exception?'}</h3>
                <p className="mt-1 text-sm text-slate-600">Case #{selectedCase.id} · {selectedCase.full_name}</p>
              </div>
              <button type="button" disabled={processing} onClick={() => setSelectedCase(null)} aria-label="Close review dialog" className="ml-auto text-slate-400 hover:text-slate-700 disabled:opacity-50"><FaTimes /></button>
            </header>
            <div className="space-y-4 p-5">
              {decision === 'authorize' && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This authorizes only the name exception. No account or payment is created now. The applicant must resubmit the same registration details; the normal initial account approval and independent payment review will then apply.</p>}
              <label className="block">
                <span className="text-sm font-medium text-slate-700">{decision === 'authorize' ? 'Identity review reason' : 'Reason for declining'}</span>
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} rows={3} className="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-sm outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-100" placeholder={decision === 'authorize' ? 'Explain why you verified this is a different person.' : 'Record the reason for declining this exception.'} />
              </label>
              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} className="mt-1 h-4 w-4 rounded border-slate-300 text-sky-700 focus:ring-sky-500" />
                <span>{decision === 'authorize' ? 'I reviewed the applicant’s identity and confirm they are a different person with the same first and last name.' : 'I confirm this review decision and its reason.'}</span>
              </label>
              <div className="flex flex-col-reverse justify-end gap-2 border-t border-slate-100 pt-4 sm:flex-row">
                <button type="button" disabled={processing} onClick={() => setSelectedCase(null)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Cancel</button>
                <button type="button" disabled={processing || !confirmed || !reason.trim()} onClick={submitReview} className={`rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 ${decision === 'authorize' ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-rose-700 hover:bg-rose-800'}`}>{processing ? 'Saving…' : decision === 'authorize' ? 'Confirm authorization' : 'Confirm decline'}</button>
              </div>
            </div>
          </section>
        </div>
      )}
    </section>
  )
}
