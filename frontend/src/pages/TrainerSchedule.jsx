import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FaArrowLeft, FaCalendarAlt, FaEdit, FaPlus, FaTrash, FaUtensils } from 'react-icons/fa'
import api from '../services/api'
import ShiftModal from '../components/ShiftModal.jsx'
import TrainerWeeklySchedule from '../components/TrainerWeeklySchedule.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import { formatTime12Hour } from '../utils/timeFormat.js'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const SHIFT_TYPES = [
  { value: 'morning', label: 'Morning Shift' },
  { value: 'afternoon', label: 'Afternoon Shift' },
  { value: 'night', label: 'Night Shift' },
]

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('user') || sessionStorage.getItem('user') || 'null')
  } catch {
    return null
  }
}

function newShiftDraft(day = '') {
  return {
    clientKey: `new-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    id: null,
    day_of_week: day,
    schedule_type: 'morning',
    start_time: '',
    end_time: '',
    lunch_enabled: true,
    status: 'active',
    start_time_valid: false,
    end_time_valid: false,
    savedValues: null,
  }
}

function toShiftDraft(slot) {
  const row = {
    clientKey: `slot-${slot.id}`,
    id: Number(slot.id),
    day_of_week: slot.day_of_week,
    schedule_type: slot.schedule_type || 'custom',
    start_time: String(slot.start_time || '').slice(0, 5),
    end_time: String(slot.end_time || '').slice(0, 5),
    lunch_enabled: Boolean(slot.lunch_start && slot.lunch_end),
    status: slot.status,
    start_time_valid: true,
    end_time_valid: true,
  }
  row.savedValues = getComparableValues(row)
  return row
}

function flattenSchedule(schedule) {
  return DAYS.flatMap((day) => schedule[day] || [])
}

function getComparableValues(row) {
  return {
    day_of_week: row.day_of_week,
    schedule_type: row.schedule_type,
    start_time: row.start_time,
    end_time: row.end_time,
    lunch_enabled: row.lunch_enabled,
  }
}

function isShiftDirty(row) {
  if (!row.savedValues) return true
  return Object.entries(getComparableValues(row)).some(([key, value]) => value !== row.savedValues[key])
}

function shiftLabel(value) {
  return SHIFT_TYPES.find((shift) => shift.value === value)?.label || 'Custom Schedule'
}

function getRequestError(requestError) {
  const status = requestError?.response?.status
  if (status === 401) return 'Your session has expired. Sign in again, then retry your schedule change.'
  if (status === 403) return 'You do not have permission to change trainer schedules. Sign in with an administrator account.'
  if (!requestError?.response && (requestError?.request || requestError?.code === 'ERR_NETWORK')) return 'The server could not be reached. Check your connection and retry; your unsaved changes are still here.'
  if (status >= 500) return 'The server could not complete this schedule change. Please retry in a moment.'
  return requestError?.response?.data?.message || 'The schedule change could not be completed. Review the shift details and try again.'
}

function TrainerSchedule() {
  const navigate = useNavigate()
  const [user] = useState(() => getStoredUser())
  const role = String(user?.role || '').toLowerCase()
  const isAdmin = role === 'admin' || role === 'administrator'
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [schedule, setSchedule] = useState({})
  const [exceptions, setExceptions] = useState([])
  const [exceptionForm, setExceptionForm] = useState({
    exception_date: '',
    exception_type: 'off',
    start_time: '',
    end_time: '',
    reason: '',
  })
  const [savingException, setSavingException] = useState(false)
  const [shiftRows, setShiftRows] = useState([])
  const [adminTrainers, setAdminTrainers] = useState([])
  const [selectedTrainerId, setSelectedTrainerId] = useState(() => new URLSearchParams(window.location.search).get('trainer_id') || '')
  const initialTrainerId = useRef(selectedTrainerId)
  const [savingKeys, setSavingKeys] = useState([])
  const savingKeysRef = useRef(new Set())
  const recentSaveAttemptsRef = useRef(new Map())
  const [confirmation, setConfirmation] = useState(null)
  const [confirmationLoading, setConfirmationLoading] = useState(false)
  const [shiftErrors, setShiftErrors] = useState({})
  const [pendingNavigation, setPendingNavigation] = useState(null)
  const [activeModalDraft, setActiveModalDraft] = useState(null)

  const fetchSchedule = useCallback(async () => {
    if (!user || (!isAdmin && role !== 'trainer')) {
      navigate('/login', { replace: true })
      return
    }
    if (isAdmin && !selectedTrainerId) {
      setSchedule({})
      setShiftRows([])
      setLoading(false)
      return
    }

    setLoading(true)
    setError('')
    try {
      const params = isAdmin ? { trainer_id: selectedTrainerId } : {}
      const response = await api.get('/trainer_availability.php', { params })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load the trainer schedule.')

      const nextSchedule = response.data.schedule || {}
      setSchedule(nextSchedule)
      setExceptions(response.data.exceptions || [])
      if (isAdmin) {
        setShiftRows(flattenSchedule(nextSchedule)
          .filter((slot) => String(slot.status).toLowerCase() === 'active')
          .map(toShiftDraft))
      }
    } catch (requestError) {
      setError(getRequestError(requestError))
    } finally {
      setLoading(false)
    }
  }, [isAdmin, navigate, role, selectedTrainerId, user])

  useEffect(() => {
    if (!isAdmin) return
    let active = true
    const loadTrainers = async () => {
      const firstPage = await api.get('/trainers.php', { params: { page: 1, per_page: 100 } })
      const meta = firstPage.data?.meta || {}
      const pages = Array.from({ length: Math.max(0, Number(meta.total_pages || 1) - 1) }, (_, index) => index + 2)
      const remainingPages = await Promise.all(pages.map((page) => api.get('/trainers.php', { params: { page, per_page: 100 } })))
      if (!active) return
      const trainers = [
        ...(firstPage.data?.trainers || []),
        ...remainingPages.flatMap((response) => response.data?.trainers || []),
      ]
      setAdminTrainers(trainers)
      if (!initialTrainerId.current && trainers[0]?.trainer_id) setSelectedTrainerId(String(trainers[0].trainer_id))
    }
    loadTrainers().catch((requestError) => {
      if (active) setError(getRequestError(requestError))
    })
    return () => { active = false }
  }, [isAdmin])

  useEffect(() => {
    void Promise.resolve().then(() => fetchSchedule())
  }, [fetchSchedule])

  const hasUnsavedChanges = useMemo(() => isAdmin && shiftRows.some(isShiftDirty), [isAdmin, shiftRows])

  useEffect(() => {
    if (!hasUnsavedChanges) return undefined
    const warnBeforeUnload = (event) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', warnBeforeUnload)
    return () => window.removeEventListener('beforeunload', warnBeforeUnload)
  }, [hasUnsavedChanges])

  const updateShift = (clientKey, field, value) => {
    setShiftRows((current) => current.map((row) => {
      if (row.clientKey !== clientKey) return row
      return { ...row, [field]: value }
    }))
    setShiftErrors((current) => ({ ...current, [clientKey]: '' }))
  }

  const validateShift = (row, rows = shiftRows) => {
    if (!DAYS.includes(row.day_of_week)) return { field: 'day_of_week', message: 'Choose a valid day for this shift.' }
    if (!SHIFT_TYPES.some((shift) => shift.value === row.schedule_type)) return { field: 'schedule_type', message: 'Choose Morning, Afternoon, or Night Shift.' }
    if (!row.start_time_valid || !row.start_time) return { field: 'start_time', message: 'Complete the start time by selecting an hour, minute, and AM/PM.' }
    if (!row.end_time_valid || !row.end_time) return { field: 'end_time', message: 'Complete the end time by selecting an hour, minute, and AM/PM.' }
    if (row.start_time === row.end_time || row.end_time < row.start_time) return { field: 'end_time', message: 'The end time must be later than the start time. Overnight shifts are not supported.' }
    if (row.lunch_enabled && (row.start_time > '12:00' || row.end_time < '13:00')) {
      return { field: 'lunch_enabled', message: 'The 12:00 PM–1:00 PM lunch break must fit within this shift. Disable lunch or adjust the hours.' }
    }

    const overlaps = rows.some((other) => {
      if (other.clientKey === row.clientKey || other.status !== 'active' || other.day_of_week !== row.day_of_week) return false
      if (!other.start_time || !other.end_time) return false
      return row.start_time < other.end_time && other.start_time < row.end_time
    })
    return overlaps ? { field: 'time_range', message: 'This time overlaps another active shift on the same day. Back-to-back shifts are allowed; adjust one of the hours.' } : null
  }

  const saveShift = async (row) => {
    if (savingKeysRef.current.has(row.clientKey)) return
    const now = Date.now()
    if (now - (recentSaveAttemptsRef.current.get(row.clientKey) || 0) < 750) return
    if (!selectedTrainerId) {
      setError('Select a trainer before saving a shift.')
      return
    }
    const validationError = validateShift(row)
    if (validationError) {
      setShiftErrors((current) => ({ ...current, [row.clientKey]: validationError }))
      setError('')
      return
    }

    recentSaveAttemptsRef.current.set(row.clientKey, now)
    savingKeysRef.current.add(row.clientKey)
    setSavingKeys((current) => [...current, row.clientKey])
    setShiftErrors((current) => ({ ...current, [row.clientKey]: null }))
    setError('')
    setSuccess('')
    try {
      const action = row.id ? 'update' : 'create'
      const response = await api.post(`/trainer_availability.php?action=${action}`, {
        trainer_id: selectedTrainerId,
        ...(row.id ? { id: row.id } : {}),
        day_of_week: row.day_of_week,
        shift_type: row.schedule_type,
        start_time: row.start_time,
        end_time: row.end_time,
        lunch_enabled: row.lunch_enabled,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save this shift.')
      setSuccess(response.data.message || 'Trainer shift saved successfully.')
      const savedRow = {
        ...row,
        id: Number(response.data.id),
      }
      savedRow.savedValues = getComparableValues(savedRow)
      setShiftRows((current) => current.map((item) => item.clientKey === row.clientKey ? savedRow : item))
      setActiveModalDraft(null)
      setShiftErrors((current) => {
        const next = { ...current }
        delete next[row.clientKey]
        return next
      })
    } catch (requestError) {
      const message = getRequestError(requestError)
      setShiftErrors((current) => ({ ...current, [row.clientKey]: { field: 'request', message } }))
      setError('')
    } finally {
      savingKeysRef.current.delete(row.clientKey)
      setSavingKeys((current) => current.filter((key) => key !== row.clientKey))
    }
  }

  const requestRemoveShift = (row) => {
    const assignedDay = row.savedValues?.day_of_week || row.day_of_week
    const assignedType = row.savedValues?.schedule_type || row.schedule_type
    setConfirmation({
      type: 'remove',
      row,
      title: row.id ? 'Remove trainer shift?' : 'Discard new shift?',
      message: row.id
        ? `Are you sure you want to remove the ${assignedDay} ${shiftLabel(assignedType)}? This action will deactivate the shift assignment. Historical attendance records will be preserved.`
        : 'Are you sure you want to discard this unsaved shift? No schedule record has been created.',
      confirmText: row.id ? 'Remove Shift' : 'Discard Shift',
      variant: 'danger',
    })
  }

  const requestCancelEdit = (row) => {
    if (isShiftDirty(row)) {
      const savedDay = row.savedValues?.day_of_week || row.day_of_week
      const savedType = row.savedValues?.schedule_type || row.schedule_type
      setConfirmation({
        type: 'discard-shift',
        row,
        title: 'Discard unsaved shift changes?',
        message: row.id
          ? `Discard the unsaved changes to the ${savedDay} ${shiftLabel(savedType)}? The saved shift will remain unchanged.`
          : 'Discard this new shift? No schedule record has been created.',
        confirmText: 'Discard Changes',
        cancelText: 'Continue Editing',
        variant: 'warning',
      })
      return
    }
    setActiveModalDraft(null)
  }

  const confirmRemoveShift = async () => {
    const row = confirmation?.row
    if (!row || confirmationLoading || savingKeysRef.current.has(row.clientKey)) return
    if (!row.id) {
      setShiftRows((current) => current.filter((item) => item.clientKey !== row.clientKey))
      setShiftErrors((current) => {
        const next = { ...current }
        delete next[row.clientKey]
        return next
      })
      setConfirmation(null)
      return
    }

    savingKeysRef.current.add(row.clientKey)
    setSavingKeys((current) => [...current, row.clientKey])
    setConfirmationLoading(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.post('/trainer_availability.php?action=delete', {
        trainer_id: selectedTrainerId,
        id: row.id,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to remove this shift.')
      setSuccess(response.data.message || 'Trainer shift removed.')
      setShiftRows((current) => current.filter((item) => item.clientKey !== row.clientKey))
      setShiftErrors((current) => {
        const next = { ...current }
        delete next[row.clientKey]
        return next
      })
      setConfirmation(null)
    } catch (requestError) {
      const message = getRequestError(requestError)
      setShiftErrors((current) => ({ ...current, [row.clientKey]: { field: 'request', message } }))
      setConfirmation(null)
    } finally {
      savingKeysRef.current.delete(row.clientKey)
      setSavingKeys((current) => current.filter((key) => key !== row.clientKey))
      setConfirmationLoading(false)
    }
  }

  const requestSwitchTrainer = (nextTrainerId) => {
    if (nextTrainerId === selectedTrainerId) return
    if (hasUnsavedChanges) {
      setPendingNavigation({ type: 'trainer', trainerId: nextTrainerId })
      setConfirmation({
        type: 'discard',
        title: 'Discard unsaved shift changes?',
        message: 'This trainer has unsaved shift changes. Stay to keep editing, or discard the changes and switch trainers.',
        confirmText: 'Discard Changes',
        cancelText: 'Stay on This Trainer',
        variant: 'warning',
      })
      return
    }
    setSelectedTrainerId(nextTrainerId)
  }

  const requestLeavePage = (event) => {
    if (savingKeysRef.current.size) {
      event.preventDefault()
      setError('Wait for the current schedule request to finish before leaving this page.')
      return
    }
    if (!hasUnsavedChanges) return
    event.preventDefault()
    setPendingNavigation({ type: 'route', path: isAdmin ? '/trainer/manage' : '/trainer' })
    setConfirmation({
      type: 'discard',
      title: 'Leave with unsaved changes?',
      message: 'This schedule has unsaved shift changes. Stay to keep editing, or discard them and leave this page.',
      confirmText: 'Discard Changes',
      cancelText: 'Stay on Page',
      variant: 'warning',
    })
  }

  const confirmDiscardChanges = () => {
    if (confirmation?.type === 'discard-shift') {
      const { row } = confirmation
      if (row.id) {
        setShiftRows((current) => current.map((item) => item.clientKey === row.clientKey
          ? { ...item, ...item.savedValues, start_time_valid: true, end_time_valid: true }
          : item))
      } else {
        setShiftRows((current) => current.filter((item) => item.clientKey !== row.clientKey))
      }
      setShiftErrors((current) => {
        const next = { ...current }
        delete next[row.clientKey]
        return next
      })
      setActiveModalDraft(null)
      setConfirmation(null)
      return
    }
    const target = pendingNavigation
    setConfirmation(null)
    setPendingNavigation(null)
    if (target?.type === 'trainer') {
      setShiftErrors({})
      setSuccess('')
      setError('')
      setSelectedTrainerId(target.trainerId)
    } else if (target?.type === 'route') {
      navigate(target.path)
    }
  }

  const saveException = async (event) => {
    event.preventDefault()
    if (!selectedTrainerId || savingException) return
    setSavingException(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.post('/trainer_availability.php?action=exception-create', {
        trainer_id: selectedTrainerId,
        ...exceptionForm,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to save schedule exception.')
      setSuccess(response.data.message || 'Schedule exception saved.')
      setExceptionForm({ exception_date: '', exception_type: 'off', start_time: '', end_time: '', reason: '' })
      await fetchSchedule()
    } catch (requestError) {
      setError(getRequestError(requestError))
    } finally {
      setSavingException(false)
    }
  }

  const removeException = async (exceptionId) => {
    setError('')
    setSuccess('')
    try {
      const response = await api.post('/trainer_availability.php?action=exception-delete', {
        trainer_id: selectedTrainerId,
        id: exceptionId,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to remove schedule exception.')
      setSuccess(response.data.message || 'Schedule exception removed.')
      setExceptions((current) => current.filter((item) => Number(item.id) !== Number(exceptionId)))
    } catch (requestError) {
      setError(getRequestError(requestError))
    }
  }

  const title = isAdmin ? 'Assign trainer schedule' : 'My assigned schedule'
  const displaySchedule = flattenSchedule(schedule)
  const selectedTrainer = adminTrainers.find((trainer) => String(trainer.trainer_id) === String(selectedTrainerId))
  const savedActiveShifts = shiftRows.filter((row) => row.id && row.status === 'active')
  const scheduledDays = new Set(savedActiveShifts.map((row) => row.day_of_week)).size
  const lunchEnabledShifts = savedActiveShifts.filter((row) => row.lunch_enabled).length
  const getSectionDay = (row) => row.id && row.savedValues ? row.savedValues.day_of_week : row.day_of_week

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div className="flex items-center gap-3">
            <Link to={isAdmin ? '/trainer/manage' : '/trainer'} onClick={requestLeavePage} className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50" title="Back">
              <FaArrowLeft />
            </Link>
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-slate-500">{isAdmin ? 'Trainer Management' : 'Trainer Dashboard'}</p>
              <h1 className="text-xl font-semibold sm:text-2xl">{title}</h1>
            </div>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        {error && <div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>}
        {success && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{success}</div>}

        {isAdmin ? (
          <>
            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
              <div className="flex flex-wrap items-end justify-between gap-4">
                <label className="block w-full text-sm font-medium text-slate-700 lg:max-w-xl">
                  Select trainer
                  <select
                    value={selectedTrainerId}
                    onChange={(event) => requestSwitchTrainer(event.target.value)}
                    disabled={savingKeys.length > 0}
                    className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm"
                  >
                    <option value="">Select a trainer</option>
                    {adminTrainers.map((trainer) => <option key={trainer.trainer_id} value={trainer.trainer_id}>{trainer.full_name} ({trainer.trainer_code})</option>)}
                  </select>
                </label>
              </div>
              <p className="mt-4 text-sm text-slate-600">Add Morning, Afternoon, and Night shifts independently. Each shift has its own day, hours, and optional lunch break; adding or editing one shift does not replace the others.</p>

              {selectedTrainerId && !loading && (
                <div className="mt-4 grid grid-cols-2 gap-2 rounded-2xl bg-slate-50 p-3 sm:grid-cols-4">
                  <div className="min-w-0">
                    <p className="text-xs text-slate-500">Trainer</p>
                    <p className="truncate text-sm font-semibold text-slate-800">{selectedTrainer?.full_name || 'Selected trainer'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-slate-500">Active shifts</p>
                    <p className="text-sm font-semibold text-slate-800">{savedActiveShifts.length}</p>
                  </div>
                  <div>
                    <p className="text-xs text-slate-500">Days scheduled</p>
                    <p className="text-sm font-semibold text-slate-800">{scheduledDays} of 7</p>
                  </div>
                  <div>
                    <p className="text-xs text-slate-500">Lunch enabled</p>
                    <p className="text-sm font-semibold text-slate-800">{lunchEnabledShifts} shifts</p>
                  </div>
                </div>
              )}

              {loading ? (
                <div className="mt-5 rounded-2xl bg-slate-50 p-8 text-center text-sm text-slate-500">Loading assigned shifts…</div>
              ) : !selectedTrainerId ? (
                <div className="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-600">Select a trainer to view and manage their weekly shifts.</div>
              ) : (
                <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {DAYS.map((day) => {
                    const dayRows = shiftRows
                      .filter((row) => getSectionDay(row) === day)
                      .sort((first, second) => first.start_time.localeCompare(second.start_time))
                    return (
                      <section key={day} aria-labelledby={`schedule-${day.toLowerCase()}`} className="flex min-w-0 flex-col rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:shadow-md">
                        <div className="mb-3 flex items-center justify-between border-b border-slate-100 pb-3">
                          <div className="flex items-center gap-2">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-50 text-violet-700">
                              <FaCalendarAlt className="text-xs" />
                            </div>
                            <h2 id={`schedule-${day.toLowerCase()}`} className="font-bold text-sm tracking-wide text-slate-800 uppercase">{day}</h2>
                          </div>
                          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                            {dayRows.length} {dayRows.length === 1 ? 'shift' : 'shifts'}
                          </span>
                        </div>
                        <div className="flex flex-1 flex-col gap-2.5">
                          {dayRows.length === 0 ? (
                            <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50/60 py-6 px-3 text-center">
                              <p className="text-xs font-medium text-slate-400">No shifts assigned</p>
                            </div>
                          ) : (
                            dayRows.map((row) => {
                              const isSaving = savingKeys.includes(row.clientKey)
                              return (
                                <article key={row.clientKey} className="group rounded-xl border border-slate-200/90 bg-slate-50/40 p-3 transition hover:border-slate-300 hover:bg-white shadow-2xs">
                                  <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 flex-1">
                                      <div className="flex items-center gap-1.5 flex-wrap">
                                        <span className="inline-block rounded-md bg-violet-100/70 px-2 py-0.5 text-[11px] font-semibold text-violet-800">
                                          {shiftLabel(row.schedule_type)}
                                        </span>
                                        {isShiftDirty(row) && (
                                          <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                            Unsaved
                                          </span>
                                        )}
                                      </div>
                                      <p className="mt-1.5 text-xs font-bold text-slate-800">
                                        {formatTime12Hour(row.start_time)} – {formatTime12Hour(row.end_time)}
                                      </p>
                                      {row.lunch_enabled ? (
                                        <p className="mt-1 flex items-center gap-1 text-[11px] font-medium text-amber-700">
                                          <FaUtensils className="text-[10px]" />
                                          <span>Lunch: 12:00 PM – 1:00 PM</span>
                                        </p>
                                      ) : (
                                        <p className="mt-1 text-[11px] text-slate-400">
                                          No lunch break
                                        </p>
                                      )}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                      <button
                                        type="button"
                                        onClick={() => {
                                          setActiveModalDraft({ ...row })
                                          setShiftErrors((current) => ({ ...current, [row.clientKey]: null }))
                                        }}
                                        aria-label={`Edit ${day} ${shiftLabel(row.schedule_type)}`}
                                        title={`Edit ${day} ${shiftLabel(row.schedule_type)}`}
                                        className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-violet-50 hover:text-violet-700 transition"
                                      >
                                        <FaEdit className="text-xs" />
                                      </button>
                                      <button
                                        type="button"
                                        onClick={() => requestRemoveShift(row)}
                                        disabled={isSaving}
                                        aria-label={`Remove ${day} ${shiftLabel(row.schedule_type)}`}
                                        title={`Remove ${day} ${shiftLabel(row.schedule_type)}`}
                                        className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-700 transition disabled:opacity-50"
                                      >
                                        <FaTrash className="text-xs" />
                                      </button>
                                    </div>
                                  </div>
                                  {shiftErrors[row.clientKey]?.field === 'request' && (
                                    <p role="alert" className="mt-2 rounded-lg bg-rose-50 p-2 text-xs text-rose-700">
                                      {shiftErrors[row.clientKey].message}
                                    </p>
                                  )}
                                </article>
                              )
                            })
                          )}
                          <button
                            type="button"
                            onClick={() => {
                              const draft = newShiftDraft(day)
                              setActiveModalDraft(draft)
                              setShiftErrors((current) => ({ ...current, [draft.clientKey]: null }))
                              setError('')
                              setSuccess('')
                            }}
                            disabled={!selectedTrainerId || loading}
                            className="mt-auto inline-flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 bg-white py-2 px-3 text-xs font-semibold text-slate-700 hover:border-violet-400 hover:bg-violet-50/50 hover:text-violet-700 transition disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            <FaPlus className="text-xs" /> Add Shift to {day}
                          </button>
                        </div>
                      </section>
                    )
                  })}
                </div>
              )}
            </section>

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
              <h2 className="text-lg font-semibold text-slate-900">Temporary schedule changes and leave</h2>
              <p className="mt-1 text-sm text-slate-600">A day off replaces the weekly shift for that date. Temporary working shifts add availability; blocked periods remove it.</p>
              {selectedTrainerId ? (
                <>
                  <form onSubmit={saveException} className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label className="text-sm text-slate-700">Date
                      <input type="date" min={new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })} value={exceptionForm.exception_date} onChange={(event) => setExceptionForm((current) => ({ ...current, exception_date: event.target.value }))} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                    <label className="text-sm text-slate-700">Change
                      <select value={exceptionForm.exception_type} onChange={(event) => setExceptionForm((current) => ({ ...current, exception_type: event.target.value }))} className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
                        <option value="off">Day off / leave</option>
                        <option value="available">Temporary working shift</option>
                        <option value="blocked">Blocked period</option>
                      </select>
                    </label>
                    {exceptionForm.exception_type !== 'off' && <>
                      <label className="text-sm text-slate-700">Start
                        <input type="time" value={exceptionForm.start_time} onChange={(event) => setExceptionForm((current) => ({ ...current, start_time: event.target.value }))} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" />
                      </label>
                      <label className="text-sm text-slate-700">End
                        <input type="time" value={exceptionForm.end_time} onChange={(event) => setExceptionForm((current) => ({ ...current, end_time: event.target.value }))} required className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" />
                      </label>
                    </>}
                    <label className="text-sm text-slate-700 md:col-span-2">Reason (optional)
                      <input maxLength={255} value={exceptionForm.reason} onChange={(event) => setExceptionForm((current) => ({ ...current, reason: event.target.value }))} placeholder="Leave, appointment, temporary coverage…" className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </label>
                    <div className="flex items-end">
                      <button type="submit" disabled={savingException} className="w-full rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{savingException ? 'Saving…' : 'Add exception'}</button>
                    </div>
                  </form>
                  <div className="mt-5 space-y-2">
                    {exceptions.length === 0 ? <p className="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-500">No upcoming exceptions.</p> : exceptions.map((exception) => (
                      <div key={exception.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm">
                        <div>
                          <p className="font-semibold text-slate-800">{exception.exception_date} · {exception.exception_type === 'off' ? 'Day off / leave' : exception.exception_type === 'available' ? 'Temporary shift' : 'Blocked period'}</p>
                          {exception.start_time && <p className="text-slate-600">{exception.start_time}–{exception.end_time}</p>}
                          {exception.reason && <p className="text-xs text-slate-500">{exception.reason}</p>}
                        </div>
                        {isAdmin && <button type="button" onClick={() => removeException(exception.id)} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-medium text-rose-700">Remove</button>}
                      </div>
                    ))}
                  </div>
                </>
              ) : <p className="mt-4 text-sm text-slate-500">Select a trainer to manage dated changes.</p>}
            </section>

          </>
        ) : loading ? (
          <div className="rounded-2xl bg-white p-8 text-center text-sm text-slate-500">Loading your assigned schedule…</div>
        ) : (
          <>
            <TrainerWeeklySchedule schedule={displaySchedule} />
            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="font-semibold text-slate-900">Upcoming schedule exceptions</h2>
              {exceptions.length === 0 ? <p className="mt-3 text-sm text-slate-500">No upcoming exceptions.</p> : (
                <ul className="mt-3 space-y-2">
                  {exceptions.map((exception) => <li key={exception.id} className="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{exception.exception_date} · {exception.exception_type === 'off' ? 'Day off / leave' : exception.exception_type === 'available' ? `Temporary shift ${exception.start_time}–${exception.end_time}` : `Blocked ${exception.start_time}–${exception.end_time}`}{exception.reason ? ` · ${exception.reason}` : ''}</li>)}
                </ul>
              )}
            </section>
          </>
        )}
      </main>
      <ShiftModal
        open={Boolean(activeModalDraft)}
        draft={activeModalDraft}
        onClose={() => {
          if (activeModalDraft) {
            requestCancelEdit(activeModalDraft)
          }
        }}
        onSave={() => {
          if (activeModalDraft) {
            saveShift(activeModalDraft)
          }
        }}
        onUpdateDraft={(field, value) => {
          if (!activeModalDraft) return
          updateShift(activeModalDraft.clientKey, field, value)
          setActiveModalDraft((prev) => (prev ? { ...prev, [field]: value } : prev))
        }}
        error={activeModalDraft ? shiftErrors[activeModalDraft.clientKey] : null}
        saving={activeModalDraft ? savingKeys.includes(activeModalDraft.clientKey) : false}
      />
      <ConfirmationModal
        open={Boolean(confirmation)}
        title={confirmation?.title || ''}
        message={confirmation?.message || ''}
        confirmText={confirmation?.confirmText || 'Confirm'}
        cancelText={confirmation?.cancelText || 'Cancel'}
        variant={confirmation?.variant || 'default'}
        loading={confirmationLoading}
        loadingText="Removing Shift..."
        onCancel={() => {
          if (!confirmationLoading) {
            setConfirmation(null)
            setPendingNavigation(null)
          }
        }}
        onConfirm={confirmation?.type === 'remove' ? confirmRemoveShift : confirmDiscardChanges}
      />
    </div>
  )
}

export default TrainerSchedule
