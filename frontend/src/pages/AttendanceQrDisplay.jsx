import { memo, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import QRCode from 'react-qr-code'
import { FaArrowLeft } from 'react-icons/fa'
import api from '../services/api'

const TOKEN_STORAGE_KEY = 'gym-attendance-qr-token'
const RETRY_DELAY_MS = 30 * 1000
let tokenRequestPromise = null

const AttendanceQrCode = memo(function AttendanceQrCode({ token }) {
  return (
    <QRCode
      value={token}
      size={320}
      style={{ width: '100%', height: 'auto', maxWidth: 320 }}
      aria-label="Gym attendance QR code"
    />
  )
})

function readStoredToken() {
  try {
    const stored = JSON.parse(localStorage.getItem(TOKEN_STORAGE_KEY) || 'null')
    if (stored && /^[a-f0-9]{64}$/i.test(stored.token || '')) return stored
  } catch {
    // Invalid local state is discarded in favor of a server-issued token.
  }
  return null
}

function requestAttendanceToken(currentToken) {
  if (!tokenRequestPromise) {
    tokenRequestPromise = api.get('/attendance_qr_token.php', {
      headers: currentToken ? { 'X-Current-Attendance-Token': currentToken } : {}
    }).finally(() => {
      tokenRequestPromise = null
    })
  }
  return tokenRequestPromise
}

function formatManilaDateTime(value) {
  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'long',
    timeZone: 'Asia/Manila'
  }).format(new Date(value))
}

function manilaDateFromInstant(value) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    timeZone: 'Asia/Manila'
  }).formatToParts(new Date(value))
  const dateParts = Object.fromEntries(parts.map(({ type, value: partValue }) => [type, partValue]))
  return `${dateParts.year}-${dateParts.month}-${dateParts.day}`
}

function formatManilaDate(value) {
  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
    timeZone: 'Asia/Manila'
  }).format(new Date(`${value}T00:00:00+08:00`))
}

