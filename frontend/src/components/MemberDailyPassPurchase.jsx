import { useEffect, useState } from 'react'
import api from '../services/api.js'

function createRequestKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const value = Math.floor(Math.random() * 16)
    return (character === 'x' ? value : (value & 0x3) | 0x8).toString(16)
  })
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`).toLocaleDateString('en-PH', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function MemberDailyPassPurchase({ onClose, onSubmitted }) {
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [proof, setProof] = useState(null)
  const [gcashSettings, setGcashSettings] = useState(null)
  const [scheduledDate, setScheduledDate] = useState('')
  const [requestKey] = useState(createRequestKey)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([
      api.get('/gcash_settings.php'),
      api.get('/daily_passes.php', { params: { action: 'member_purchase_preview' } }),
    ])
      .then(([settingsResponse, previewResponse]) => {
        if (!active) return
        if (!settingsResponse.data?.success) throw new Error(settingsResponse.data?.message || 'Unable to load payment information.')
        if (!previewResponse.data?.success) throw new Error(previewResponse.data?.message || 'Unable to calculate the next pass date.')
        setGcashSettings(settingsResponse.data.settings || null)
        setScheduledDate(previewResponse.data.scheduled_visit_date || '')
      })
      .catch((requestError) => {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load payment information.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [])

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (paymentMethod === 'gcash' && !proof) {
      setError('Upload your GCash payment proof.')
      return
    }
    setSubmitting(true)
    try {
      const payload = new FormData()
      payload.append('action', 'member_purchase')
      payload.append('payment_method', paymentMethod)
      payload.append('idempotency_key', requestKey)
      if (paymentMethod === 'gcash' && proof) payload.append('payment_proof', proof)
      const response = await api.post('/daily_passes.php', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to purchase a 1 Day Pass.')
      const assignedDate = response.data.scheduled_visit_date
      onSubmitted(`${response.data.message || '1 Day Pass purchase submitted.'}${assignedDate ? ` Scheduled for ${formatDate(assignedDate)}.` : ''}`)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to purchase a 1 Day Pass.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="daily-pass-purchase-title">
      <form onSubmit={submit} className="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-5 sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 id="daily-pass-purchase-title" className="text-lg font-bold text-slate-900">Buy another 1 Day Pass</h2>
            <p className="mt-1 text-sm text-slate-600">Fixed price ₱100 · valid for one Manila-local calendar day.</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm">Close</button>
        </div>
        {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
        <div className="rounded-xl bg-violet-50 p-3 text-sm text-violet-900">
          <p className="font-semibold">Scheduled pass date</p>
          <p className="mt-1">{loading ? 'Checking the next available date…' : formatDate(scheduledDate)}</p>
          <p className="mt-1 text-xs text-violet-800">Each purchase is assigned the next date after your existing pass transactions.</p>
        </div>
        <label className="block text-sm font-medium text-slate-700">
          Payment method
          <select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            <option value="cash">Cash — staff confirmation required</option>
            <option value="gcash">GCash — manual verification required</option>
          </select>
        </label>
        {paymentMethod === 'cash' ? (
          <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Staff must confirm receipt of ₱100 before gym access is eligible.</p>
        ) : (
          <>
            {gcashSettings?.gcash_account_name && gcashSettings?.gcash_account_number ? (
              <div className="rounded-xl border border-slate-200 p-3 text-sm text-slate-700">
                <img src={`${import.meta.env.BASE_URL}images/gym/gcashqr.jpg`} alt="Gym GCash payment QR code" className="mx-auto mb-3 max-h-56 w-full max-w-56 object-contain" />
                <p><strong>GCash account:</strong> {gcashSettings.gcash_account_name}</p>
                <p><strong>Mobile number:</strong> {gcashSettings.gcash_account_number}</p>
              </div>
            ) : <p role="alert" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">GCash account details are not configured. Do not transfer funds.</p>}
            <label className="block text-sm font-medium text-slate-700">
              Payment proof
              <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setProof(event.target.files?.[0] || null)} className="mt-1 block w-full text-sm" />
            </label>
          </>
        )}
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancel</button>
          <button type="submit" disabled={loading || !scheduledDate || submitting || (paymentMethod === 'gcash' && (!gcashSettings?.gcash_account_name || !gcashSettings?.gcash_account_number))} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {submitting ? 'Submitting…' : 'Submit 1 Day Pass'}
          </button>
        </div>
      </form>
    </div>
  )
}

export default MemberDailyPassPurchase
