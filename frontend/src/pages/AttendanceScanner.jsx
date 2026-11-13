import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaArrowLeft, FaCamera, FaStop, FaCheckCircle, FaExclamationCircle } from 'react-icons/fa'
import { Html5Qrcode } from 'html5-qrcode'
import api from '../services/api'

export default function AttendanceScanner() {
  const navigate = useNavigate()
  const scannerRef = useRef(null)
  const html5Ref = useRef(null)
  const isLockedRef = useRef(false)
  const isStartingRef = useRef(false)
  const resumeTimerRef = useRef(null)

  const [scanning, setScanning] = useState(false)
  const [locked, setLocked] = useState(false)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [liveStats, setLiveStats] = useState({ checked_in: 0, total_today: 0, completed: 0 })

  // Poll live gym stats every 10 seconds
  useEffect(() => {
    let mounted = true
    const fetchStats = async () => {
      try {
        const res = await api.get('/stats.php')
        if (res?.data?.success && mounted) {
          setLiveStats({
            checked_in: res.data.summary?.checked_in ?? 0,
            total_today: res.data.summary?.total_today ?? 0,
            completed: res.data.summary?.completed ?? 0,
          })
        }
      } catch {
        // Live stats are best-effort while attendance scanning remains available.
      }
    }
    fetchStats()
    const id = setInterval(fetchStats, 10000)
    return () => {
      mounted = false
      clearInterval(id)
    }
  }, [])

  // Cleanup scanner on unmount
  useEffect(() => {
    const scannerElement = scannerRef.current
    return () => {
      if (resumeTimerRef.current) {
        clearTimeout(resumeTimerRef.current)
      }
      if (html5Ref.current) {
        try {
          if (html5Ref.current.isScanning) {
            html5Ref.current.stop()
          }
          html5Ref.current.clear()
        } catch {
          // ignore cleanup errors
        }
        html5Ref.current = null
      }
      if (scannerElement) {
        scannerElement.innerHTML = ''
      }
    }
  }, [])

  const startScanner = async () => {
    if (isStartingRef.current) return
    isStartingRef.current = true

    setError('')
    setResult(null)
    setLocked(false)
    isLockedRef.current = false

    if (!scannerRef.current) {
      isStartingRef.current = false
      return
    }

    // Fully tear down any previous instance and clean DOM container
    try {
      if (html5Ref.current) {
        try {
          if (html5Ref.current.isScanning) {
            await html5Ref.current.stop()
          }
          html5Ref.current.clear()
        } catch {
          // ignore
        }
        html5Ref.current = null
      }
    } catch {
      // ignore
    }

    if (scannerRef.current) {
      scannerRef.current.innerHTML = ''
    }

    try {
      const qrScanner = new Html5Qrcode(scannerRef.current.id)
      html5Ref.current = qrScanner

      const config = {
        fps: 10,
        qrbox: { width: 260, height: 260 },
        aspectRatio: 1.0,
      }

      await qrScanner.start(
        { facingMode: 'environment' },
        config,
        (decodedText) => {
          // Synchronous lock check via ref to prevent multiple triggers
          if (isLockedRef.current) return
          if (!decodedText || !decodedText.trim()) return

          isLockedRef.current = true
          setLocked(true)
          handleScan(decodedText.trim())
        },
        () => {
          // ignore frame scan errors
        }
      )

      setError('')
      setScanning(true)
    } catch (e) {
      console.error('Camera initialization error:', e)
      setError('Camera permission is required or camera is unavailable.')
      setScanning(false)
      if (html5Ref.current) {
        try {
          if (html5Ref.current.isScanning) {
            await html5Ref.current.stop()
          }
          html5Ref.current.clear()
        } catch {
          // Initialization already failed; cleanup is best-effort.
        }
        html5Ref.current = null
      }
      if (scannerRef.current) {
        scannerRef.current.innerHTML = ''
      }
    } finally {
      isStartingRef.current = false
    }
  }

  const stopScanner = async () => {
    if (resumeTimerRef.current) {
      clearTimeout(resumeTimerRef.current)
      resumeTimerRef.current = null
    }

    setScanning(false)
    setLocked(false)
    isLockedRef.current = false

    if (html5Ref.current) {
      const instance = html5Ref.current
      html5Ref.current = null
      try {
        if (instance.isScanning) {
          await instance.stop()
        }
        instance.clear()
      } catch {
        // ignore stop errors
      }
    }

    if (scannerRef.current) {
      scannerRef.current.innerHTML = ''
    }
  }

  const handleScan = async (value) => {
    const scannedCode = (value || '').toString().trim()
    if (!scannedCode) {
      setError('Invalid QR code scanned.')
      setResult(null)
      isLockedRef.current = false
      setLocked(false)
      return
    }

    try {
      const isTrainerCode = /^TRN-[A-Z0-9-]+$/i.test(scannedCode)
      const res = isTrainerCode
        ? await api.post('/trainer_attendance.php', { trainer_code: scannedCode })
        : await api.post('/attendance.php', { member_code: scannedCode })

      // Handle success
      if (res?.data?.success) {
        setError('')
        setResult(res.data)
        playBeep()
        // Immediately refresh live gym counters
        try {
          const statsRes = await api.get('/stats.php')
          if (statsRes?.data?.success) {
            setLiveStats({
              checked_in: statsRes.data.summary?.checked_in ?? 0,
              total_today: statsRes.data.summary?.total_today ?? 0,
              completed: statsRes.data.summary?.completed ?? 0,
            })
          }
        } catch {
          // Attendance was recorded; polling can refresh stats later.
        }
      } else {
        setError('')
        setResult(res.data)
      }
    } catch (err) {
      const resData = err?.response?.data
      if (resData && typeof resData === 'object' && resData.message) {
        // Server returned structured error (e.g. already_completed, expired, etc.)
        setError('')
        setResult(resData)
      } else {
        const msg = resData?.message || 'Unable to reach attendance API.'
        setError(msg)
        setResult(null)
      }
    }

    // Keep camera active and resume scanning after 3 seconds cooldown
    if (resumeTimerRef.current) {
      clearTimeout(resumeTimerRef.current)
    }
    resumeTimerRef.current = setTimeout(() => {
      setResult(null)
      setError('')
      setLocked(false)
      isLockedRef.current = false
      resumeTimerRef.current = null
    }, 3000)
  }

  // WebAudio Beep Sound
  const playBeep = () => {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext
      if (!AudioCtx) return
      const ctx = new AudioCtx()
      const o = ctx.createOscillator()
      const g = ctx.createGain()
      o.type = 'sine'
      o.frequency.value = 880
      g.gain.setValueAtTime(0.0001, ctx.currentTime)
      g.gain.exponentialRampToValueAtTime(0.1, ctx.currentTime + 0.01)
      o.connect(g)
      g.connect(ctx.destination)
      o.start()
      setTimeout(() => {
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.02)
        o.stop(ctx.currentTime + 0.03)
        try {
          ctx.close()
        } catch {
          // Closing an already-closed audio context is harmless.
        }
      }, 120)
    } catch {
      // ignore audio errors
    }
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-20">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-4">
            <button
              onClick={() => navigate('/dashboard')}
              className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-slate-700 transition hover:bg-slate-50 hover:text-slate-900"
              title="Back to Dashboard"
            >
              <FaArrowLeft />
            </button>
            <div>
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Attendance Scanner</p>
              <h1 className="text-2xl font-semibold text-slate-900">QR Attendance Scanner</h1>
            </div>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-6 py-8">
        {/* Live Gym Stats */}
        <div className="mb-6 grid grid-cols-3 gap-4">
          <div className="rounded-2xl border border-violet-200 bg-violet-50 p-4 text-center shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-widest text-violet-500">In Gym Now</p>
            <p className="mt-2 text-4xl font-bold text-violet-700">{liveStats.checked_in}</p>
            <span className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-600">
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-400 opacity-75"></span>
                <span className="relative inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
              </span>
              Live
            </span>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4 text-center shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-widest text-slate-500">Today's Total</p>
            <p className="mt-2 text-4xl font-bold text-slate-800">{liveStats.total_today}</p>
            <p className="mt-1 text-xs text-slate-400">check-ins today</p>
          </div>
          <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-center shadow-sm">
            <p className="text-[10px] font-medium uppercase tracking-widest text-emerald-500">Completed</p>
            <p className="mt-2 text-4xl font-bold text-emerald-700">{liveStats.completed}</p>
            <p className="mt-1 text-xs text-emerald-400">checked out</p>
          </div>
        </div>

        <div className="rounded-3xl bg-white p-6 shadow-sm">
          <p className="text-sm text-slate-500">
            Scan a member or trainer QR code to record attendance. The scanner routes the code to the appropriate attendance system.
          </p>

          <div className="mt-6 flex flex-col items-center gap-4">
            {/* Camera Viewport Container */}
            <div className="relative w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 aspect-square shadow-inner flex items-center justify-center">
              <div id="reader" ref={scannerRef} className="w-full h-full [&_video]:w-full [&_video]:h-full [&_video]:object-cover" />

              {!scanning && (
                <div className="absolute inset-0 flex flex-col items-center justify-center bg-slate-900/80 text-white p-6 text-center">
                  <FaCamera className="text-4xl text-slate-400 mb-3" />
                  <p className="font-semibold text-lg">Camera Ready</p>
                  <p className="text-xs text-slate-400 mt-1 max-w-xs">
                    Click Start Scanner below to activate your webcam and scan member or trainer QR codes.
                  </p>
                </div>
              )}

              {scanning && locked && (
                <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/40 backdrop-blur-xs text-white">
                  <div className="h-8 w-8 animate-spin rounded-full border-3 border-white border-t-transparent mb-2"></div>
                  <p className="text-sm font-semibold tracking-wide">Processing Scan...</p>
                </div>
              )}
            </div>

            {/* Controls */}
            {!scanning && (
              <button
                onClick={startScanner}
                className="inline-flex items-center gap-2 rounded-2xl bg-violet-700 px-6 py-3 text-white font-medium hover:bg-violet-800 transition shadow-sm"
              >
                <FaCamera /> Start Scanner
              </button>
            )}

            {scanning && (
              <button
                onClick={stopScanner}
                className="inline-flex items-center gap-2 rounded-2xl bg-rose-50 px-6 py-3 text-rose-700 font-medium hover:bg-rose-100 transition border border-rose-200"
              >
                <FaStop /> Stop Scanner
              </button>
            )}

            {/* General Error Banner */}
            {error && (
              <div className="mt-2 w-full max-w-md rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-700 text-sm font-medium flex items-center justify-between shadow-xs">
                <span className="flex items-center gap-2">
                  <FaExclamationCircle /> {error}
                </span>
                <button onClick={() => setError('')} className="text-rose-600 hover:text-rose-700 font-bold ml-2">
                  ✕
                </button>
              </div>
            )}

            {/* Result Card */}
            {result && (
              <div className="mt-2 w-full max-w-md rounded-2xl border border-slate-200 bg-slate-50 p-6 text-left shadow-sm transition-all">
                {result.success ? (
                  <div>
                    <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-1 text-emerald-800 text-xs font-bold tracking-wide">
                      <FaCheckCircle className="text-emerald-600" />
                      {result.action === 'time_in' ? 'TIME IN SUCCESSFUL' : result.action === 'time_out' ? 'TIME OUT SUCCESSFUL' : 'SUCCESS'}
                    </div>
                    <p className="text-lg font-bold text-slate-900">{result.member?.full_name || result.attendance?.trainer_name}</p>
                    <p className="text-sm text-slate-600">{result.member ? 'Member Code' : 'Trainer Code'}: <span className="font-mono font-medium">{result.member?.member_code || result.attendance?.trainer_code}</span></p>
                    <p className="mt-2 text-sm text-slate-700 font-medium">
                      Time: {result.attendance?.time_out ? result.attendance.time_out : result.attendance?.time_in || 'Recorded'}
                    </p>
                    {result.attendance?.status && <p className="mt-1 text-sm font-semibold uppercase text-violet-700">Status: {result.attendance.status}</p>}
                    <p className="mt-1 text-sm text-emerald-700 font-medium">{result.message}</p>
                    <div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-3 text-xs text-slate-500">
                      <span>Ready for next scan in 3s...</span>
                      <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    </div>
                  </div>
                ) : (
                  <div>
                    <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-amber-800 text-xs font-bold tracking-wide">
                      <FaExclamationCircle className="text-amber-600" />
                      {result.action === 'already_completed'
                        ? 'ALREADY COMPLETED'
                        : result.action === 'already_timed_out'
                        ? 'ALREADY TIMED OUT'
                        : result.action === 'already_scanned'
                        ? 'ALREADY SCANNED'
                        : 'ATTENDANCE NOTICE'}
                    </div>

                    <p className="text-base font-bold text-slate-900 mt-1">
                      {result.action === 'already_completed'
                        ? 'Attendance Completed For Today'
                        : result.action === 'already_timed_out'
                        ? 'Member Already Timed Out'
                        : result.action === 'already_scanned'
                        ? 'Member Already Scanned'
                        : 'Notice'}
                    </p>

                    {(result.member || result.attendance) && (
                      <div className="mt-2 text-sm text-slate-600">
                        <p>{result.member ? 'Member' : 'Trainer'}: <span className="font-semibold text-slate-800">{result.member?.full_name || result.attendance?.trainer_name}</span></p>
                        <p>Code: <span className="font-mono">{result.member?.member_code || result.attendance?.trainer_code}</span></p>
                      </div>
                    )}

                    <p className="mt-2 text-sm font-medium text-amber-800 bg-amber-50 p-2.5 rounded-xl border border-amber-200">
                      {result.message}
                    </p>
                    <div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-3 text-xs text-slate-500">
                      <span>Ready for next scan in 3s...</span>
                      <span className="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  )
}