export default function AttendanceQrDisplay() {
  const navigate = useNavigate()
  const cachedToken = useRef(readStoredToken())
  const [token, setToken] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [validForDate, setValidForDate] = useState('')
  const [secondsLeft, setSecondsLeft] = useState(0)
  const [durationMs, setDurationMs] = useState(0)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const activeToken = useRef('')
  const expiryDeadline = useRef(0)
  const retryDeadline = useRef(0)
  const requestInFlight = useRef(false)
  const refreshTokenRef = useRef(null)

  useEffect(() => {
    const refreshToken = async (currentToken = '') => {
      if (requestInFlight.current) return
      requestInFlight.current = true
      const requestStartedAt = performance.now()
      setLoading(true)
      setError('')
      try {
        const response = await requestAttendanceToken(currentToken)
        const result = response?.data
        if (
          !result?.success ||
          !/^[a-f0-9]{64}$/i.test(result.token || '') ||
          !result.expires_at ||
          !result.server_time ||
          !/^\d{4}-\d{2}-\d{2}$/.test(result.valid_for_date || '')
        ) {
          throw new Error(response?.data?.message || 'The server returned an invalid attendance QR response.')
        }
        const serverTime = new Date(result.server_time).getTime()
        const expiry = new Date(result.expires_at).getTime()
        const remainingMs = expiry - serverTime - (performance.now() - requestStartedAt)
        if (
          !Number.isFinite(serverTime) ||
          !Number.isFinite(expiry) ||
          result.valid_for_date !== manilaDateFromInstant(result.server_time) ||
          remainingMs <= 0
        ) {
          throw new Error('The current day’s QR code has expired. Requesting a fresh code is required.')
        }

        const tokenData = {
          token: result.token,
          expires_at: result.expires_at,
          valid_for_date: result.valid_for_date
        }
        try {
          localStorage.setItem(TOKEN_STORAGE_KEY, JSON.stringify(tokenData))
        } catch {
          // The server-issued code remains usable for this page session.
        }
        activeToken.current = tokenData.token
        setToken(tokenData.token)
        setExpiresAt(tokenData.expires_at)
        setValidForDate(tokenData.valid_for_date)
        expiryDeadline.current = performance.now() + remainingMs
        retryDeadline.current = 0
        setDurationMs(remainingMs)
        setSecondsLeft(Math.ceil(remainingMs / 1000))
        setError('')
      } catch (requestError) {
        const safeMessage = requestError?.response?.data?.message || requestError.message || 'Attendance QR generation failed.'
        activeToken.current = ''
        expiryDeadline.current = 0
        retryDeadline.current = performance.now() + RETRY_DELAY_MS
        try {
          localStorage.removeItem(TOKEN_STORAGE_KEY)
        } catch {
          // The QR code is not rendered while the service is unavailable.
        }
        setToken('')
        setExpiresAt('')
        setValidForDate('')
        setSecondsLeft(0)
        setDurationMs(0)
        setError(safeMessage)
      } finally {
        requestInFlight.current = false
        setLoading(false)
      }
    }
    refreshTokenRef.current = refreshToken

    const initialToken = cachedToken.current?.token || ''
    cachedToken.current = null
    refreshToken(initialToken)

    const updateRotation = () => {
      if (expiryDeadline.current) {
        const remaining = Math.max(0, Math.ceil((expiryDeadline.current - performance.now()) / 1000))
        setSecondsLeft(remaining)
        if (remaining === 0) {
          const expiredToken = activeToken.current
          activeToken.current = ''
          expiryDeadline.current = 0
          try {
            localStorage.removeItem(TOKEN_STORAGE_KEY)
          } catch {
            // The backend rejects expired tokens even if local storage is unavailable.
          }
          setToken('')
          setExpiresAt('')
          setValidForDate('')
          refreshToken(expiredToken)
        }
      } else if (retryDeadline.current && performance.now() >= retryDeadline.current) {
        retryDeadline.current = 0
        refreshToken('')
      }
    }

    const intervalId = setInterval(updateRotation, 1000)
    document.addEventListener('visibilitychange', updateRotation)

    return () => {
      clearInterval(intervalId)
      document.removeEventListener('visibilitychange', updateRotation)
    }
  }, [])

  const retry = () => {
    retryDeadline.current = 0
    refreshTokenRef.current?.(activeToken.current)
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-5xl items-center gap-4 px-4 py-4 sm:px-6">
          <button
            onClick={() => navigate('/dashboard')}
            className="rounded-xl border border-slate-200 bg-white p-3 text-slate-700 hover:bg-slate-50"
            aria-label="Back to dashboard"
          >
            <FaArrowLeft />
          </button>
          <div>
            <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Gym attendance</p>
            <h1 className="text-xl font-semibold sm:text-2xl">Live QR code</h1>
          </div>
        </div>
      </header>

      <main className="mx-auto flex max-w-5xl justify-center px-4 py-8 sm:px-6">
        <section className="w-full max-w-xl rounded-3xl bg-white p-5 text-center shadow-sm sm:p-8">
          <p className="text-sm text-slate-600">
            Display this code at the gym entrance. Members and trainers must sign in and scan it from their attendance page.
          </p>

          <div className="mx-auto mt-6 flex min-h-80 w-full max-w-96 items-center justify-center rounded-2xl border border-slate-200 bg-white p-4">
            {token ? (
              <AttendanceQrCode token={token} />
            ) : loading ? (
              <p className="text-slate-500">Generating secure QR code…</p>
            ) : (
              <div className="space-y-3">
                <p role="alert" className="text-sm text-rose-700">{error || 'No active QR code is available.'}</p>
                <button
                  onClick={retry}
                  disabled={loading}
                  className="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-60"
                >
                  {loading ? 'Retrying…' : 'Retry QR generation'}
                </button>
              </div>
            )}
          </div>

          <div className="mt-5">
            {token && <p className="text-sm font-semibold text-slate-900">Valid for {formatManilaDate(validForDate)} (Philippine time)</p>}
            <p className="text-sm font-medium text-slate-700">
              {token ? `Expires in ${Math.floor(secondsLeft / 3600)}h ${Math.floor((secondsLeft % 3600) / 60)}m ${secondsLeft % 60}s` : 'Waiting for a new code'}
            </p>
            {token && <p className="mt-1 text-xs text-slate-500">Expires {formatManilaDateTime(expiresAt)}</p>}
            <div className="mx-auto mt-2 h-2 max-w-xs overflow-hidden rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-violet-600 transition-all"
                style={{ width: `${token && durationMs > 0 ? Math.min(100, (secondsLeft * 1000 / durationMs) * 100) : 0}%` }}
              />
            </div>
          </div>

          <p className="mt-6 rounded-2xl bg-amber-50 px-4 py-3 text-left text-xs leading-5 text-amber-900">
            This code is valid only for the displayed Philippine calendar date and expires at midnight. Members and
            trainers can use it for their current attendance action. A QR code does not independently prove a member
            is physically at the gym.
          </p>
        </section>
      </main>
    </div>
  )
}
