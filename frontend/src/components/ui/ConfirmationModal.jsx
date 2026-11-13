import { useEffect, useRef } from 'react'
import { FaExclamationTriangle, FaTimes } from 'react-icons/fa'

export default function ConfirmationModal({ open, title, message, confirmText = 'Confirm', cancelText = 'Cancel', variant = 'default', loading = false, loadingText = 'Processing...', onConfirm, onCancel }) {
  const cancelButtonRef = useRef(null)
  const onCancelRef = useRef(onCancel)

  useEffect(() => {
    onCancelRef.current = onCancel
  }, [onCancel])

  useEffect(() => {
    if (!open) return undefined
    cancelButtonRef.current?.focus()
    const handleKeyDown = (event) => {
      if (event.key === 'Escape' && !loading) onCancelRef.current?.()
      if (event.key === 'Tab') {
        const dialog = cancelButtonRef.current?.closest('[role="dialog"]')
        const focusable = dialog?.querySelectorAll('button:not(:disabled)')
        if (!focusable?.length) return
        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [loading, open])

  if (!open) return null

  const buttonVariants = {
    default: 'bg-slate-900 text-white hover:bg-slate-800',
    danger: 'bg-rose-600 text-white hover:bg-rose-700',
    warning: 'bg-amber-600 text-white hover:bg-amber-700',
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 px-4 py-8">
      <div
        className="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirmation-modal-title"
        aria-describedby="confirmation-modal-message"
      >
        <div className="border-b border-slate-200 px-6 py-5">
          <div className="flex items-start gap-4">
            {variant !== 'default' && (
              <div className={`flex-shrink-0 ${variant === 'danger' ? 'text-rose-600' : 'text-amber-600'}`}>
                <FaExclamationTriangle className="mt-0.5 text-lg" />
              </div>
            )}
            <div className="flex-1">
              <h3 id="confirmation-modal-title" className="text-lg font-semibold text-slate-900">{title}</h3>
              {message && <div id="confirmation-modal-message" className="mt-2 text-sm text-slate-600">{message}</div>}
            </div>
            <button type="button" onClick={onCancel} disabled={loading} aria-label="Close confirmation dialog" className="text-slate-400 transition hover:text-slate-600 disabled:opacity-50">
              <FaTimes />
            </button>
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:justify-end">
          <button
            ref={cancelButtonRef}
            type="button"
            onClick={onCancel}
            disabled={loading}
            className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {cancelText}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={loading}
            className={`rounded-xl px-4 py-2.5 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50 ${buttonVariants[variant]}`}
          >
            {loading ? loadingText : confirmText}
          </button>
        </div>
      </div>
    </div>
  )
}
