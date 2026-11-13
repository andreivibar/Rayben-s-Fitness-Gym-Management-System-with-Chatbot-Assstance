
import { FaCalendarAlt, FaClock, FaTimes, FaUtensils } from 'react-icons/fa'
import Time12HourSelect from './Time12HourSelect.jsx'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const SHIFT_TYPES = [
  { value: 'morning', label: 'Morning Shift' },
  { value: 'afternoon', label: 'Afternoon Shift' },
  { value: 'night', label: 'Night Shift' },
]

export default function ShiftModal({
  open,
  draft,
  onClose,
  onSave,
  onUpdateDraft,
  error,
  saving = false,
}) {

  if (!open || !draft) return null

  const isEditing = Boolean(draft.id)
  const shiftOptions = draft.schedule_type === 'custom'
    ? [...SHIFT_TYPES, { value: 'custom', label: 'Custom Schedule (existing)' }]
    : SHIFT_TYPES

  const handleSubmit = (e) => {
    e.preventDefault()
    onSave()
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 overflow-y-auto"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shift-modal-title"
    >
      <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl transition-all my-8">
        {/* Modal Header */}
        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <div className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
              <FaCalendarAlt className="text-base" />
            </div>
            <div>
              <h2 id="shift-modal-title" className="text-base font-semibold text-slate-900">
                {isEditing ? 'Edit Shift' : 'Add New Shift'}
              </h2>
              <p className="text-xs text-slate-500">
                {draft.day_of_week ? `${draft.day_of_week} schedule configuration` : 'Configure shift schedule'}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50"
            aria-label="Close shift modal"
          >
            <FaTimes />
          </button>
        </div>

        {/* Modal Body */}
        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          {error && (
            <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
              {typeof error === 'string' ? error : error.message}
            </div>
          )}

          {/* Day of Week */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1.5">
              Day of Week
            </label>
            <select
              value={draft.day_of_week}
              onChange={(e) => onUpdateDraft('day_of_week', e.target.value)}
              disabled={saving}
              className={`w-full rounded-xl border bg-white px-3 py-2.5 text-sm text-slate-800 transition focus:outline-none focus:ring-2 focus:ring-violet-500/20 ${
                error?.field === 'day_of_week' ? 'border-rose-400' : 'border-slate-300'
              }`}
            >
              <option value="">Select a day</option>
              {DAYS.map((day) => (
                <option key={day} value={day}>{day}</option>
              ))}
            </select>
            {error?.field === 'day_of_week' && (
              <p role="alert" className="mt-1 text-xs text-rose-600">{error.message}</p>
            )}
          </div>

          {/* Shift Category */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1.5">
              Shift Category
            </label>
            <select
              value={draft.schedule_type}
              onChange={(e) => onUpdateDraft('schedule_type', e.target.value)}
              disabled={saving}
              className={`w-full rounded-xl border bg-white px-3 py-2.5 text-sm text-slate-800 transition focus:outline-none focus:ring-2 focus:ring-violet-500/20 ${
                error?.field === 'schedule_type' ? 'border-rose-400' : 'border-slate-300'
              }`}
            >
              <option value="">Select shift category</option>
              {shiftOptions.map((shift) => (
                <option key={shift.value} value={shift.value}>{shift.label}</option>
              ))}
            </select>
            {error?.field === 'schedule_type' && (
              <p role="alert" className="mt-1 text-xs text-rose-600">{error.message}</p>
            )}
          </div>

          {/* Time Controls: Separated Start Time and End Time */}
          <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 space-y-3">
            <div className="flex items-center gap-2 pb-1 border-b border-slate-200/60 text-xs font-semibold uppercase tracking-wider text-slate-500">
              <FaClock className="text-violet-600" />
              <span>Shift Timing</span>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Start Time
                </label>
                <Time12HourSelect
                  value={draft.start_time}
                  onChange={(val) => onUpdateDraft('start_time', val)}
                  onValidityChange={(valid) => onUpdateDraft('start_time_valid', valid)}
                  label={`${draft.day_of_week || 'Shift'} start time`}
                  disabled={saving}
                  invalid={error?.field === 'start_time' || error?.field === 'time_range'}
                />
                {error?.field === 'start_time' && (
                  <p role="alert" className="mt-1 text-xs text-rose-600">{error.message}</p>
                )}
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  End Time
                </label>
                <Time12HourSelect
                  value={draft.end_time}
                  onChange={(val) => onUpdateDraft('end_time', val)}
                  onValidityChange={(valid) => onUpdateDraft('end_time_valid', valid)}
                  label={`${draft.day_of_week || 'Shift'} end time`}
                  disabled={saving}
                  invalid={error?.field === 'end_time' || error?.field === 'time_range'}
                />
                {error?.field === 'end_time' && (
                  <p role="alert" className="mt-1 text-xs text-rose-600">{error.message}</p>
                )}
              </div>
            </div>

            {error?.field === 'time_range' && (
              <p role="alert" className="text-xs text-rose-600">{error.message}</p>
            )}
          </div>

          {/* Lunch Break Toggle */}
          <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-3.5">
            <label className="flex cursor-pointer items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${draft.lunch_enabled ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-500'}`}>
                  <FaUtensils className="text-xs" />
                </div>
                <div>
                  <span className="block text-xs font-semibold text-slate-800">1-Hour Lunch Break</span>
                  <span className="block text-[11px] text-slate-500">12:00 PM – 1:00 PM (Requires shift to encompass this window)</span>
                </div>
              </div>
              <input
                type="checkbox"
                checked={draft.lunch_enabled}
                onChange={(e) => onUpdateDraft('lunch_enabled', e.target.checked)}
                disabled={saving}
                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
              />
            </label>

            {draft.lunch_enabled && (
              <div className="mt-2.5 rounded-lg border border-amber-200/80 bg-amber-50/80 px-3 py-2 text-xs text-amber-900 flex items-center justify-between">
                <span>Scheduled: <strong>12:00 PM – 1:00 PM</strong></span>
                <span className="text-[10px] uppercase font-semibold tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">Active</span>
              </div>
            )}

            {error?.field === 'lunch_enabled' && (
              <p role="alert" className="mt-2 text-xs text-rose-600">{error.message}</p>
            )}
          </div>

          {/* Modal Footer Actions */}
          <div className="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-100">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 transition"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="rounded-xl bg-violet-700 px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-violet-800 disabled:opacity-50 transition"
            >
              {saving ? 'Saving...' : isEditing ? 'Save Changes' : 'Save Shift'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
