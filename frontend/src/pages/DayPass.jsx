import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import QRCode from 'react-qr-code'
import { FaArrowLeft, FaCheckCircle, FaDumbbell } from 'react-icons/fa'
import api from '../services/api.js'

function createRequestKey() {
  return crypto.randomUUID()
}

export default function DayPass() {
  const [form, setForm] = useState({ customer_name: '', customer_phone: '' })
  const [proof, setProof] = useState(null)
  const [requestKey, setRequestKey] = useState(createRequestKey)
  const [pass, setPass] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [checking, setChecking] = useState(false)
  const [error, setError] = useState('')
  const [gcashSettings, setGcashSettings] = useState(null)

  useEffect(() => {
    api.get('/gcash_settings.php')
      .then((response) => {
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load payment details.')
        setGcashSettings(response.data.settings || null)
      })
      .catch((requestError) => {
        setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load GCash payment details.')
      })
  }, [])

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (!proof) {
      setError('Upload your GCash payment proof.')
      return
    }
    const payload = new FormData()
    Object.entries(form).forEach(([key, value]) => payload.append(key, value.trim()))
    payload.append('payment_proof', proof)
    payload.append('idempotency_key', requestKey)
    payload.append('action', 'submit_gcash')
    setSubmitting(true)
    try {
      const response = await api.post('/daily_passes.php', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to submit your 1 Day Pass.')
      setPass({ ...response.data.daily_pass, pass_id: response.data.pass_id })
      if (response.data.payment_status) {
        setPass((current) => ({ ...current, payment_status: response.data.payment_status }))
      }
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to connect. Your form is preserved; retry when ready.')
    } finally {
      setSubmitting(false)
    }
  }

  const checkStatus = async () => {
    setChecking(true)
    setError('')
    try {
      const response = await api.post('/daily_passes.php', {
        action: 'status',
        idempotency_key: requestKey,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to check pass status.')
      setPass(response.data.daily_pass)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to check pass status. Retry.')
    } finally {
      setChecking(false)
    }
  }

  const reset = () => {
    setForm({ customer_name: '', customer_phone: '' })
    setProof(null)
    setPass(null)
    setError('')
    setRequestKey(createRequestKey())
  }

  return (
    <main className="min-h-screen bg-slate-100 px-4 py-8 text-slate-900 sm:py-12">
      <div className="mx-auto max-w-xl">
        <Link to="/" className="mb-5 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-violet-700">
          <FaArrowLeft /> Back to gym home
        </Link>
        <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
          <div className="mb-6 flex items-center gap-3">
            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-700"><FaDumbbell /></span>
            <div>
              <p className="text-xs font-bold uppercase tracking-widest text-violet-700">Daily access</p>
              <h1 className="text-2xl font-bold">1 Day Pass</h1>
            </div>
            <strong className="ml-auto whitespace-nowrap text-lg">₱100 / day</strong>
          </div>

          {!pass ? (
            <form onSubmit={submit} className="space-y-4">
              <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                Pay with GCash and upload your receipt image. A staff member must verify the actual transaction before the pass can be used. It is valid only on today’s Manila-local date.
              </p>
              <div className="rounded-xl border border-violet-100 bg-violet-50 p-3 text-sm">
                <p className="font-semibold">Send ₱100 to the configured GCash account</p>
                <p className="mt-1">{gcashSettings?.gcash_account_name || 'Payment details are unavailable.'}</p>
                <p>{gcashSettings?.gcash_account_number || ''}</p>
              </div>
              {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
              <label className="block text-sm font-medium">Name
                <input required maxLength={150} value={form.customer_name} onChange={(event) => setForm({ ...form, customer_name: event.target.value })} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5" />
              </label>
              <label className="block text-sm font-medium">Phone (optional)
                <input inputMode="numeric" maxLength={11} value={form.customer_phone} onChange={(event) => setForm({ ...form, customer_phone: event.target.value.replace(/\D/g, '').slice(0, 11) })} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5" />
              </label>
              <label className="block text-sm font-medium">Payment proof
                <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setProof(event.target.files?.[0] || null)} className="mt-1 block w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
              </label>
              <button disabled={submitting || !gcashSettings?.gcash_account_number} className="w-full rounded-xl bg-violet-700 px-4 py-3 font-semibold text-white disabled:opacity-50">
                {submitting ? 'Submitting…' : gcashSettings?.gcash_account_number ? 'Submit GCash proof' : 'GCash unavailable'}
              </button>
            </form>
          ) : (
            <div className="space-y-4 text-center">
              {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
              <FaCheckCircle className="mx-auto text-3xl text-emerald-600" />
              <h2 className="text-lg font-semibold">Pass payment: <span className="capitalize">{pass.payment_status || 'pending'}</span></h2>
              <p className="text-sm text-slate-600">
                {pass.payment_status === 'paid'
                  ? 'Payment verified. This pass is valid for today only.'
                  : pass.payment_status === 'rejected'
                    ? 'The payment was rejected. Contact gym staff for help.'
                    : 'Your pass cannot be used until staff verifies your GCash payment.'}
              </p>
              {pass.pass_code && (
                <div className="mx-auto w-fit rounded-xl border border-slate-200 bg-white p-4">
                  <QRCode value={pass.pass_code} size={180} />
                  <p className="mt-2 break-all font-mono text-xs">{pass.pass_code}</p>
                </div>
              )}
              {pass.pass_code && <p className="text-xs text-slate-500">Show this pass code to gym staff for entry.</p>}
              <div className="flex flex-col justify-center gap-2 sm:flex-row">
                <button type="button" disabled={checking} onClick={checkStatus} className="rounded-xl border border-violet-200 px-4 py-2.5 text-sm font-semibold text-violet-700 disabled:opacity-50">
                  {checking ? 'Checking…' : 'Check payment status'}
                </button>
                <button type="button" onClick={reset} className="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white">New 1 Day Pass</button>
              </div>
            </div>
          )}
        </section>
      </div>
    </main>
  )
}
