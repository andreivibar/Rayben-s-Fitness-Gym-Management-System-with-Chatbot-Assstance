import { FaTimes } from 'react-icons/fa'
import { useState } from 'react'

const categoryLabels = {
  regular: 'Regular',
  student: 'Student',
  senior: 'Senior',
  pwd: 'PWD',
}

function MemberFormModal({
  open,
  mode,
  formData,
  onChange,
  onClose,
  onSave,
  onSaveMembershipAccess,
  accessHistory = [],
  accessHistoryLoading = false,
  accessHistoryError = '',
  error = '',
}) {
  const [errors, setErrors] = useState({})
  const [touched, setTouched] = useState({})
  const [accessSaving, setAccessSaving] = useState(false)
  const [accessSaveError, setAccessSaveError] = useState('')
  const [accessSaveSuccess, setAccessSaveSuccess] = useState('')

  const normalizeTypeKey = (value) =>
    (value || '').toString().trim().toLowerCase().replace(/[^a-z0-9]+/g, '')
  const isMonthly = ['monthlysubscription', 'monthly'].includes(normalizeTypeKey(formData.membershipType))

  // Validation logic
  const validateForm = () => {
    const newErrors = {}
    const today = new Date()
    today.setHours(0, 0, 0, 0)

    // DOB validation
    if (formData.dob) {
      const dobDate = new Date(formData.dob + 'T00:00:00')
      if (dobDate > today) {
        newErrors.dob = 'Date of birth cannot be a future date.'
      }
    }

    if (!/^\d{11}$/.test(formData.phone || '')) {
      newErrors.phone = 'Phone number must contain exactly 11 digits.'
    }

    // Monthly subscription validation
    if (isMonthly) {
      if (!formData.membershipStart) {
        newErrors.membershipStart = 'Start date is required for monthly subscriptions.'
      }
      if (!formData.membershipEnd) {
        newErrors.membershipEnd = 'End date is required for monthly subscriptions.'
      }

      // Only validate date order if both dates are present
      if (formData.membershipStart && formData.membershipEnd) {
        // Parse dates as YYYY-MM-DD format to avoid timezone issues
        const [startYear, startMonth, startDay] = formData.membershipStart.split('-').map(Number)
        const [endYear, endMonth, endDay] = formData.membershipEnd.split('-').map(Number)
        
        const startDate = new Date(startYear, startMonth - 1, startDay)
        const endDate = new Date(endYear, endMonth - 1, endDay)
        
        if (endDate < startDate) {
          newErrors.membershipEnd = 'End date must not be earlier than start date.'
        }
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    if (validateForm()) {
      onSave()
    }
  }

  const handleMembershipAccessSave = async () => {
    setAccessSaving(true)
    setAccessSaveError('')
    setAccessSaveSuccess('')
    try {
      await onSaveMembershipAccess({
        member_category: formData.memberCategory || 'regular',
        gym_access_status: formData.gymAccessControlStatus || 'allowed',
        membership_start: formData.membershipStart || '',
        membership_end: formData.membershipEnd || '',
        reason: formData.accessChangeReason || '',
      })
      onChange('accessChangeReason', '')
      setAccessSaveSuccess('Membership and gym access updated.')
    } catch (saveError) {
      setAccessSaveError(saveError?.response?.data?.message || saveError?.message || 'Unable to save membership and access changes.')
    } finally {
      setAccessSaving(false)
    }
  }

  const handleFieldChange = (field) => (event) => {
    const value = field === 'phone'
      ? event.target.value.replace(/\D/g, '').slice(0, 11)
      : event.target.value
    if (field === 'membershipType' && ['walk-in', 'daily_pass'].includes(value.toLowerCase())) {
      onChange('membershipStart', '')
      onChange('membershipEnd', '')
      setErrors({})
    }
    onChange(field, value)
    setTouched({ ...touched, [field]: true })
    // Clear error for this field when user starts editing it
    if (errors[field]) {
      setErrors({ ...errors, [field]: undefined })
    }
  }

  const handleBlur = (field) => {
    setTouched({ ...touched, [field]: true })
    validateForm()
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-8">
      <div className="flex w-full max-w-2xl max-h-full flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
              {mode === 'edit' ? 'Edit member' : 'Add member'}
            </p>
            <h2 className="mt-2 text-xl font-semibold text-slate-900">
              {mode === 'edit' ? 'Update member details' : 'Add a new gym member'}
            </h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
          >
            <FaTimes />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto space-y-6 px-6 py-6">
          {error && (
            <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
              ✕ {error}
            </div>
          )}
          
          {/* Basic Info Section */}
          <div>
            <h3 className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-600 mb-4">Basic Information</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">First Name <span className="text-rose-500">*</span></span>
                <input
                  type="text"
                  value={formData.firstName || ''}
                  onChange={handleFieldChange('firstName')}
                  onBlur={() => handleBlur('firstName')}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  placeholder="Juan"
                  required
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Middle Name</span>
                <input
                  type="text"
                  value={formData.middleName || ''}
                  onChange={handleFieldChange('middleName')}
                  onBlur={() => handleBlur('middleName')}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  placeholder="Santos"
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Last Name <span className="text-rose-500">*</span></span>
                <input
                  type="text"
                  value={formData.lastName || ''}
                  onChange={handleFieldChange('lastName')}
                  onBlur={() => handleBlur('lastName')}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  placeholder="Dela Cruz"
                  required
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Email</span>
                <input
                  type="email"
                  value={formData.email}
                  onChange={handleFieldChange('email')}
                  onBlur={() => handleBlur('email')}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  placeholder="juan@example.com"
                  required
                />
              </label>
              {mode === 'create' && (
                <label className="space-y-2 text-sm">
                  <span className="block font-medium text-slate-700">Password</span>
                  <input
                    type="text"
                    value={formData.password || ''}
                    onChange={handleFieldChange('password')}
                    onBlur={() => handleBlur('password')}
                    className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                    placeholder="Enter a secure password"
                    required
                  />
                </label>
              )}
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Phone Number</span>
                <input
                  type="tel"
                  inputMode="numeric"
                  maxLength={11}
                  pattern="[0-9]{11}"
                  value={formData.phone}
                  onChange={handleFieldChange('phone')}
                  onBlur={() => handleBlur('phone')}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  placeholder="09123456789"
                  required
                />
                {errors.phone && touched.phone && <p className="mt-1 text-xs text-rose-600">{errors.phone}</p>}
              </label>
              <label className={`space-y-2 text-sm ${errors.dob && touched.dob ? 'has-error' : ''}`}>
                <span className="block font-medium text-slate-700">Date of Birth</span>
                <input
                  type="date"
                  value={formData.dob}
                  onChange={handleFieldChange('dob')}
                  onBlur={() => handleBlur('dob')}
                  className={`w-full rounded-xl border bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition ${
                    errors.dob && touched.dob
                      ? 'border-rose-400 focus:border-rose-400 focus:ring-1 focus:ring-rose-400'
                      : 'border-slate-200 focus:border-violet-400 focus:ring-1 focus:ring-violet-400'
                  }`}
                  required
                />
                {errors.dob && touched.dob && (
                  <p className="mt-1 text-xs text-rose-600">{errors.dob}</p>
                )}
              </label>
            </div>

            <label className="mt-4 space-y-2 text-sm">
              <span className="block font-medium text-slate-700">Address</span>
              <textarea
                value={formData.address}
                onChange={handleFieldChange('address')}
                onBlur={() => handleBlur('address')}
                rows="2"
                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                placeholder="123 Fitness Street, Makati City"
                required
              />
            </label>
          </div>

          {/* Membership Section */}
          <div>
            <h3 className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-600 mb-4">Membership Information</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Membership Type</span>
                <select
                  value={formData.membershipType}
                  onChange={handleFieldChange('membershipType')}
                  disabled={mode === 'edit'}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400 disabled:cursor-not-allowed disabled:opacity-70"
                  required
                >
                  <option value="monthly">Monthly Subscription</option>
                  {mode === 'edit' && formData.membershipType === 'walk-in' && (
                    <option value="walk-in">1 Day Pass (legacy account)</option>
                  )}
                  {mode === 'edit' && formData.membershipType === 'daily_pass' && (
                    <option value="daily_pass">1 Day Pass account (entitlement tracked separately)</option>
                  )}
                </select>
                {mode === 'edit' && <span className="block text-xs text-slate-500">Membership service type changes use the payment and approval workflows.</span>}
              </label>
              {mode === 'edit' && (
                <>
                  <div className="space-y-2 text-sm">
                    <span className="block font-medium text-slate-700">Gym Access Status</span>
                    <p className="rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-slate-700">
                      {formData.status || 'Unknown'}
                    </p>
                  </div>
                  <div className="space-y-2 text-sm">
                    <span className="block font-medium text-slate-700">Account Status</span>
                    <p className="rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-slate-700">
                      {formData.accountStatus || 'Active'}
                    </p>
                  </div>
                </>
              )}
            </div>

            {isMonthly ? (
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <label className={`space-y-2 text-sm ${errors.membershipStart && touched.membershipStart ? 'has-error' : ''}`}>
                  <span className="block font-medium text-slate-700">Membership Start Date</span>
                  <input
                    type="date"
                    disabled={mode === 'edit'}
                    value={formData.membershipStart}
                    onChange={handleFieldChange('membershipStart')}
                    onBlur={() => handleBlur('membershipStart')}
                    className={`w-full rounded-xl border bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition ${
                      errors.membershipStart && touched.membershipStart
                        ? 'border-rose-400 focus:border-rose-400 focus:ring-1 focus:ring-rose-400'
                        : 'border-slate-200 focus:border-violet-400 focus:ring-1 focus:ring-violet-400'
                    } disabled:cursor-not-allowed disabled:opacity-70`}
                    required
                  />
                  {errors.membershipStart && touched.membershipStart && (
                    <p className="mt-1 text-xs text-rose-600">{errors.membershipStart}</p>
                  )}
                </label>
                <label className={`space-y-2 text-sm ${errors.membershipEnd && touched.membershipEnd ? 'has-error' : ''}`}>
                  <span className="block font-medium text-slate-700">Membership End Date</span>
                  <input
                    type="date"
                    disabled={mode === 'edit'}
                    value={formData.membershipEnd}
                    onChange={handleFieldChange('membershipEnd')}
                    onBlur={() => handleBlur('membershipEnd')}
                    className={`w-full rounded-xl border bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition ${
                      errors.membershipEnd && touched.membershipEnd
                        ? 'border-rose-400 focus:border-rose-400 focus:ring-1 focus:ring-rose-400'
                        : 'border-slate-200 focus:border-violet-400 focus:ring-1 focus:ring-violet-400'
                    } disabled:cursor-not-allowed disabled:opacity-70`}
                    required
                  />
                  {errors.membershipEnd && touched.membershipEnd && (
                    <p className="mt-1 text-xs text-rose-600">{errors.membershipEnd}</p>
                  )}
                </label>
              </div>
            ) : (
              <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p className="text-sm text-amber-800">
                  <strong>Legacy 1 Day Pass account:</strong> This historical member type does not grant current access. Record a paid 1 Day Pass transaction for entry.
                </p>
              </div>
            )}
          </div>

          {mode === 'edit' && (
            <section className="space-y-4 rounded-2xl border border-violet-200 bg-violet-50/50 p-4">
              <div>
                <h3 className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-700">Membership & Gym Access</h3>
                <p className="mt-1 text-xs text-slate-600">Update the member category, manual access restriction, or eligible paid subscription dates. Allowing access does not bypass payment eligibility.</p>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="block font-medium text-slate-700">Membership Category</span>
                  <select
                    value={formData.memberCategory || 'regular'}
                    onChange={handleFieldChange('memberCategory')}
                    className="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900"
                  >
                    {Object.entries(categoryLabels).map(([value, label]) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="block font-medium text-slate-700">Manual Gym Access</span>
                  <select
                    value={formData.gymAccessControlStatus || 'allowed'}
                    onChange={handleFieldChange('gymAccessControlStatus')}
                    className="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900"
                  >
                    <option value="allowed">Allowed</option>
                    <option value="restricted">Restricted</option>
                  </select>
                </label>
                {isMonthly ? (
                  <>
                    <label className="space-y-2 text-sm">
                      <span className="block font-medium text-slate-700">Subscription Start Date</span>
                      <input
                        type="date"
                        value={formData.membershipStart || ''}
                        onChange={handleFieldChange('membershipStart')}
                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900"
                      />
                    </label>
                    <label className="space-y-2 text-sm">
                      <span className="block font-medium text-slate-700">Subscription End Date</span>
                      <input
                        type="date"
                        value={formData.membershipEnd || ''}
                        onChange={handleFieldChange('membershipEnd')}
                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900"
                      />
                    </label>
                    <p className="text-xs text-slate-600 sm:col-span-2">Date changes apply only to the current or nearest upcoming paid, verified period. Other subscription periods remain unchanged.</p>
                  </>
                ) : (
                  <p className="text-xs text-slate-600 sm:col-span-2">Daily-pass access still requires a valid, verified pass transaction. There are no monthly subscription dates to edit.</p>
                )}
              </div>
              <label className="block space-y-2 text-sm">
                <span className="block font-medium text-slate-700">Reason for membership/access change <span className="text-rose-600">*</span></span>
                <textarea
                  value={formData.accessChangeReason || ''}
                  onChange={handleFieldChange('accessChangeReason')}
                  rows={2}
                  maxLength={500}
                  className="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900"
                />
              </label>
              {accessSaveError && <p role="alert" className="text-sm text-rose-700">{accessSaveError}</p>}
              {accessSaveSuccess && <p role="status" className="text-sm text-emerald-700">{accessSaveSuccess}</p>}
              <button
                type="button"
                onClick={handleMembershipAccessSave}
                disabled={accessSaving || !formData.accessChangeReason?.trim()}
                className="rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {accessSaving ? 'Saving membership & access…' : 'Save Membership & Access'}
              </button>

              <div className="border-t border-violet-200 pt-4">
                <h4 className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-700">Change History</h4>
                {accessHistoryLoading ? (
                  <p className="mt-3 text-sm text-slate-500">Loading audit history…</p>
                ) : accessHistoryError ? (
                  <p role="alert" className="mt-3 text-sm text-rose-700">{accessHistoryError}</p>
                ) : accessHistory.length === 0 ? (
                  <p className="mt-3 text-sm text-slate-500">No admin changes recorded.</p>
                ) : (
                  <ol className="mt-3 max-h-48 space-y-2 overflow-y-auto">
                    {accessHistory.map((entry) => (
                      <li key={entry.id} className="rounded-xl bg-white p-3 text-xs text-slate-600">
                        <p className="font-semibold text-slate-800">
                          {categoryLabels[entry.previous_member_category] || entry.previous_member_category}
                          {' → '}
                          {categoryLabels[entry.new_member_category] || entry.new_member_category}
                          {' · '}
                          {entry.previous_gym_access_status} → {entry.new_gym_access_status}
                        </p>
                        <p className="mt-1">
                          {entry.previous_membership_start || 'No start'} – {entry.previous_membership_end || 'No end'}
                          {' → '}
                          {entry.new_membership_start || 'No start'} – {entry.new_membership_end || 'No end'}
                        </p>
                        <p className="mt-1">{entry.reason || 'No reason recorded'}</p>
                        <p className="mt-1 text-slate-500">{entry.admin_name || 'Admin'} · {entry.changed_at}</p>
                      </li>
                    ))}
                  </ol>
                )}
              </div>
            </section>
          )}

          {/* Action Buttons */}
          <div className="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
            <button
              type="button"
              onClick={onClose}
              className="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700"
            >
              {mode === 'edit' ? 'Update Member' : 'Add Member'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default MemberFormModal
