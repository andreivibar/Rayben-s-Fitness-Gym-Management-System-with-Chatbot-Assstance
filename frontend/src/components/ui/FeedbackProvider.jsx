import { useCallback, useEffect, useMemo, useState } from 'react'
import { FaCheckCircle, FaExclamationCircle, FaTimes } from 'react-icons/fa'
import { FeedbackContext, getSafeErrorMessage } from './FeedbackContext.js'
let nextToastId = 0

export default function FeedbackProvider({ children }) {
  const [dialog, setDialog] = useState(null)
  const [toasts, setToasts] = useState([])

  const dismissToast = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const showSuccess = useCallback((message) => {
    const id = ++nextToastId
    setToasts((current) => [...current, { id, message }])
    return id
  }, [])

  const showError = useCallback((title, message) => {
    setDialog({ title, message })
  }, [])

  const showRequestError = useCallback((error, fallback, title = 'Unable to complete action') => {
    if (import.meta.env.DEV) {
      console.error('[request failed]', {
        status: error?.response?.status || null,
        code: error?.code || null,
        name: error?.name || null,
      })
    }
    showError(title, getSafeErrorMessage(error, fallback))
  }, [showError])

  useEffect(() => {
    if (!toasts.length) return undefined
    const timers = toasts.map((toast) => window.setTimeout(() => dismissToast(toast.id), 5000))
    return () => timers.forEach(window.clearTimeout)
  }, [dismissToast, toasts])

  const value = useMemo(() => ({ showSuccess, showError, showRequestError }), [showError, showRequestError, showSuccess])

  return (
    <FeedbackContext.Provider value={value}>
      {children}
      {dialog && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setDialog(null)
        }}>
          <section role="alertdialog" aria-modal="true" aria-labelledby="app-error-title" aria-describedby="app-error-message" className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <div className="flex items-start gap-4">
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-600">
                <FaExclamationCircle aria-hidden="true" className="text-xl" />
              </span>
              <div className="min-w-0 flex-1">
                <h2 id="app-error-title" className="text-lg font-semibold text-slate-900">{dialog.title}</h2>
                <p id="app-error-message" className="mt-2 text-sm leading-6 text-slate-600">{dialog.message}</p>
              </div>
              <button type="button" onClick={() => setDialog(null)} aria-label="Close error dialog" className="text-slate-400 hover:text-slate-600">
                <FaTimes aria-hidden="true" />
              </button>
            </div>
            <div className="mt-6 flex justify-end">
              <button type="button" autoFocus onClick={() => setDialog(null)} className="rounded-xl bg-rose-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-800">
                Close
              </button>
            </div>
          </section>
        </div>
      )}
      <div className="pointer-events-none fixed right-4 top-4 z-[110] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} role="status" className="pointer-events-auto flex items-start gap-3 rounded-2xl border border-emerald-200 bg-white p-4 text-sm text-slate-800 shadow-lg">
            <FaCheckCircle aria-hidden="true" className="mt-0.5 shrink-0 text-emerald-600" />
            <p className="flex-1">{toast.message}</p>
            <button type="button" onClick={() => dismissToast(toast.id)} aria-label="Dismiss notification" className="text-slate-400 hover:text-slate-600">
              <FaTimes aria-hidden="true" />
            </button>
          </div>
        ))}
      </div>
    </FeedbackContext.Provider>
  )
}
