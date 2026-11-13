import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../services/api'
import { logout } from '../utils/auth'
import { getStoredUser, persistStoredUser } from '../utils/authState'
import LogoutConfirmationModal from '../components/ui/LogoutConfirmationModal.jsx'

function formatSubmissionDate(value) {
  if (!value) return 'Unavailable'
  const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}+08:00`)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
  }).format(date)
}

function formatPaymentStatus(value) {
  const labels = {
    unpaid: 'Unpaid',
    pending: 'Pending verification',
    paid: 'Paid',
    rejected: 'Rejected',
    submitted: 'Pending verification',
    verified: 'Verified',
    needs_correction: 'Correction requested',
    not_required: 'Not required',
  }
  return labels[String(value || '').toLowerCase()] || 'Unavailable'
}

export default function MemberPendingApproval() {
  const navigate = useNavigate()
  const storedUser = getStoredUser()
  const [approval, setApproval] = useState(storedUser?.initial_approval || { status: 'pending' })
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false)

  const confirmLogout = async () => {
    setLogoutConfirmOpen(false)
    await logout(navigate)
  }

  const refreshStatus = async () => {
    setRefreshing(true)
    setError('')
    try {
      const response = await api.get('/member_account_status.php')
      const currentApproval = response?.data?.initial_approval
      if (!response?.data?.success || !currentApproval?.status) {
        throw new Error(response?.data?.message || 'Unable to retrieve your registration status.')
      }

      const currentUser = getStoredUser()
      if (!currentUser) {
        navigate('/login', { replace: true })
        return
      }
      persistStoredUser({ ...currentUser, initial_approval: currentApproval })
      setApproval(currentApproval)

      if (currentApproval.status === 'approved') {
        navigate('/member/dashboard', { replace: true })
      }
    } catch (refreshError) {
      setError(refreshError?.response?.data?.message || refreshError?.message || 'Unable to retrieve your registration status. Please try again.')
    } finally {
      setRefreshing(false)
    }
  }

  const isRejected = approval?.status === 'rejected'
  const isDailyPass = approval?.application_type === 'daily_pass'

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-8 text-slate-900">
      <section className="w-full max-w-2xl rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-10">
        <header className="text-center">
          <img
            src={`${import.meta.env.BASE_URL}images/gym-logo.png`}
            alt="Rayben's Gym"
            onError={(event) => { event.currentTarget.style.visibility = 'hidden' }}
            className="mx-auto mb-4 h-20 w-32 object-contain"
          />
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700">Rayben's Gym</p>
          <h1 className="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">
            {isRejected ? 'Application Rejected' : 'Registration Pending Approval'}
          </h1>
          <p className="mx-auto mt-4 max-w-xl text-sm leading-6 text-slate-600 sm:text-base">
            {isRejected
              ? 'Your registration application was reviewed and rejected.'
              : 'Your registration has been successfully submitted! Our administrator is currently reviewing your application. You will be able to access your member dashboard once your account has been approved.'}
          </p>
        </header>

        <dl className="mt-7 grid gap-3 rounded-2xl bg-slate-50 p-4 sm:grid-cols-2 sm:p-5">
          <div>
            <dt className="text-xs font-medium text-slate-500">Application submitted</dt>
            <dd className="mt-1 text-sm font-semibold text-slate-800">{formatSubmissionDate(approval?.submitted_at)}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-slate-500">{isDailyPass ? 'Daily Pass payment status' : 'Membership payment status'}</dt>
            <dd className="mt-1 text-sm font-semibold text-slate-800">{formatPaymentStatus(approval?.payment_status)}</dd>
          </div>
        </dl>

        {isRejected && approval?.review_note && (
          <section className="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 sm:p-5">
            <h2 className="text-sm font-semibold text-rose-900">Administrator's reason</h2>
            <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-rose-800">{approval.review_note}</p>
          </section>
        )}

        <p className={`mt-5 rounded-2xl px-4 py-3 text-sm leading-6 ${isRejected ? 'bg-rose-50 text-rose-800' : 'bg-amber-50 text-amber-900'}`}>
          {isRejected
            ? 'Your account remains restricted and gym access is unavailable. Please contact gym administration for more information.'
            : `Your dashboard and gym access are unavailable until your account registration is approved. Account approval is separate from ${isDailyPass ? 'Daily Pass' : 'membership'} payment verification.`}
        </p>

        {error && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}

        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
          <button
            type="button"
            onClick={refreshStatus}
            disabled={refreshing}
            className="min-h-12 flex-1 rounded-xl bg-cyan-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"
          >
            {refreshing ? 'Refreshing…' : 'Refresh Status'}
          </button>
          <button
            type="button"
            onClick={() => setLogoutConfirmOpen(true)}
            className="min-h-12 rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 sm:min-w-36"
          >
            Logout
          </button>
        </div>
      </section>
      <LogoutConfirmationModal
        open={logoutConfirmOpen}
        onConfirm={confirmLogout}
        onCancel={() => setLogoutConfirmOpen(false)}
      />
    </main>
  )
}
