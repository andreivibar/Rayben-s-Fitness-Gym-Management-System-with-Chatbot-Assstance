import { FaTimes } from 'react-icons/fa'
import { FaArrowRightFromBracket } from 'react-icons/fa6'

export default function LogoutConfirmationModal({ open, onConfirm, onCancel, loading = false }) {
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 px-4 py-8 backdrop-blur-sm transition-opacity duration-200">
      <div className="w-full max-w-sm transform overflow-hidden rounded-2xl bg-white shadow-xl transition-all duration-200">
        <div className="border-b border-slate-100 px-6 py-5">
          <div className="flex items-start gap-4">
            <div className="mt-0.5 flex-shrink-0 text-slate-500">
              <FaArrowRightFromBracket className="text-xl" />
            </div>
            <div className="flex-1">
              <h3 className="text-lg font-semibold text-slate-900">Log out</h3>
              <p className="mt-1.5 text-sm text-slate-600">Are you sure you want to log out?</p>
            </div>
            <button 
              onClick={onCancel}
              disabled={loading}
              className="flex-shrink-0 text-slate-400 transition hover:text-slate-600 disabled:cursor-not-allowed"
              title="Close"
            >
              <FaTimes className="text-lg" />
            </button>
          </div>
        </div>

        <div className="flex flex-col gap-2.5 border-t border-slate-100 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3">
          <button
            onClick={onCancel}
            disabled={loading}
            className="order-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:order-1"
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className="order-1 rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50 sm:order-2"
          >
            {loading ? 'Logging out...' : 'Log out'}
          </button>
        </div>
      </div>
    </div>
  )
}
