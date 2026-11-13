import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaCheckCircle, FaExclamationCircle, FaSpinner, FaTimes } from 'react-icons/fa'
import api from '../services/api'
import { clearAuthState } from '../utils/authState'

export default function EmailChangeModal({ open, onClose, currentEmail = '' }) {
  const navigate = useNavigate()
  const [step, setStep] = useState('details')
  const [newEmail, setNewEmail] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [cooldown, setCooldown] = useState(0)

  useEffect(() => {
    if (cooldown <= 0) return undefined
    const timer = window.setInterval(() => {
      setCooldown((value) => Math.max(0, value - 1))
    }, 1000)
    return () => window.clearInterval(timer)
  }, [cooldown])

  const reset = () => {
    setStep('details')
    setNewEmail('')
    setPassword('')
    setCode('')
    setError('')
    setCooldown(0)
  }

  const close = () => {
    if (loading) return
    if (step === 'complete') {
      navigate('/login', { replace: true })
      return
    }
    reset()
    onClose()
  }

  if (!open) return null

  const requestCode = async () => {
    setLoading(true)
    setError('')
    try {
      await api.post('/email_change.php', {
        action: 'request-code',
        new_email: newEmail.trim(),
        current_password: password,
      })
      setStep('code')
      setCooldown(60)
      setCode('')
    } catch (requestError) {
      const retryAfter = Number(requestError?.response?.data?.retry_after_seconds || 0)
      if (retryAfter > 0) setCooldown(retryAfter)
      setError(requestError?.response?.data?.message || 'Unable to send a verification code. Please try again later.')
    } finally {
      setLoading(false)
    }
  }

  const verifyCode = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      await api.post('/email_change.php', {
        action: 'verify-code',
        new_email: newEmail.trim(),
        current_password: password,
        code,
      })
      setPassword('')
      setCode('')
      clearAuthState()
      setStep('complete')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || 'The verification code is invalid or expired.')
    } finally {
      setLoading(false)
    }
  }

  const changeAddress = () => {
    setStep('details')
    setCode('')
    setError('')
  }

  const continueToLogin = () => {
    reset()
    navigate('/login', { replace: true })
  }

  const title = currentEmail.trim() ? 'Change Email Address' : 'Add Email Address'

  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) close()
      }}
    >
      <section
        className="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="email-change-title"
      >
        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4 sm:px-6 sm:py-5">
          <div>
            <p className="text-xs uppercase tracking-[0.2em] text-slate-500">Account security</p>
            <h2 id="email-change-title" className="mt-1 text-xl font-semibold text-slate-900">{title}</h2>
          </div>
          <button
            type="button"
            onClick={close}
            disabled={loading}
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-50"
            aria-label="Close email change dialog"
          >
            <FaTimes />
          </button>
        </div>

        {step === 'complete' ? (
          <div className="p-5 sm:p-6">
            <div className="flex gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
              <FaCheckCircle className="mt-0.5 shrink-0 text-emerald-600" />
              <div>
                <p className="font-semibold">Email address updated</p>
                <p className="mt-1 text-sm">Your session has ended for security. Sign in again using your new email address.</p>
              </div>
            </div>
            <button
              type="button"
              onClick={continueToLogin}
              className="mt-5 w-full rounded-xl bg-violet-700 px-4 py-3 text-sm font-semibold text-white hover:bg-violet-800"
            >
              Continue to sign in
            </button>
          </div>
        ) : (
          <form onSubmit={step === 'details' ? (event) => { event.preventDefault(); void requestCode() } : verifyCode}>
            <div className="space-y-4 p-5 sm:p-6">
              {error && (
                <div className="flex gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">
                  <FaExclamationCircle className="mt-0.5 shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              {step === 'details' ? (
                <>
                  {currentEmail.trim() && (
                    <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                      Current email: <span className="font-medium text-slate-900">{currentEmail}</span>
                    </p>
                  )}
                  <label className="block text-sm font-medium text-slate-700">
                    New email address
                    <input
                      type="email"
                      name="new_email"
                      autoComplete="email"
                      maxLength={100}
                      required
                      value={newEmail}
                      onChange={(event) => setNewEmail(event.target.value)}
                      className="mt-1.5 w-full rounded-xl border border-slate-300 px-3.5 py-3 text-base outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    />
                    <span className="mt-1 block text-xs text-slate-500">Maximum 100 characters. We’ll send a verification code to this address.</span>
                  </label>
                  <label className="block text-sm font-medium text-slate-700">
                    Current account password
                    <input
                      type="password"
                      name="current_password"
                      autoComplete="current-password"
                      required
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      className="mt-1.5 w-full rounded-xl border border-slate-300 px-3.5 py-3 text-base outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                  <button
                    type="submit"
                    disabled={loading || !newEmail.trim() || !password}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-700 px-4 py-3 text-sm font-semibold text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {loading && <FaSpinner className="animate-spin" />}
                    {loading ? 'Sending code...' : 'Send verification code'}
                  </button>
                </>
              ) : (
                <>
                  <p className="text-sm text-slate-600">
                    Enter the six-digit code sent to <span className="font-medium text-slate-900">{newEmail.trim()}</span>. The code expires in 10 minutes.
                  </p>
                  <label className="block text-sm font-medium text-slate-700">
                    Verification code
                    <input
                      type="text"
                      name="verification_code"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      pattern="[0-9]{6}"
                      maxLength={6}
                      required
                      value={code}
                      onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                      className="mt-1.5 w-full rounded-xl border border-slate-300 px-3.5 py-3 text-center font-mono text-2xl tracking-[0.5em] outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                  <button
                    type="submit"
                    disabled={loading || code.length !== 6}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-700 px-4 py-3 text-sm font-semibold text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {loading && <FaSpinner className="animate-spin" />}
                    {loading ? 'Verifying...' : 'Verify and update email'}
                  </button>
                  <div className="flex flex-col gap-2 text-center text-sm sm:flex-row sm:justify-between">
                    <button
                      type="button"
                      onClick={() => void requestCode()}
                      disabled={loading || cooldown > 0}
                      className="font-medium text-violet-700 hover:text-violet-900 disabled:cursor-not-allowed disabled:text-slate-400"
                    >
                      {cooldown > 0 ? `Resend code in ${cooldown}s` : 'Resend code'}
                    </button>
                    <button
                      type="button"
                      onClick={changeAddress}
                      disabled={loading}
                      className="font-medium text-slate-600 hover:text-slate-900 disabled:opacity-50"
                    >
                      Use a different address
                    </button>
                  </div>
                </>
              )}
            </div>
          </form>
        )}
      </section>
    </div>
  )
}
