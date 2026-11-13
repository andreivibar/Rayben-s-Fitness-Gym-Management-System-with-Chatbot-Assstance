import { useCallback, useEffect, useState } from 'react'
import { FaPlus } from 'react-icons/fa'
import api from '../services/api'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function emptyHours() {
  return Object.fromEntries(DAYS.map((day) => [day, { is_open: false, opens_at: '', closes_at: '' }]))
}

export default function BookingSettingsPanel() {
  const [loading, setLoading] = useState(true)
  const [savingHours, setSavingHours] = useState(false)
  const [savingWindow, setSavingWindow] = useState(false)
  const [savingSession, setSavingSession] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [sessionTypes, setSessionTypes] = useState([])
  const [priceVersions, setPriceVersions] = useState([])
  const [hours, setHours] = useState(emptyHours)
  const [maxAdvanceDays, setMaxAdvanceDays] = useState(90)
  const [newSession, setNewSession] = useState({ name: '', duration_minutes: 60, service_category: 'other', is_active: true })

  const loadSettings = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/booking_settings.php')
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load booking settings.')
      setSessionTypes(response.data.session_types || [])
      setPriceVersions(response.data.price_versions || [])
      setMaxAdvanceDays(Number(response.data.max_advance_days) || 90)
      setHours(Object.fromEntries(DAYS.map((day) => {
        const row = response.data.operating_hours?.[day] || {}
        return [day, { is_open: Boolean(row.is_open), opens_at: row.opens_at || '', closes_at: row.closes_at || '' }]
      })))
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load booking settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(() => loadSettings())
  }, [loadSettings])

  const saveSession = async (session) => {
    setError('')
    setNotice('')
    setSavingSession(true)
    try {
      const response = await api.post('/booking_settings.php?action=save-session-type', session)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save session type.')
      setNotice(response.data.message || 'Session type saved.')
      setNewSession({ name: '', duration_minutes: 60, service_category: 'other', is_active: true })
      await loadSettings()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save session type.')
    } finally {
      setSavingSession(false)
    }
  }

  const savePrice = async (price) => {
    setError('')
    setNotice('')
    setSavingSession(true)
    try {
      const response = await api.post('/booking_settings.php?action=create-price', price)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save booking price.')
      setNotice(response.data.message || 'Booking price saved.')
      await loadSettings()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save booking price.')
    } finally {
      setSavingSession(false)
    }
  }

  const retirePrice = async (priceId) => {
    setError('')
    setNotice('')
    setSavingSession(true)
    try {
      const response = await api.post('/booking_settings.php?action=retire-price', { price_id: priceId })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to retire booking price.')
      setNotice(response.data.message || 'Booking price retired.')
      await loadSettings()
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to retire booking price.')
    } finally {
      setSavingSession(false)
    }
  }

  const saveHours = async (event) => {
    event.preventDefault()
    setSavingHours(true)
    setError('')
    setNotice('')
    try {
      const response = await api.post('/booking_settings.php?action=save-operating-hours', { operating_hours: hours })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save operating hours.')
      setNotice(response.data.message || 'Gym operating hours saved.')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save operating hours.')
    } finally {
      setSavingHours(false)
    }
  }

  const saveBookingWindow = async (event) => {
    event.preventDefault()
    setSavingWindow(true)
    setError('')
    setNotice('')
    try {
      const response = await api.post('/booking_settings.php?action=save-booking-window', {
        max_advance_days: Number(maxAdvanceDays),
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save booking window.')
      setNotice(response.data.message || 'Maximum advance-booking window saved.')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to save booking window.')
    } finally {
      setSavingWindow(false)
    }
  }

  return (
    <div className="mt-6 space-y-5">
      <div>
        <h3 className="font-semibold text-slate-900">Booking setup</h3>
        <p className="mt-1 text-sm text-slate-600">Availability and booking requests follow these server-enforced settings.</p>
      </div>

      {error && (
        <div role="alert" className="flex flex-col gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 sm:flex-row sm:items-center sm:justify-between">
          <p>{error}</p>
          <button type="button" onClick={() => void loadSettings()} disabled={loading} className="shrink-0 font-semibold underline disabled:opacity-50">Retry loading</button>
        </div>
      )}
      {notice && <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</div>}
      {loading ? <p role="status" className="rounded-xl bg-slate-50 py-8 text-center text-sm text-slate-500">Loading booking settings…</p> : (
        <div className="space-y-6">
            <section>
              <h3 className="font-semibold text-slate-900">Session types and duration</h3>
              <p className="mt-1 text-sm text-slate-600">Inactive session types remain in history but are unavailable for new bookings.</p>
              <div className="mt-3 space-y-2">
                {sessionTypes.length === 0 && (
                  <p className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                    No session types are configured yet.
                  </p>
                )}
                {sessionTypes.map((session) => (
                  <SessionTypeRow
                    key={session.id}
                    session={session}
                    prices={priceVersions.filter((price) => Number(price.session_type_id) === Number(session.id))}
                    onSave={saveSession}
                    onSavePrice={savePrice}
                    onRetirePrice={retirePrice}
                    saving={savingSession}
                  />
                ))}
              </div>
              <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_180px_auto]">
                <input aria-label="New session name" value={newSession.name} onChange={(event) => setNewSession((current) => ({ ...current, name: event.target.value }))} maxLength={64} placeholder="New session type" className="min-w-0 rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100" />
                <select aria-label="New session duration" value={newSession.duration_minutes} onChange={(event) => setNewSession((current) => ({ ...current, duration_minutes: Number(event.target.value) }))} className="rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100">
                  {Array.from({ length: 32 }, (_, index) => (index + 1) * 15).map((duration) => <option key={duration} value={duration}>{duration} minutes</option>)}
                </select>
                <select aria-label="New session category" value={newSession.service_category} onChange={(event) => setNewSession((current) => ({ ...current, service_category: event.target.value }))} className="rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100">
                  <option value="other">Other service</option>
                  <option value="personal_training">Personal Training</option>
                </select>
                <button type="button" onClick={() => saveSession(newSession)} disabled={!newSession.name.trim() || savingSession} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900 disabled:cursor-not-allowed disabled:opacity-50"><FaPlus /> {savingSession ? 'Saving…' : 'Add'}</button>
              </div>
            </section>

            <form onSubmit={saveBookingWindow} className="rounded-2xl border border-slate-200 bg-white p-4">
              <h3 className="font-semibold text-slate-900">Advance booking window</h3>
              <p className="mt-1 text-sm text-slate-600">Members may request sessions up to this many days ahead. The server enforces this limit.</p>
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <label className="text-sm font-medium text-slate-700">
                  Maximum days ahead
                  <input type="number" min="1" max="365" value={maxAdvanceDays} onChange={(event) => setMaxAdvanceDays(event.target.value)} required className="mt-1 block w-40 rounded-xl border border-slate-300 px-3 py-2 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100" />
                </label>
                <button type="submit" disabled={savingWindow} className="min-h-10 rounded-xl bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-900 disabled:opacity-50">{savingWindow ? 'Saving…' : 'Save booking window'}</button>
              </div>
            </form>

            <form onSubmit={saveHours} className="rounded-2xl border border-slate-200 bg-white p-4">
              <h3 className="font-semibold text-slate-900">Gym operating hours</h3>
              <div className="mt-3 space-y-2">
                {DAYS.map((day) => (
                  <div key={day} className="grid items-center gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-[1fr_100px_1fr_1fr]">
                    <span className="text-sm font-medium text-slate-700">{day}</span>
                    <label className="flex items-center gap-2 text-sm text-slate-600">
                      <input type="checkbox" checked={hours[day]?.is_open || false} onChange={(event) => setHours((current) => ({ ...current, [day]: { ...current[day], is_open: event.target.checked } }))} />
                      Open
                    </label>
                    <input type="time" aria-label={`${day} opening time`} disabled={!hours[day]?.is_open} value={hours[day]?.opens_at || ''} onChange={(event) => setHours((current) => ({ ...current, [day]: { ...current[day], opens_at: event.target.value } }))} className="min-w-0 rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-100" required={hours[day]?.is_open} />
                    <input type="time" aria-label={`${day} closing time`} disabled={!hours[day]?.is_open} value={hours[day]?.closes_at || ''} onChange={(event) => setHours((current) => ({ ...current, [day]: { ...current[day], closes_at: event.target.value } }))} className="min-w-0 rounded-lg border border-slate-300 px-2 py-2 text-sm disabled:bg-slate-100" required={hours[day]?.is_open} />
                  </div>
                ))}
              </div>
              <div className="mt-4 flex justify-end">
                <button type="submit" disabled={savingHours} className="min-h-10 rounded-xl bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-900 disabled:opacity-50">{savingHours ? 'Saving…' : 'Save operating hours'}</button>
              </div>
            </form>
          </div>
      )}
    </div>
  )
}

