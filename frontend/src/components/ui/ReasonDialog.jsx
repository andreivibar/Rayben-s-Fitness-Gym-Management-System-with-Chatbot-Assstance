import { useState } from 'react'
import { FaExclamationTriangle, FaTimes } from 'react-icons/fa'

export default function ReasonDialog({
  open,
  title,
  description,
  label = 'Reason',
  placeholder = 'Enter a reason',
  confirmText = 'Continue',
  required = false,
  maxLength = 1000,
  loading = false,
  onConfirm,
  onCancel,
}) {
  if (!open) return null
  return (
    <ReasonDialogForm
      title={title}
      description={description}
      label={label}
      placeholder={placeholder}
      confirmText={confirmText}
      required={required}
      maxLength={maxLength}
      loading={loading}
      onConfirm={onConfirm}
      onCancel={onCancel}
    />
  )
}

function ReasonDialogForm({
  title,
  description,
  label,
  placeholder,
  confirmText,
  required,
  maxLength,
  loading,
  onConfirm,
  onCancel,
}) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')

  const submit = (event) => {
    event.preventDefault()
    const value = reason.trim()
    if (required && !value) {
      setError('Enter a reason to continue.')
      return
    }
    setError('')
    onConfirm(value)
  }

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4">
      <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="reason-dialog-title" className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
        <div className="flex items-start gap-4">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700">
            <FaExclamationTriangle aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 id="reason-dialog-title" className="text-lg font-semibold text-slate-900">{title}</h2>
            <p className="mt-1 text-sm leading-6 text-slate-600">{description}</p>
          </div>
          <button type="button" onClick={onCancel} disabled={loading} aria-label="Close dialog" className="text-slate-400 hover:text-slate-600 disabled:opacity-50">
            <FaTimes aria-hidden="true" />
          </button>
        </div>
        <label className="mt-5 block text-sm font-medium text-slate-700">
          {label}{required ? ' (required)' : ' (optional)'}
          <textarea
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            maxLength={maxLength}
            rows={4}
            placeholder={placeholder}
            disabled={loading}
            className="mt-2 w-full resize-y rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100 disabled:bg-slate-50"
          />
        </label>
        {error && <p role="alert" className="mt-2 text-sm text-rose-700">{error}</p>}
        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" onClick={onCancel} disabled={loading} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 disabled:opacity-50">
            Cancel
          </button>
          <button type="submit" disabled={loading} className="rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            {loading ? 'Processing…' : confirmText}
          </button>
        </div>
      </form>
    </div>
  )
}
