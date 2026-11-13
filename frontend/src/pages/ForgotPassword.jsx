import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import api from '../services/api'

const GENERIC_REQUEST_MESSAGE = 'If an eligible account matches that email address, password reset instructions will be sent.'

function getErrorMessage(error, fallback) {
  return error?.response?.data?.message || fallback
}

export default function ForgotPassword() {
  const navigate = useNavigate()
  const [step, setStep] = useState('email')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [resetToken, setResetToken] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [resendSeconds, setResendSeconds] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    if (resendSeconds <= 0) return undefined
    const timer = window.setInterval(() => {
      setResendSeconds((seconds) => Math.max(0, seconds - 1))
    }, 1000)
    return () => window.clearInterval(timer)
  }, [resendSeconds])

  const requestCode = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    setLoading(true)
    try {
      const response = await api.post('/password_reset.php', {
        action: 'request-code',
        email: email.trim(),
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to process the request.')
      }
      setNotice(response.data.message || GENERIC_REQUEST_MESSAGE)
      setResendSeconds(60)
      setStep('code')
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Unable to process the request. Please try again.'))
    } finally {
      setLoading(false)
    }
  }

  const verifyCode = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the six-digit verification code.')
      return
    }
    setLoading(true)
    try {
      const response = await api.post('/password_reset.php', {
        action: 'verify-code',
        email: email.trim(),
        code,
      })
      if (!response.data?.success || !response.data?.reset_token) {
        throw new Error(response.data?.message || 'The verification code is invalid or expired.')
      }
      setResetToken(response.data.reset_token)
      setCode('')
      setStep('password')
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'The verification code is invalid or expired.'))
    } finally {
      setLoading(false)
    }
  }

  const resendCode = async () => {
    if (resendSeconds > 0 || loading) return
    setError('')
    setNotice('')
    setLoading(true)
    try {
      const response = await api.post('/password_reset.php', {
        action: 'request-code',
        email: email.trim(),
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to process the request.')
      }
      setNotice(response.data.message || GENERIC_REQUEST_MESSAGE)
      setResendSeconds(60)
      setCode('')
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Unable to process the request. Please try again.'))
    } finally {
      setLoading(false)
    }
  }

  const completeReset = async (event) => {
    event.preventDefault()
    setError('')
    setNotice('')
    if (newPassword.length < 8 || newPassword.length > 72) {
      setError('Use a password between 8 and 72 characters.')
      return
    }
    if (newPassword.trim() !== newPassword) {
      setError('Password cannot start or end with whitespace.')
      return
    }
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.')
      return
    }

    setLoading(true)
    try {
      const response = await api.post('/password_reset.php', {
        action: 'complete-reset',
        email: email.trim(),
        reset_token: resetToken,
        new_password: newPassword,
        confirm_password: confirmPassword,
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to reset the password.')
      }
      setResetToken('')
      setNewPassword('')
      setConfirmPassword('')
      setStep('success')
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Unable to reset the password. Request a new code and try again.'))
    } finally {
      setLoading(false)
    }
  }

  const titles = {
    email: ['Forgot your password?', 'Enter the email address associated with your Member or Trainer account.'],
    code: ['Check your email', 'Enter the six-digit code if you received one. The code expires after 10 minutes.'],
    password: ['Create a new password', 'Choose a new password for your account.'],
    success: ['Password updated', 'Your password has been changed. Sign in with your new password.'],
  }

  const goToLogin = () => navigate('/login', { replace: true })

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-10 text-slate-900 sm:px-6">
      <section className="w-full max-w-md rounded-[32px] border border-slate-200 bg-white p-7 shadow-xl shadow-slate-200/60 sm:p-9">
        <div className="mb-7 text-center">
          <img
            src={`${import.meta.env.BASE_URL}images/gym-logo.png`}
            alt="Gym logo"
            onError={(event) => { event.currentTarget.style.visibility = 'hidden' }}
            className="mx-auto mb-4 h-24 w-36 object-contain"
          />
          <p className="text-xs font-semibold uppercase tracking-[0.25em] text-violet-600">Account recovery</p>
          <h1 className="mt-2 text-2xl font-semibold sm:text-3xl">{titles[step][0]}</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">{titles[step][1]}</p>
        </div>

        {error && <p role="alert" className="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}
        {notice && <p role="status" className="mb-5 rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-sm text-cyan-900">{notice}</p>}

        {step === 'email' && (
          <form onSubmit={requestCode} className="space-y-5">
            <label className="block text-sm font-medium text-slate-700">
              Email address
              <input
                type="email"
                required
                maxLength={254}
                autoComplete="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className="mt-2 min-h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-violet-400 focus:bg-white"
                placeholder="you@example.com"
              />
            </label>
            <button type="submit" disabled={loading} className="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-800 disabled:cursor-wait disabled:opacity-60">
              {loading ? 'Sending…' : 'Send verification code'}
            </button>
            <Link to="/login" className="block text-center text-sm font-semibold text-violet-600 hover:text-violet-800">Back to Login</Link>
          </form>
        )}

        {step === 'code' && (
          <form onSubmit={verifyCode} className="space-y-5">
            <p className="text-center text-sm text-slate-600">If an eligible account matches, instructions are sent to its registered address.</p>
            <label className="block text-sm font-medium text-slate-700">
              Six-digit verification code
              <input
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                required
                pattern="[0-9]{6}"
                maxLength={6}
                value={code}
                onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                className="mt-2 min-h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-center text-xl tracking-[0.45em] outline-none transition focus:border-violet-400 focus:bg-white"
                placeholder="000000"
              />
            </label>
            <button type="submit" disabled={loading} className="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-800 disabled:cursor-wait disabled:opacity-60">
              {loading ? 'Verifying…' : 'Verify code'}
            </button>
            <button type="button" onClick={resendCode} disabled={loading || resendSeconds > 0} className="min-h-10 w-full text-sm font-semibold text-violet-700 disabled:cursor-not-allowed disabled:text-slate-400">
              {resendSeconds > 0 ? `Resend code in ${resendSeconds}s` : 'Resend code'}
            </button>
            <button type="button" onClick={() => { setStep('email'); setError(''); setNotice('') }} className="block w-full text-center text-sm text-slate-600 hover:text-slate-900">Use a different email</button>
          </form>
        )}

        {step === 'password' && (
          <form onSubmit={completeReset} className="space-y-5">
            <label className="block text-sm font-medium text-slate-700">
              New password
              <span className="relative mt-2 block">
                <input
                  type={showNewPassword ? 'text' : 'password'}
                  required
                  minLength={8}
                  maxLength={72}
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(event) => setNewPassword(event.target.value)}
                  className="min-h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 pr-16 text-sm outline-none transition focus:border-violet-400 focus:bg-white"
                />
                <button type="button" onClick={() => setShowNewPassword((shown) => !shown)} className="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500">
                  {showNewPassword ? 'Hide' : 'Show'}
                </button>
              </span>
            </label>
            <label className="block text-sm font-medium text-slate-700">
              Confirm new password
              <span className="relative mt-2 block">
                <input
                  type={showConfirmPassword ? 'text' : 'password'}
                  required
                  minLength={8}
                  maxLength={72}
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(event) => setConfirmPassword(event.target.value)}
                  className="min-h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 pr-16 text-sm outline-none transition focus:border-violet-400 focus:bg-white"
                />
                <button type="button" onClick={() => setShowConfirmPassword((shown) => !shown)} className="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500">
                  {showConfirmPassword ? 'Hide' : 'Show'}
                </button>
              </span>
            </label>
            <p className="text-xs leading-5 text-slate-500">Use 8–72 characters. Leading or trailing whitespace is not allowed.</p>
            <button type="submit" disabled={loading} className="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-800 disabled:cursor-wait disabled:opacity-60">
              {loading ? 'Updating password…' : 'Reset password'}
            </button>
          </form>
        )}

        {step === 'success' && (
          <div className="space-y-5 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-2xl font-semibold text-emerald-700" aria-hidden="true">✓</div>
            <p role="status" className="text-sm leading-6 text-emerald-800">Your password has been updated and existing sessions have been signed out.</p>
            <button type="button" onClick={goToLogin} className="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-violet-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-800">
              Return to Login
            </button>
          </div>
        )}
      </section>
    </main>
  )
}
