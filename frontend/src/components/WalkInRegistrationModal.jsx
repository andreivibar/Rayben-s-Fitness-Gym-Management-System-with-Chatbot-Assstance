import { useEffect, useState } from 'react'
import { FaTimes } from 'react-icons/fa'
import api from '../services/api.js'
import { MEMBER_GENDER_OPTIONS } from '../utils/memberGender.js'

const initialForm = {
  first_name: '',
  middle_name: '',
  last_name: '',
  username: '',
  email: '',
  phone: '',
  address: '',
  date_of_birth: '',
  gender: '',
  password: '',
  membership_type: 'monthly',
  member_category: 'regular',
  membership_plan_id: '',
  duration_months: '1',
  payment_method: 'cash',
  verify_discount_eligibility: false,
}

function createRequestKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const value = Math.floor(Math.random() * 16)
    return (character === 'x' ? value : (value & 0x3) | 0x8).toString(16)
  })
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

function WalkInRegistrationModal({ open, onClose, onCreated }) {
  const [form, setForm] = useState(initialForm)
  const [plans, setPlans] = useState([])
  const [paymentProof, setPaymentProof] = useState(null)
  const [discountProof, setDiscountProof] = useState(null)
  const [requestKey, setRequestKey] = useState(createRequestKey)
  const [loadingPlans, setLoadingPlans] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!open || plans.length > 0) return
    let active = true
    api.get('gcash_settings.php')
      .then((response) => {
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load membership plans.')
        if (!active) return
        const availablePlans = response.data.membership_plans || []
        setPlans(availablePlans)
        setForm((current) => ({
          ...current,
          membership_plan_id: current.membership_plan_id || String(availablePlans[0]?.id || ''),
        }))
      })
      .catch((requestError) => {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load membership plans.')
      })
      .finally(() => {
        if (active) setLoadingPlans(false)
      })
    return () => { active = false }
  }, [open, plans.length])

  if (!open) return null

  const setField = (field) => (event) => {
    const value = field === 'phone'
      ? event.target.value.replace(/\D/g, '').slice(0, 11)
      : event.target.value
    setForm((current) => ({ ...current, [field]: value }))
  }

  const close = () => {
    if (submitting) return
    setForm(initialForm)
    setPaymentProof(null)
    setDiscountProof(null)
    setRequestKey(createRequestKey())
    setError('')
    onClose()
  }

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (!form.first_name.trim() || !form.last_name.trim() || !form.username.trim() ||
      !form.email.trim() || !form.phone || !form.address.trim() || !form.date_of_birth ||
      !form.password || (form.membership_type === 'monthly' && !form.membership_plan_id) ||
      (form.payment_method === 'gcash' && !paymentProof)) {
      setError('Complete the required fields and provide the required GCash receipt.')
      return
    }
    if (form.membership_type === 'monthly' &&
      (!/^\d+$/.test(form.duration_months) || Number(form.duration_months) < 1 || Number(form.duration_months) > 120)) {
      setError('Choose a whole number from 1 to 120 months.')
      return
    }
    if (form.membership_type === 'monthly' && form.member_category !== 'regular' && !discountProof) {
      setError('Attach the selected member category eligibility document.')
      return
    }
    if (form.membership_type === 'monthly' && form.member_category !== 'regular' && !form.verify_discount_eligibility) {
      setError('Confirm that you reviewed and verified the promo eligibility document.')
      return
    }

    const payload = new FormData()
    Object.entries(form).forEach(([key, value]) => {
      if (key !== 'verify_discount_eligibility' || (form.membership_type === 'monthly' && form.member_category !== 'regular')) {
        payload.append(key, String(value))
      }
    })
    payload.append('idempotency_key', requestKey)
    if (paymentProof && form.payment_method === 'gcash') payload.append('payment_proof', paymentProof)
    if (discountProof && form.membership_type === 'monthly' && form.member_category !== 'regular') {
      payload.append('discount_proof', discountProof)
    }

    setSubmitting(true)
    try {
      const response = await api.post('/admin_member_registration.php', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to create the Member registration.')
      setForm(initialForm)
      setPaymentProof(null)
      setDiscountProof(null)
      setRequestKey(createRequestKey())
      onCreated(response.data)
      onClose()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to create the Member registration. Retry when the connection is available.')
    } finally {
      setSubmitting(false)
    }
  }

  const selectedPlan = plans.find((plan) => String(plan.id) === String(form.membership_plan_id))
  const monthlyRate = Number(selectedPlan?.[`${form.member_category}_price`] || 0)
  const price = monthlyRate * Number(form.duration_months || 0)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
      <section role="dialog" aria-modal="true" aria-labelledby="walkin-registration-title" className="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-700">Admin workflow</p>
            <h2 id="walkin-registration-title" className="mt-1 text-xl font-semibold text-slate-900">New Member Registration</h2>
            <p className="mt-1 text-sm text-slate-500">Admin-created accounts are created directly. Gym access follows payment and eligibility rules.</p>
          </div>
          <button type="button" disabled={submitting} onClick={close} aria-label="Close" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-50"><FaTimes /></button>
        </header>
        <form onSubmit={submit} className="space-y-5 overflow-y-auto p-5">
          {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
          <div className="grid gap-3 sm:grid-cols-2">
            {[
              ['first_name', 'First name', true],
              ['middle_name', 'Middle name', false],
              ['last_name', 'Last name', true],
              ['username', 'Username', true],
              ['email', 'Email', true],
              ['phone', 'Phone (11 digits)', true],
              ['address', 'Address', true],
              ['date_of_birth', 'Date of birth', true],
              ['password', 'Initial password', true],
            ].map(([field, label, required]) => (
              <label key={field} className="space-y-1 text-sm">
                <span className="font-medium text-slate-700">{label}{required && ' *'}</span>
                <input
                  type={field === 'date_of_birth' ? 'date' : field === 'password' ? 'password' : field === 'email' ? 'email' : 'text'}
                  required={required}
                  min={field === 'date_of_birth' ? undefined : undefined}
                  max={field === 'date_of_birth' ? getManilaDate() : undefined}
                  minLength={field === 'password' ? 8 : undefined}
                  maxLength={field === 'phone' ? 11 : field === 'address' ? 255 : undefined}
                  value={form[field]}
                  onChange={setField(field)}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-500/10"
                />
              </label>
            ))}
            <label className="space-y-1 text-sm">
              <span className="font-medium text-slate-700">Gender (optional)</span>
              <select value={form.gender} onChange={setField('gender')} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <option value="">Not specified</option>
                {MEMBER_GENDER_OPTIONS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
              </select>
            </label>
            <label className="space-y-1 text-sm">
              <span className="font-medium text-slate-700">Membership type *</span>
              <select value={form.membership_type} onChange={(event) => {
                const membershipType = event.target.value
                setForm((current) => ({
                  ...current,
                  membership_type: membershipType,
                  member_category: membershipType === 'daily_pass' ? 'regular' : current.member_category,
                  verify_discount_eligibility: false,
                }))
                setDiscountProof(null)
              }} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <option value="monthly">Monthly Membership</option>
                <option value="daily_pass">Daily Pass / 1-Day Pass</option>
              </select>
            </label>
            {form.membership_type === 'monthly' && <>
              <label className="space-y-1 text-sm">
                <span className="font-medium text-slate-700">Subscription plan *</span>
                <select required disabled={loadingPlans || plans.length === 0} value={form.membership_plan_id} onChange={setField('membership_plan_id')} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                  {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.plan_name}</option>)}
                </select>
              </label>
            <label className="space-y-1 text-sm">
              <span className="font-medium text-slate-700">Member category *</span>
              <select value={form.member_category} onChange={setField('member_category')} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <option value="regular">Regular</option>
                <option value="student">Student Promo</option>
                <option value="senior">Senior Citizen Promo</option>
                <option value="pwd">PWD Promo</option>
              </select>
            </label>
            <label className="space-y-1 text-sm">
              <span className="font-medium text-slate-700">Number of months *</span>
              <input required type="number" min="1" max="120" step="1" inputMode="numeric" value={form.duration_months} onChange={setField('duration_months')} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5" />
            </label>
            </>}
          </div>
          {form.membership_type === 'monthly' && selectedPlan && <p className="text-sm font-semibold text-violet-800">Amount due: ₱{Number(price).toFixed(2)} <span className="font-normal">({monthlyRate.toFixed(2)} × {form.duration_months} months)</span></p>}
          {form.membership_type === 'daily_pass' && <div className="rounded-xl bg-sky-50 p-3 text-sm text-sky-900"><p className="font-semibold">Daily Pass: ₱100.00</p><p className="mt-1">Valid for one Manila-local calendar day only. This does not include monthly subscription benefits.</p></div>}
          <label className="block space-y-1 text-sm">
            <span className="font-medium text-slate-700">Payment method *</span>
            <select value={form.payment_method} onChange={(event) => setField('payment_method')(event)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
              <option value="cash">Cash — pending staff confirmation</option>
              <option value="gcash">GCash — admin verification required</option>
            </select>
          </label>
          {form.payment_method === 'gcash' && <label className="block space-y-1 text-sm">
            <span className="font-medium text-slate-700">GCash payment receipt *</span>
            <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setPaymentProof(event.target.files?.[0] || null)} className="block w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
          </label>}
          {form.payment_method === 'cash' && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{form.membership_type === 'daily_pass'
            ? 'The pass will remain unpaid and unavailable until staff confirms cash receipt in Member Management → Daily Passes.'
            : 'The monthly payment will remain pending until staff confirms cash receipt in Payment Management. Gym access remains unavailable until a paid subscription period is active.'}</p>}
          {form.membership_type === 'monthly' && form.member_category !== 'regular' && (
            <>
            <label className="block space-y-1 text-sm">
              <span className="font-medium text-slate-700">Eligibility document *</span>
              <input required type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setDiscountProof(event.target.files?.[0] || null)} className="block w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            </label>
            <label className="flex items-start gap-2 rounded-xl bg-violet-50 p-3 text-sm text-violet-900">
              <input required type="checkbox" checked={form.verify_discount_eligibility} onChange={(event) => setForm((current) => ({ ...current, verify_discount_eligibility: event.target.checked }))} className="mt-1" />
              <span>I reviewed the submitted document and verify this Member’s promo eligibility. The verification will be recorded under my Admin account.</span>
            </label>
            </>
          )}
          <footer className="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
            <button type="button" disabled={submitting} onClick={close} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-50">Cancel</button>
            <button type="submit" disabled={submitting || (form.membership_type === 'monthly' && (loadingPlans || plans.length === 0))} className="rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-50">
              {submitting ? 'Creating Member…' : 'Create Member'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  )
}

export default WalkInRegistrationModal