function SessionTypeRow({ session, prices, onSave, onSavePrice, onRetirePrice, saving }) {
  const [value, setValue] = useState({
    id: Number(session.id),
    name: session.name,
    duration_minutes: Number(session.duration_minutes),
    service_category: session.service_category || 'other',
    is_active: Boolean(Number(session.is_active)),
  })
  const [priceAmount, setPriceAmount] = useState('')
  const [effectiveFrom, setEffectiveFrom] = useState('')
  const currentPrice = prices.find((price) => Number(price.is_current) === 1)

  return (
    <div className="min-w-0 space-y-3 rounded-xl bg-slate-50 p-3">
      <div className="grid min-w-0 items-center gap-2 sm:grid-cols-[minmax(0,1fr)_150px_180px_100px_auto]">
        <input aria-label="Session name" value={value.name} onChange={(event) => setValue((current) => ({ ...current, name: event.target.value }))} maxLength={64} className="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100" />
        <select aria-label="Session duration" value={value.duration_minutes} onChange={(event) => setValue((current) => ({ ...current, duration_minutes: Number(event.target.value) }))} className="min-w-0 rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100">
          {Array.from({ length: 32 }, (_, index) => (index + 1) * 15).map((duration) => <option key={duration} value={duration}>{duration} minutes</option>)}
        </select>
        <select aria-label="Session category" value={value.service_category} onChange={(event) => setValue((current) => ({ ...current, service_category: event.target.value }))} className="min-w-0 rounded-lg border border-slate-300 px-2 py-2 text-sm">
          <option value="other">Other service</option>
          <option value="personal_training">Personal Training</option>
        </select>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={value.is_active} onChange={(event) => setValue((current) => ({ ...current, is_active: event.target.checked }))} />
          Active
        </label>
        <button type="button" onClick={() => onSave(value)} disabled={saving} className="rounded-lg border border-cyan-200 bg-white px-3 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50 disabled:opacity-50">{saving ? 'Saving…' : 'Save'}</button>
      </div>
      <div className="border-t border-slate-200 pt-3">
        <p className="text-sm font-semibold text-slate-800">
          Current price: {currentPrice ? `PHP ${Number(currentPrice.amount).toFixed(2)}` : 'Not configured'}
        </p>
        {prices.filter((price) => Number(price.is_scheduled) === 1).map((price) => (
          <div key={price.id} className="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
            <span>Scheduled: PHP {Number(price.amount).toFixed(2)} from {String(price.effective_from).slice(0, 16)}</span>
            <button type="button" onClick={() => onRetirePrice(Number(price.id))} disabled={saving} className="font-semibold text-rose-700 underline disabled:opacity-50">Retire</button>
          </div>
        ))}
        {prices.filter((price) => Number(price.is_current) === 1).map((price) => (
          <div key={price.id} className="mt-1 text-xs text-slate-500">
            Effective since {String(price.effective_from).slice(0, 16)}
            <button type="button" onClick={() => onRetirePrice(Number(price.id))} disabled={saving} className="ml-3 font-semibold text-rose-700 underline disabled:opacity-50">Retire current price</button>
          </div>
        ))}
        <form
          className="mt-3 grid gap-2 sm:grid-cols-[140px_minmax(0,1fr)_auto]"
          onSubmit={(event) => {
            event.preventDefault()
            onSavePrice({ session_type_id: Number(session.id), amount: priceAmount, effective_from: effectiveFrom })
            setPriceAmount('')
            setEffectiveFrom('')
          }}
        >
          <input aria-label={`Price for ${session.name}`} type="number" min="0.01" step="0.01" required value={priceAmount} onChange={(event) => setPriceAmount(event.target.value)} placeholder="Price (PHP)" className="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input aria-label={`Price effective date for ${session.name}`} type="datetime-local" value={effectiveFrom} onChange={(event) => setEffectiveFrom(event.target.value)} className="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <button type="submit" disabled={saving || !priceAmount} className="rounded-lg bg-cyan-800 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{saving ? 'Saving…' : 'Set price'}</button>
        </form>
        <details className="mt-2 text-xs text-slate-600">
          <summary className="cursor-pointer font-semibold">Price history</summary>
          <ul className="mt-1 space-y-1">
            {prices.map((price) => (
              <li key={price.id}>
                PHP {Number(price.amount).toFixed(2)} · from {String(price.effective_from).slice(0, 16)}
                {price.retired_at && ` · retired ${String(price.retired_at).slice(0, 16)}`}
              </li>
            ))}
            {prices.length === 0 && <li>No price versions yet.</li>}
          </ul>
        </details>
      </div>
    </div>
  )
}
