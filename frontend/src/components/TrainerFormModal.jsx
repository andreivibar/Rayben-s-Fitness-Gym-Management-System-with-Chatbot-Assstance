import { FaTimes } from 'react-icons/fa'
import Time12HourSelect from './Time12HourSelect.jsx'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

export default function TrainerFormModal({ open, mode = 'create', formData, onChange, onClose, onSave, errors = {}, saving = false }) {
  if (!open) return null

  const handleSubmit = (e) => { e.preventDefault(); if (!saving) onSave() }

  const handleFieldChange = (field) => (e) => onChange(field, field === 'phone' ? e.target.value.replace(/\D/g, '').slice(0, 11) : e.target.value)
  const schedule = formData.schedule || []
  const addSlot = (day) => onChange('schedule', [...schedule, { day_of_week: day, start_time: '09:00', end_time: '12:00' }])
  const updateSlot = (index, field, value) => onChange('schedule', schedule.map((slot, slotIndex) => slotIndex === index ? { ...slot, [field]: value } : slot))
  const removeSlot = (index) => onChange('schedule', schedule.filter((_, slotIndex) => slotIndex !== index))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-8">
      <div className="flex w-full max-w-2xl max-h-full flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-sm uppercase tracking-[0.28em] text-slate-500">{mode === 'edit' ? 'Edit trainer' : 'Add trainer'}</p>
            <h2 className="mt-2 text-2xl font-semibold text-slate-900">{mode === 'edit' ? 'Update trainer details' : 'Create a new trainer account'}</h2>
          </div>
          <button type="button" onClick={onClose} className="rounded-2xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900">
            <FaTimes />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto space-y-6 px-6 py-6">
          {errors.general && (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
              ✕ {errors.general}
            </div>
          )}

          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">First Name <span className="text-rose-500">*</span></span>
                <input
                  type="text"
                  maxLength={50}
                  value={formData.first_name || ''}
                  onChange={handleFieldChange('first_name')}
                  placeholder="Juan"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  required
                />
                {errors.first_name && <p className="text-xs text-rose-600">{errors.first_name}</p>}
              </label>
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">Middle Name <span className="text-xs font-normal text-slate-400">(Optional)</span></span>
                <input
                  type="text"
                  maxLength={50}
                  value={formData.middle_name || ''}
                  onChange={handleFieldChange('middle_name')}
                  placeholder="Santos"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                />
                {errors.middle_name && <p className="text-xs text-rose-600">{errors.middle_name}</p>}
              </label>
            </div>

            <div>
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">Last Name <span className="text-rose-500">*</span></span>
                <input
                  type="text"
                  maxLength={50}
                  value={formData.last_name || ''}
                  onChange={handleFieldChange('last_name')}
                  placeholder="Dela Cruz"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  required
                />
                {errors.last_name && <p className="text-xs text-rose-600">{errors.last_name}</p>}
              </label>
            </div>

            {mode === 'create' && (
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">Username <span className="text-rose-500">*</span></span>
                <input
                  type="text"
                  autoComplete="username"
                  maxLength={30}
                  value={formData.username || ''}
                  onChange={handleFieldChange('username')}
                  placeholder="trainer_username"
                  pattern="[A-Za-z0-9_]{3,30}"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  required
                />
                {errors.username && <p className="text-xs text-rose-600">{errors.username}</p>}
              </label>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">Email <span className="text-xs font-normal text-slate-400">(Optional)</span></span>
                <input
                  type="email"
                  value={formData.email || ''}
                  onChange={handleFieldChange('email')}
                  placeholder="juan@example.com"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                />
                {errors.email && <p className="text-xs text-rose-600">{errors.email}</p>}
              </label>
              <label className="space-y-2 text-sm text-slate-700">
                <span className="block font-medium">Phone Number <span className="text-rose-500">*</span></span>
                <input
                  type="tel"
                  inputMode="numeric"
                  maxLength={11}
                  pattern="[0-9]{11}"
                  value={formData.phone || ''}
                  onChange={handleFieldChange('phone')}
                  placeholder="09123456789"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                  required
                />
                {errors.phone && <p className="text-xs text-rose-600">{errors.phone}</p>}
              </label>
            </div>

            {mode === 'create' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="space-y-2 text-sm text-slate-700">
                  <span className="block font-medium">Password <span className="text-rose-500">*</span></span>
                  <input
                    type="password"
                    value={formData.password || ''}
                    onChange={handleFieldChange('password')}
                    placeholder="••••••••"
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                    required
                  />
                  {errors.password && <p className="text-xs text-rose-600">{errors.password}</p>}
                </label>
                <label className="space-y-2 text-sm text-slate-700">
                  <span className="block font-medium">Confirm Password <span className="text-rose-500">*</span></span>
                  <input
                    type="password"
                    value={formData.confirm_password || ''}
                    onChange={handleFieldChange('confirm_password')}
                    placeholder="••••••••"
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
                    required
                  />
                  {errors.confirm_password && <p className="text-xs text-rose-600">{errors.confirm_password}</p>}
                </label>
              </div>
            )}
          </div>

          {mode === 'create' && (
            <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
              <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div><p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Initial Weekly Availability</p><p className="mt-1 text-sm text-slate-600">Choose each time using separate hour, minute, and AM/PM controls. Preset shifts can be assigned after the trainer is created.</p></div>
                <p className="text-xs text-slate-500">Optional</p>
              </div>
              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {DAYS.map((day) => {
                  const daySlots = schedule.map((slot, index) => ({ ...slot, index })).filter((slot) => slot.day_of_week === day)
                  return <div key={day} className="rounded-xl border border-slate-200 bg-white p-3"><div className="flex items-center justify-between gap-2"><p className="font-semibold text-slate-800">{day}</p><button type="button" onClick={() => addSlot(day)} className="rounded-lg border border-violet-200 px-2 py-1 text-xs font-medium text-violet-700 hover:bg-violet-50">+ Add slot</button></div>{daySlots.length === 0 ? <p className="mt-3 text-xs text-slate-400">No schedule</p> : <div className="mt-3 space-y-2">{daySlots.map((slot) => <div key={slot.index} className="flex items-center gap-2"><Time12HourSelect value={slot.start_time} onChange={(value) => updateSlot(slot.index, 'start_time', value)} label={`${day} start time`} /><span className="text-xs text-slate-400">to</span><Time12HourSelect value={slot.end_time} onChange={(value) => updateSlot(slot.index, 'end_time', value)} label={`${day} end time`} /><button type="button" onClick={() => removeSlot(slot.index)} className="rounded-lg px-2 py-2 text-sm text-rose-600 hover:bg-rose-50" aria-label={`Remove ${day} slot`}>×</button></div>)}</div>}</div>
                })}
              </div>
              {errors.schedule && <p className="mt-3 text-sm text-rose-600">{errors.schedule}</p>}
            </section>
          )}

          <div className="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
            <button type="button" onClick={onClose} disabled={saving} className="rounded-3xl border border-slate-200 bg-white px-6 py-3 text-sm font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">Cancel</button>
            <button type="submit" disabled={saving} className="rounded-3xl bg-violet-600 px-6 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{saving ? 'Saving…' : mode === 'edit' ? 'Save Changes' : 'Create Trainer'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
