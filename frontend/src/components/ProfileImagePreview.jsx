import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { FaTimes, FaUser } from 'react-icons/fa'

export default function ProfileImagePreview({ open, imageUrl, name, code, onClose }) {
  useEffect(() => {
    if (!open) return undefined

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [open, onClose])

  if (!open) return null

  return createPortal(
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4"
      role="presentation"
      onClick={onClose}
    >
      <div
        className="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col items-center overflow-y-auto rounded-3xl border border-white/10 bg-white p-5 shadow-2xl sm:p-7"
        role="dialog"
        aria-modal="true"
        aria-label={`${name || 'Profile'} picture preview`}
        onClick={(event) => event.stopPropagation()}
      >
        <button
          type="button"
          onClick={onClose}
          className="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
          aria-label="Close profile picture preview"
        >
          <FaTimes />
        </button>
        <div className="mt-5 flex max-h-[min(65vh,32rem)] w-full items-center justify-center overflow-hidden rounded-2xl bg-slate-100 p-3 sm:p-5">
          {imageUrl ? (
            <img src={imageUrl} alt={`${name || 'Profile'} profile`} className="max-h-[min(60vh,28rem)] max-w-full rounded-xl object-contain" />
          ) : (
            <div className="flex h-56 w-56 max-w-full items-center justify-center rounded-full bg-violet-50 text-violet-700 sm:h-72 sm:w-72" aria-label="Default profile avatar">
              <FaUser className="text-7xl sm:text-8xl" />
            </div>
          )}
        </div>
        <p className="mt-5 max-w-full truncate text-center text-lg font-semibold text-slate-900">{name || 'Profile picture'}</p>
        {code && <p className="mt-1 font-mono text-sm text-slate-500">{code}</p>}
      </div>
    </div>,
    document.body
  )
}
