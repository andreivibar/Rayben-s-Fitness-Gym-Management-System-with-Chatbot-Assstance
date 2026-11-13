import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Html5Qrcode } from 'html5-qrcode'
import { FaArrowLeft, FaCamera, FaCheckCircle, FaExclamationCircle, FaStop } from 'react-icons/fa'
import api from '../services/api'

function actionLabel(action) {
  return action === 'time_in' ? 'CHECK IN' : 'CHECK OUT'
}

function formatManilaDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00+08:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'Asia/Manila' }).format(date)
}

function formatManilaTime(value) {
  if (!value) return '—'
  const [hours, minutes, seconds] = String(value).split(':').map(Number)
  if ([hours, minutes, seconds].some(Number.isNaN)) return value
  const suffix = hours >= 12 ? 'PM' : 'AM'
  const hour = hours % 12 || 12
  return `${hour}:${String(minutes).padStart(2, '0')}:${String(seconds || 0).padStart(2, '0')} ${suffix}`
}

export default function MemberAttendanceScan({ mode = 'member' }) {
  const isTrainer = mode === 'trainer'
  const navigate = useNavigate()
  const scannerElement = useRef(null)
  const scanner = useRef(null)
  const processing = useRef(false)
  const [cameraActive, setCameraActive] = useState(false)
  const [busy, setBusy] = useState(false)
  const [pendingToken, setPendingToken] = useState('')
  const [preview, setPreview] = useState(null)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => () => {
    const activeScanner = scanner.current
    scanner.current = null
    if (activeScanner) {
      const stop = activeScanner.isScanning ? activeScanner.stop() : Promise.resolve()
      stop.catch(() => {}).finally(() => {
        try {
          activeScanner.clear()
        } catch {
          // The scanner may already have cleared its camera element.
        }
      })
    }
  }, [])

  const stopCamera = async () => {
    setCameraActive(false)
    const activeScanner = scanner.current
    scanner.current = null
    if (!activeScanner) return

    try {
      if (activeScanner.isScanning) await activeScanner.stop()
      activeScanner.clear()
    } catch {
      setError('The camera could not be stopped cleanly. You can still continue with attendance.')
    }
    if (scannerElement.current) scannerElement.current.innerHTML = ''
  }

  const handleScan = async (decodedText) => {
    if (processing.current) return
    processing.current = true
    setBusy(true)
    setError('')
    await stopCamera()

    const token = (decodedText || '').trim().toLowerCase()
    if (!/^[a-f0-9]{64}$/.test(token)) {
      setError('That QR code is not a valid gym attendance code. Scan the current code displayed at the gym.')
      setBusy(false)
      processing.current = false
      return
    }

    try {
      const endpoint = isTrainer ? '/trainer_attendance_qr.php' : '/member_attendance_qr.php'
      const response = await api.post(endpoint, { operation: 'preview', token })
      if (!response?.data?.success) {
        throw new Error(response?.data?.message || 'Unable to validate this QR code.')
      }
      setPendingToken(token)
      setPreview(response.data)
      setResult(null)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError.message || 'Unable to validate this QR code.')
      setPreview(null)
      setPendingToken('')
    } finally {
      setBusy(false)
      processing.current = false
    }
  }

  const startCamera = async () => {
    if (!scannerElement.current || cameraActive || busy) return
    setError('')
    setPreview(null)
    setPendingToken('')
    setResult(null)
    processing.current = false

    try {
      const reader = new Html5Qrcode(scannerElement.current.id)
      scanner.current = reader
      await reader.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1 },
        handleScan,
        () => {}
      )
      setCameraActive(true)
    } catch (cameraError) {
      const failedScanner = scanner.current
      scanner.current = null
      if (failedScanner) {
        try {
          if (failedScanner.isScanning) await failedScanner.stop()
          failedScanner.clear()
        } catch {
          // A failed camera start may leave no active scanner to clean up.
        }
      }
      if (scannerElement.current) scannerElement.current.innerHTML = ''
      setCameraActive(false)
      setError(
        cameraError?.name === 'NotAllowedError'
          ? 'Camera permission was denied. Allow camera access in your browser settings and try again.'
          : 'Camera access is unavailable. Allow camera permission and open this page over HTTPS (or localhost).'
      )
    }
  }

  const confirmAttendance = async () => {
    if (!preview || !pendingToken || busy) return
    setBusy(true)
    setError('')
    try {
      const endpoint = isTrainer ? '/trainer_attendance_qr.php' : '/member_attendance_qr.php'
      const response = await api.post(endpoint, {
        operation: 'confirm',
        token: pendingToken,
        expected_action: preview.action
      })
      if (!response?.data?.success) {
        throw new Error(response?.data?.message || 'Unable to record attendance.')
      }
      setResult(response.data)
      if (!isTrainer) {
        window.dispatchEvent(new Event('gym:attendance-recorded'))
      }
      setPreview(null)
      setPendingToken('')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError.message || 'Unable to record attendance.')
      setPreview(null)
      setPendingToken('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-3xl items-center gap-4 px-4 py-4 sm:px-6">
          <button
            onClick={() => navigate(isTrainer ? '/trainer' : '/member/dashboard')}
            className="rounded-xl border border-slate-200 bg-white p-3 text-slate-700 hover:bg-slate-50"
            aria-label={`Back to ${isTrainer ? 'trainer' : 'member'} dashboard`}
          >
            <FaArrowLeft />
          </button>
          <div>
            <p className="text-xs uppercase tracking-[0.24em] text-slate-500">{isTrainer ? 'Trainer attendance' : 'Member attendance'}</p>
            <h1 className="text-xl font-semibold sm:text-2xl">Scan gym QR code</h1>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-8 pb-28 sm:px-6 sm:pb-8">
        <section className="rounded-3xl bg-white p-5 shadow-sm sm:p-8">
          <p className="text-sm leading-6 text-slate-600">
            Point your phone camera at the changing QR code displayed at the gym. Your attendance action is checked
            using your signed-in {isTrainer ? 'trainer' : 'member'} account.
          </p>

          <div className="relative mx-auto mt-6 aspect-square w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-slate-950">
            <div
              id={isTrainer ? 'trainer-attendance-reader' : 'member-attendance-reader'}
              ref={scannerElement}
              className="h-full w-full [&_video]:h-full [&_video]:w-full [&_video]:object-cover"
            />
            {!cameraActive && (
              <div className="absolute inset-0 flex flex-col items-center justify-center bg-slate-900/80 p-6 text-center text-white">
                <FaCamera className="mb-3 text-4xl text-slate-300" />
                <p className="font-semibold">{busy ? 'Validating attendance…' : 'Camera ready'}</p>
                <p className="mt-2 max-w-xs text-xs leading-5 text-slate-300">
                  Camera access is requested only after you press Start camera.
                </p>
              </div>
            )}
          </div>

          <div className="mt-5 flex justify-center">
            {cameraActive ? (
              <button
                onClick={stopCamera}
                className="inline-flex min-h-12 items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-6 py-3 font-semibold text-rose-700 hover:bg-rose-100"
              >
                <FaStop /> Stop camera
              </button>
            ) : !preview && !result ? (
              <button
                onClick={startCamera}
                disabled={busy}
                className="inline-flex min-h-12 items-center gap-2 rounded-2xl bg-violet-700 px-6 py-3 font-semibold text-white hover:bg-violet-800 disabled:opacity-60"
              >
                <FaCamera /> Start camera
              </button>
            ) : null}
          </div>

          {error && (
            <div role="alert" className="mt-5 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
              <FaExclamationCircle className="mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {preview && (
            <div className="mt-6 rounded-2xl border border-violet-200 bg-violet-50 p-5">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-700">Confirm attendance</p>
              <p className="mt-3 text-lg font-semibold text-slate-900">{preview[isTrainer ? 'trainer' : 'member']?.full_name}</p>
              <p className="mt-1 text-sm text-slate-600">The next action is {actionLabel(preview.action)}.</p>
              <button
                onClick={confirmAttendance}
                disabled={busy}
                className="mt-5 min-h-12 w-full rounded-2xl bg-violet-700 px-5 py-3 font-semibold text-white hover:bg-violet-800 disabled:opacity-60"
              >
                {busy ? 'Recording…' : `Confirm ${actionLabel(preview.action)}`}
              </button>
            </div>
          )}

          {result && (
            <div role="status" className="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
              <div className="flex items-center gap-2 font-semibold text-emerald-800">
                <FaCheckCircle />
                {actionLabel(result.action)} successful
              </div>
              <p className="mt-2 text-sm text-emerald-900">
                {result[isTrainer ? 'trainer' : 'member']?.full_name} — {result.message}
              </p>
              {isTrainer ? (
                <p className="mt-1 text-sm text-emerald-900">
                  Attendance date and Philippine time: {formatManilaDate(result.attendance?.attendance_date)} at{' '}
                  {formatManilaTime(result.action === 'time_in' ? result.attendance?.time_in : result.attendance?.time_out)}
                </p>
              ) : (
                <p className="mt-1 text-sm text-emerald-900">
                  {result.action === 'time_in' ? 'Check-in' : 'Check-out'} time: {result.attendance?.time_out || result.attendance?.time_in}
                </p>
              )}
              <button
                onClick={startCamera}
                className="mt-4 min-h-11 rounded-xl border border-emerald-300 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100"
              >
                Scan another code
              </button>
            </div>
          )}
        </section>
      </main>
    </div>
  )
}
