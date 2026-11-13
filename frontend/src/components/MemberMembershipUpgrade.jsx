import { useEffect, useMemo, useState } from 'react'
import api from '../services/api.js'

const categories = [
  { value: 'regular', label: 'Regular — ₱800/month' },
  { value: 'student', label: 'Student Promo — ₱700/month' },
  { value: 'senior', label: 'Senior Citizen Promo — ₱700/month' },
  { value: 'pwd', label: 'PWD Promo — ₱700/month' },
]

function isVerifiedOneMonthPlan(plan) {
  return plan?.plan_code === 'monthly_1'
    && Number(plan.duration_months) === 1
    && Number(plan.regular_price) === 800
    && Number(plan.student_price) === 700
    && Number(plan.senior_price) === 700
    && Number(plan.pwd_price) === 700
}

function MemberMembershipUpgrade({ onClose, onSubmitted }) {
  const [plans, setPlans] = useState([])
  const [gcashSettings, setGcashSettings] = useState(null)
  const [category, setCategory] = useState('regular')
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [durationMonths, setDurationMonths] = useState('1')
  const [periodPreview, setPeriodPreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewError, setPreviewError] = useState('')
  const [proof, setProof] = useState(null)
  const [discountProof, setDiscountProof] = useState(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api.get('/gcash_settings.php')
      .then((response) => {
        if (!active) return
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load monthly plans.')
        setPlans((response.data.membership_plans || []).filter(isVerifiedOneMonthPlan))
        setGcashSettings(response.data.settings || null)
      })
      .catch((requestError) => {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load monthly plans.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [])

  const plan = plans[0]
  const parsedDuration = Number(durationMonths)
  const validDuration = Number.isInteger(parsedDuration) && parsedDuration >= 1 && parsedDuration <= 120
  const amount = useMemo(
    () => plan && validDuration ? Number(plan[`${category}_price`]) * parsedDuration : 0,
    [category, parsedDuration, plan, validDuration]
  )

  useEffect(() => {
    if (!validDuration) return

    let active = true
    api.get('/payments.php', { params: { duration_months: parsedDuration } })
      .then((response) => {
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to calculate the membership dates.')
        if (active) setPeriodPreview(response.data.membership?.next_period || null)
      })
      .catch((requestError) => {
        if (active) setPreviewError(requestError?.response?.data?.message || requestError?.message || 'Unable to calculate the membership dates.')
      })
      .finally(() => {
        if (active) setPreviewLoading(false)
      })
    return () => { active = false }
  }, [parsedDuration, validDuration])

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (!plan) {
      setError('No active one-month membership plan is available.')
      return
    }
    if (!validDuration) {
      setError('Choose a whole number from 1 to 120 months.')
      return
    }
    if (paymentMethod === 'gcash' && !proof) {
      setError('Upload your GCash payment proof.')
      return
    }
    if (category !== 'regular' && !discountProof) {
      setError('Upload a document verifying your selected discount category.')
      return
    }

    setSubmitting(true)
    try {
      const payload = new FormData()
      payload.append('action', 'create_member_upgrade')
      payload.append('membership_plan_id', String(plan.id))
      payload.append('duration_months', String(parsedDuration))
      payload.append('member_category', category)
      payload.append('payment_method', paymentMethod)
      if (paymentMethod === 'gcash' && proof) payload.append('payment_proof', proof)
      if (category !== 'regular' && discountProof) payload.append('discount_proof', discountProof)
      const response = await api.post('/registration_requests.php?action=create_member_upgrade', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to submit your membership request.')
      onSubmitted(response.data.message || 'Monthly membership request submitted.')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to submit your membership request.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="membership-upgrade-title">
      <form onSubmit={submit} className="max-h-[90vh] w-full max-w-xl space-y-4 overflow-y-auto rounded-2xl bg-white p-5 sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 id="membership-upgrade-title" className="text-lg font-bold text-slate-900">Purchase or renew monthly subscription</h2>
            <p className="mt-1 text-sm text-slate-600">Use your existing Member account. Your 1 Day Pass and payment history will remain unchanged.</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm">Close</button>
        </div>
        {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
        {loading ? <p className="py-4 text-sm text-slate-600">Loading available monthly plans…</p> : (
          <>
            <label className="block text-sm font-medium text-slate-700">
              Monthly rate plan
              <select disabled={!plans.length} value={plan?.id || ''} className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                {plans.map((item) => <option key={item.id} value={item.id}>{item.plan_name} · base monthly rates</option>)}
              </select>
            </label>
            <label className="block text-sm font-medium text-slate-700">
              Member category
              <select value={category} onChange={(event) => setCategory(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                {categories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <label className="block text-sm font-medium text-slate-700">
              Number of months
              <input type="number" min="1" max="120" step="1" inputMode="numeric" value={durationMonths} onChange={(event) => { const value = event.target.value; const months = Number(value); setDurationMonths(value); setPreviewLoading(Number.isInteger(months) && months >= 1 && months <= 120); setPreviewError('') }} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5" />
            </label>
            <div className="rounded-xl bg-violet-50 p-3 text-sm text-violet-900">
              <p className="font-semibold">{category === 'regular' ? 'Amount due' : 'Requested total (subject to approval)'}: {validDuration ? `₱${amount.toFixed(2)}` : 'Enter a valid duration'}</p>
              <p className="mt-1 text-xs">{validDuration ? `₱${Number(plan?.[`${category}_price`] || 0).toFixed(2)} per month × ${parsedDuration} month${parsedDuration === 1 ? '' : 's'}` : 'Duration must be between 1 and 120 months.'}</p>
              {category !== 'regular' && <p className="mt-2 text-xs">This promo rate is a request, not an approval. Staff must verify the new supporting document; a previous approval cannot be reused. If declined, submit a new Regular request.</p>}
              <p className="mt-2 text-xs">
                {previewLoading ? 'Calculating membership dates…' : !validDuration
                  ? 'Enter a valid duration to preview membership dates.'
                  : periodPreview
                  ? `Scheduled period after staff approval: ${periodPreview.starts_on} – ${periodPreview.ends_on}`
                  : previewError || 'Membership dates will be shown when available.'}
              </p>
            </div>
            {category !== 'regular' && (
              <label className="block text-sm font-medium text-slate-700">
                {category === 'pwd' ? 'PWD' : category} eligibility document
                <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setDiscountProof(event.target.files?.[0] || null)} className="mt-1 block w-full text-sm" />
                <span className="mt-1 block text-xs font-normal text-slate-500">Required for this request. A previous approval does not carry over; staff must verify this document before the promo rate is approved.</span>
              </label>
            )}
            <label className="block text-sm font-medium text-slate-700">
              Payment method
              <select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
                <option value="cash">Cash — staff confirmation required</option>
                <option value="gcash">GCash — manual verification required</option>
              </select>
            </label>
            {paymentMethod === 'cash' ? (
              <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Staff must record and confirm cash receipt. Your monthly membership will not activate until all approvals are complete.</p>
            ) : (
              <>
                {gcashSettings?.gcash_account_name && gcashSettings?.gcash_account_number ? (
                  <div className="rounded-xl border border-slate-200 p-3 text-sm text-slate-700">
                    <img src={`${import.meta.env.BASE_URL}images/gym/gcashqr.jpg`} alt="Gym GCash payment QR code" className="mx-auto mb-3 max-h-56 w-full max-w-56 object-contain" />
                    <p><strong>GCash account:</strong> {gcashSettings.gcash_account_name}</p>
                    <p><strong>Mobile number:</strong> {gcashSettings.gcash_account_number}</p>
                  </div>
                ) : <p role="alert" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">GCash details are not configured. Do not transfer funds.</p>}
                <label className="block text-sm font-medium text-slate-700">
                  Payment proof
                  <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setProof(event.target.files?.[0] || null)} className="mt-1 block w-full text-sm" />
                </label>
              </>
            )}
            <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
              <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancel</button>
              <button type="submit" disabled={loading || submitting || !plan || !validDuration || (paymentMethod === 'gcash' && (!gcashSettings?.gcash_account_name || !gcashSettings?.gcash_account_number))} className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                {submitting ? 'Submitting…' : 'Submit request'}
              </button>
            </div>
          </>
        )}
      </form>
    </div>
  )
}

export default MemberMembershipUpgrade
