import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  FaCalculator,
  FaChartLine,
  FaCheckCircle,
  FaDumbbell,
  FaEdit,
  FaHistory,
  FaPlus,
  FaTrash,
} from 'react-icons/fa'
import api from '../services/api'
import ConfirmationModal from './ui/ConfirmationModal'

const fieldClass = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-violet-400'
const buttonClass = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-60'
const weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function getManilaToday() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function addCalendarDays(value, days) {
  const date = new Date(`${value}T12:00:00+08:00`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toLocaleDateString('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  })
}

function mondayOf(value) {
  const date = new Date(`${value}T12:00:00+08:00`)
  const day = date.getUTCDay() || 7
  return addCalendarDays(value, 1 - day)
}

function displayDate(value, options = { month: 'short', day: 'numeric' }) {
  if (!value) return ''
  return new Date(`${value}T00:00:00+08:00`).toLocaleDateString('en-PH', {
    ...options,
    timeZone: 'Asia/Manila',
  })
}

function createWorkoutRequestKey() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()

  const bytes = new Uint8Array(16)
  window.crypto.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

function getWeekdayIndex(value) {
  const day = new Date(`${value}T12:00:00+08:00`).getUTCDay()
  return day === 0 ? 6 : day - 1
}

function formatWeekRange(weekStart) {
  if (!weekStart) return ''
  return `${displayDate(weekStart)} – ${displayDate(addCalendarDays(weekStart, 6), {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })}`
}

function fitnessErrorMessage(error, fallback) {
  if (error?.response?.data?.message) return error.response.data.message
  const status = error?.response?.status
  if (status === 400) return 'Some workout details are invalid. Review them and try again.'
  if (status === 401) return 'Your session has expired. Sign in again, then retry your change.'
  if (status === 403) return 'You are not allowed to change this workout.'
  if (status === 404) return 'This workout could not be found. Refresh the plan and try again.'
  if (status === 409) return 'This workout has changed or cannot be changed in its current state. Refresh and try again.'
  if (status >= 500) return 'The fitness service could not save your change. Your workout was not confirmed; please retry.'
  if (error?.code === 'ECONNABORTED' || error?.code === 'ETIMEDOUT') {
    return 'The request timed out. Check your connection and try again.'
  }
  if (error?.message === 'Network Error' || error?.code === 'ERR_NETWORK') {
    return 'Unable to connect to the fitness service. Check your connection and try again.'
  }
  return error?.message || fallback
}

function isRetryableRequestError(error) {
  const status = error?.response?.status
  return !status || status === 408 || status === 429 || status >= 500
}

function makeEmptyActualExercise(exercise = {}) {
  return {
    planned_exercise_id: exercise.id || '',
    exercise_name: exercise.exercise_name || '',
    sets_completed: exercise.sets_completed != null
      ? String(exercise.sets_completed)
      : exercise.target_sets ? String(exercise.target_sets) : '',
    reps_completed: exercise.reps_completed || exercise.target_reps || '',
    actual_weight_kg: exercise.actual_weight_kg || exercise.target_weight_kg || '',
    duration_minutes: exercise.duration_minutes || exercise.target_duration_minutes || '',
    is_completed: exercise.is_completed ?? true,
  }
}

function getAge(dateOfBirth) {
  if (!dateOfBirth) return null
  const birth = new Date(`${String(dateOfBirth).slice(0, 10)}T12:00:00+08:00`)
  if (Number.isNaN(birth.getTime())) return null
  const today = new Date(`${getManilaToday()}T12:00:00+08:00`)
  let age = today.getUTCFullYear() - birth.getUTCFullYear()
  const monthDifference = today.getUTCMonth() - birth.getUTCMonth()
  if (monthDifference < 0 || (monthDifference === 0 && today.getUTCDate() < birth.getUTCDate())) age--
  return age
}

export default function MemberFitness({ member, sections = 'all' }) {
  const [weekStart, setWeekStart] = useState(() => mondayOf(getManilaToday()))
  const [manilaToday, setManilaToday] = useState(() => getManilaToday())
  const [weekData, setWeekData] = useState({ week: null, plans: [] })
  const [progress, setProgress] = useState(null)
  const [history, setHistory] = useState({ sessions: [], plannedCompletions: [] })
  const [historyFrom, setHistoryFrom] = useState(() => mondayOf(getManilaToday()))
  const [historyTo, setHistoryTo] = useState(() => addCalendarDays(mondayOf(getManilaToday()), 6))
  const [historyLoading, setHistoryLoading] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [weekError, setWeekError] = useState('')
  const [historyError, setHistoryError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [confirmation, setConfirmation] = useState(null)
  const [retryAction, setRetryAction] = useState(null)
  const [saving, setSaving] = useState(false)
  const [statusSaving, setStatusSaving] = useState(false)
  const planSaveInProgress = useRef(false)
  const deleteInProgress = useRef(false)
  const restDayInProgress = useRef(false)
  const approvedRestDaySave = useRef(false)
  const [planEditor, setPlanEditor] = useState(null)
  const planEditorInitial = useRef(null)
  const [planEditorError, setPlanEditorError] = useState('')
  const [planFieldErrors, setPlanFieldErrors] = useState({})
  const [workoutEditor, setWorkoutEditor] = useState(null)
  const [heightCm, setHeightCm] = useState('')
  const [weightKg, setWeightKg] = useState('')

  useEffect(() => {
    if (!successMessage) return undefined
    const timeoutId = window.setTimeout(() => setSuccessMessage(''), 4000)
    return () => window.clearTimeout(timeoutId)
  }, [successMessage])

  const age = useMemo(() => getAge(member?.date_of_birth), [member?.date_of_birth])
  const bmiResult = useMemo(() => {
    const height = Number(heightCm)
    const weight = Number(weightKg)
    if (!heightCm || !weightKg || !Number.isFinite(height) || !Number.isFinite(weight)
      || height < 80 || height > 250 || weight < 20 || weight > 500) return null
    const bmi = weight / ((height / 100) ** 2)
    let category = ''
    if (age === null || age >= 20) {
      if (bmi < 18.5) category = 'Underweight'
      else if (bmi < 25) category = 'Healthy weight range'
      else if (bmi < 30) category = 'Overweight'
      else category = 'Obesity range'
    }
    return { value: bmi.toFixed(1), category }
  }, [age, heightCm, weightKg])

  useEffect(() => {
    const refreshCurrentWeek = () => {
      const currentDate = getManilaToday()
      const currentWeekStart = mondayOf(currentDate)
      setManilaToday((previousDate) => (previousDate === currentDate ? previousDate : currentDate))
      if (weekStart !== currentWeekStart) {
        setLoading(true)
        setWeekStart(currentWeekStart)
        setHistoryFrom(currentWeekStart)
        setHistoryTo(addCalendarDays(currentWeekStart, 6))
      }
    }
    window.addEventListener('focus', refreshCurrentWeek)
    const intervalId = window.setInterval(refreshCurrentWeek, 60000)
    return () => {
      window.removeEventListener('focus', refreshCurrentWeek)
      window.clearInterval(intervalId)
    }
  }, [weekStart])

  const fetchWeek = useCallback(async () => {
    const [weekResponse, progressResponse] = await Promise.all([
        api.get('/member_fitness.php', { params: { action: 'week', week_start: weekStart } }),
        api.get('/member_fitness.php', { params: { action: 'progress', week_start: weekStart } }),
    ])
    if (!weekResponse.data?.success || !progressResponse.data?.success) {
      throw new Error(weekResponse.data?.message || progressResponse.data?.message || 'Unable to load your workout data.')
    }
    if (!Array.isArray(weekResponse.data.plans)
      || !progressResponse.data.stats
      || typeof progressResponse.data.stats !== 'object') {
      throw new Error('The fitness service returned incomplete workout data. Please try again.')
    }
    return {
      week: weekResponse.data.week,
      plans: weekResponse.data.plans,
      stats: progressResponse.data.stats,
    }
  }, [weekStart])

  const fetchHistory = useCallback(async () => {
    if (!historyFrom || !historyTo || historyFrom > historyTo) {
      throw new Error('Choose a valid workout history date range.')
    }
    const response = await api.get('/member_fitness.php', {
      params: { action: 'history', from: historyFrom, to: historyTo },
    })
    if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load workout history.')
    if (!Array.isArray(response.data.sessions) || !Array.isArray(response.data.planned_completions)) {
      throw new Error('The fitness service returned incomplete workout history. Please try again.')
    }
    return {
      sessions: response.data.sessions,
      plannedCompletions: response.data.planned_completions,
    }
  }, [historyFrom, historyTo])

  useEffect(() => {
    let active = true
    fetchWeek().then((data) => {
      if (!active) return
      setWeekError('')
      setWeekData({ week: data.week, plans: data.plans })
      setProgress(data.stats)
    }).catch((requestError) => {
      if (!active) return
      setProgress(null)
      setWeekError(fitnessErrorMessage(requestError, 'Unable to load your workout data.'))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [fetchWeek])

  useEffect(() => {
    let active = true
    fetchHistory().then((sessions) => {
      if (active) {
        setHistoryError('')
        setHistory(sessions)
        setHistoryLoading(false)
      }
    }).catch((requestError) => {
      if (!active) return
      setHistoryError(fitnessErrorMessage(requestError, 'Unable to load workout history.'))
      setHistoryLoading(false)
    })
    return () => { active = false }
  }, [fetchHistory])

  const historyGroups = useMemo(() => {
    const plannedCompletions = history.plannedCompletions || []
    const completedPlanIds = new Set(plannedCompletions.map((entry) => entry.plan_id))
    const sessionByPlanId = new Map(
      history.sessions
        .filter((session) => session.planned_workout_id)
        .map((session) => [session.planned_workout_id, session]),
    )
    const entries = [
      ...plannedCompletions.map((entry) => ({
        ...entry,
        id: `planned-${entry.plan_id}`,
        source: 'planned',
        session: sessionByPlanId.get(entry.plan_id) || null,
      })),
      ...history.sessions
        .filter((session) => !completedPlanIds.has(session.planned_workout_id))
        .map((session) => ({
          ...session,
          id: `session-${session.id}`,
          completion_date: session.workout_date,
          source: session.planned_workout_id ? 'planned_record' : 'manual',
          title: session.planned_title || 'Manually logged workout',
          description: session.planned_description || '',
        })),
    ].sort((first, second) => second.completion_date.localeCompare(first.completion_date))

    return entries.reduce((groups, entry) => {
      const entryWeek = entry.week_start || mondayOf(entry.completion_date)
      if (!groups[entryWeek]) groups[entryWeek] = []
      groups[entryWeek].push(entry)
      return groups
    }, {})
  }, [history])

  const loadWeek = async () => {
    setLoading(true)
    setWeekError('')
    try {
      const data = await fetchWeek()
      setWeekData({ week: data.week, plans: data.plans })
      setProgress(data.stats)
    } catch (requestError) {
      setProgress(null)
      setWeekError(fitnessErrorMessage(requestError, 'Unable to load your workout data.'))
    } finally {
      setLoading(false)
    }
  }

  const loadHistory = async () => {
    setError('')
    setHistoryError('')
    setHistoryLoading(true)
    try {
      setHistory(await fetchHistory())
    } catch (requestError) {
      setHistoryError(fitnessErrorMessage(requestError, 'Unable to load workout history.'))
    } finally {
      setHistoryLoading(false)
    }
  }

  const submitRequest = async (action, data) => {
    const response = await api.post(`/member_fitness.php?action=${action}`, data)
    if (response.data?.success !== true) {
      throw new Error(response.data?.message || 'The fitness service returned an unexpected response. Please retry.')
    }
    return response.data
  }

  const refreshAfterSave = async (message) => {
    setRetryAction(null)
    setSuccessMessage(message)
    await Promise.all([loadWeek(), loadHistory()])
  }

  const reconcileAfterFailure = async () => {
    const [week, nextHistory] = await Promise.all([
      fetchWeek().catch(() => null),
      fetchHistory().catch(() => null),
    ])
    if (week) {
      setWeekData({ week: week.week, plans: week.plans })
      setProgress(week.stats)
      setWeekError('')
    } else {
      setProgress(null)
      setWeekError('Unable to verify your latest workout plan. Retry loading the plan before trying again.')
    }
    if (nextHistory) {
      setHistory(nextHistory)
      setHistoryError('')
    } else {
      setHistoryError('Unable to verify your latest workout history. Retry loading history before trying again.')
    }
    return { week, history: nextHistory }
  }

  const openNewPlan = (workoutDate, isRestDay = false) => {
    setError('')
    setPlanEditorError('')
    setPlanFieldErrors({})
    setRetryAction(null)
    setSuccessMessage('')
    const draft = {
      plan_id: '',
      workout_date: workoutDate,
      title: isRestDay ? 'Rest day' : '',
      description: '',
      is_rest_day: isRestDay,
    }
    planEditorInitial.current = draft
    setPlanEditor(draft)
  }

  const openEditPlan = (plan) => {
    setError('')
    setPlanEditorError('')
    setPlanFieldErrors({})
    setRetryAction(null)
    setSuccessMessage('')
    const draft = {
      plan_id: plan.id,
      workout_date: plan.workout_date,
      title: plan.title,
      description: plan.description || '',
      is_rest_day: plan.is_rest_day,
    }
    planEditorInitial.current = draft
    setPlanEditor(draft)
  }

  const planEditorIsDirty = () => {
    const initial = planEditorInitial.current
    return Boolean(planEditor && initial && (
      planEditor.title !== initial.title
      || planEditor.description !== initial.description
      || planEditor.workout_date !== initial.workout_date
      || planEditor.is_rest_day !== initial.is_rest_day
    ))
  }

  const requestClosePlanEditor = () => {
    if (saving) return
    if (planEditorIsDirty()) {
      setConfirmation({ type: 'discard-plan' })
      return
    }
    planEditorInitial.current = null
    setPlanEditor(null)
    setPlanEditorError('')
    setPlanFieldErrors({})
  }

  const savePlan = async (event) => {
    event.preventDefault()
    if (planSaveInProgress.current) return
    if (planEditor.is_rest_day && !approvedRestDaySave.current) {
      setConfirmation({ type: 'save-rest-day' })
      return
    }
    approvedRestDaySave.current = false
    const title = planEditor.is_rest_day ? 'Rest day' : planEditor.title.trim()
    if (!planEditor.is_rest_day && !title) {
      setPlanFieldErrors({ title: 'Enter a workout name before saving.' })
      setPlanEditorError('')
      return
    }
    if (title.length > 100) {
      setPlanFieldErrors({ title: 'Workout names must be 100 characters or fewer.' })
      setPlanEditorError('')
      return
    }
    if (planEditor.description.length > 1000) {
      setPlanFieldErrors({ description: 'Workout notes must be 1000 characters or fewer.' })
      setPlanEditorError('')
      return
    }
    const duplicate = weekData.plans.some((plan) => (
      plan.id !== planEditor.plan_id
      && plan.workout_date === planEditor.workout_date
      && plan.is_rest_day === planEditor.is_rest_day
      && plan.title.trim().toLocaleLowerCase() === title.toLocaleLowerCase()
    ))
    if (duplicate) {
      setPlanFieldErrors({ title: 'An activity with this name already exists for that day.' })
      setPlanEditorError('')
      return
    }
    planSaveInProgress.current = true
    setSaving(true)
    setPlanEditorError('')
    setPlanFieldErrors({})
    setError('')
    setRetryAction(null)
    setSuccessMessage('')
    const submittedPlan = {
      ...planEditor,
      title,
      description: planEditor.description.trim(),
    }
    try {
      const response = await submitRequest('save-plan', {
        plan_id: submittedPlan.plan_id,
        workout_date: submittedPlan.workout_date,
        week_start: weekStart,
        title,
        description: submittedPlan.description,
        is_rest_day: submittedPlan.is_rest_day,
      })
      if (!Number.isInteger(Number(response.plan_id))
        || Number(response.plan_id) < 1
        || (submittedPlan.plan_id && Number(response.plan_id) !== Number(submittedPlan.plan_id))) {
        throw new Error('The fitness service returned an unexpected response. Your changes were not confirmed; please retry.')
      }
      planEditorInitial.current = null
      setPlanEditor(null)
      await refreshAfterSave(submittedPlan.is_rest_day ? 'Rest day saved.' : 'Workout saved.')
    } catch (requestError) {
      const { week } = await reconcileAfterFailure()
      const updatedPlan = submittedPlan.plan_id
        ? week?.plans.find((plan) => Number(plan.id) === Number(submittedPlan.plan_id))
        : null
      if (updatedPlan
        && updatedPlan.workout_date === submittedPlan.workout_date
        && updatedPlan.title === submittedPlan.title
        && (updatedPlan.description || '') === submittedPlan.description
        && Boolean(updatedPlan.is_rest_day) === Boolean(submittedPlan.is_rest_day)) {
        planEditorInitial.current = null
        setPlanEditor(null)
        await refreshAfterSave('Workout changes were confirmed.')
      } else {
        const message = fitnessErrorMessage(requestError, 'Unable to save the workout plan.')
        const backendMessage = requestError?.response?.data?.message || ''
        if (/title|workout name|activity name/i.test(backendMessage)) {
          setPlanFieldErrors({ title: message })
          setPlanEditorError('')
        } else if (/notes|description/i.test(backendMessage)) {
          setPlanFieldErrors({ description: message })
          setPlanEditorError('')
        } else {
          setPlanEditorError(message)
        }
        if (isRetryableRequestError(requestError) && week && updatedPlan) {
          const originalPlan = planEditorInitial.current
          const serverStillMatchesOriginal = originalPlan
            && updatedPlan.workout_date === originalPlan.workout_date
            && updatedPlan.title === originalPlan.title
            && (updatedPlan.description || '') === (originalPlan.description || '')
          if (serverStillMatchesOriginal) {
            setRetryAction({
              label: 'Retry saving changes',
              run: () => savePlan({ preventDefault() {} }),
            })
          } else {
            setPlanEditorError('This workout changed on the server while you were editing. Refresh the plan before retrying.')
          }
        }
      }
    } finally {
      planSaveInProgress.current = false
      setSaving(false)
    }
  }

  const performRestDayChange = async (workoutDate, dayPlans) => {
    if (restDayInProgress.current || saving) return
    restDayInProgress.current = true
    const restDay = dayPlans.find((plan) => plan.is_rest_day)
    const shouldBeRestDay = !restDay
    setSaving(true)
    setError('')
    setSuccessMessage('')
    setRetryAction(null)
    try {
      if (restDay) {
        await submitRequest('delete-rest-day', { plan_id: restDay.id })
        await refreshAfterSave('Rest day undone. Your workout activities were preserved.')
        return
      }

      const response = await submitRequest('save-plan', {
        workout_date: workoutDate,
        week_start: weekStart,
        title: 'Rest day',
        description: '',
        is_rest_day: true,
        exercises: [],
      })
      if (!Number.isInteger(Number(response.plan_id)) || Number(response.plan_id) < 1) {
        throw new Error('The fitness service returned an unexpected response. Checking the current plan before retrying.')
      }
      await refreshAfterSave('Rest day saved.')
    } catch (requestError) {
      const { week } = await reconcileAfterFailure()
      const currentDayPlans = week?.plans.filter((plan) => plan.workout_date === workoutDate) || []
      const currentRestDay = currentDayPlans.some((plan) => plan.is_rest_day)
      if (currentRestDay === shouldBeRestDay) {
        await refreshAfterSave(shouldBeRestDay ? 'Rest day saved.' : 'Rest day undone.')
      } else {
        setError(fitnessErrorMessage(requestError, 'Unable to update the rest day.'))
        const retryIsSafe = shouldBeRestDay
          ? currentDayPlans.length === 0
          : currentRestDay
        if (isRetryableRequestError(requestError) && week && retryIsSafe) {
          setRetryAction({
            label: shouldBeRestDay ? 'Retry marking as rest day' : 'Retry undoing rest day',
            run: () => {
              const latestPlans = weekData.plans.filter((plan) => plan.workout_date === workoutDate)
              if (!latestPlans.some((plan) => plan.is_rest_day)) {
                setConfirmation({ type: 'mark-rest-day', workoutDate })
              } else {
                performRestDayChange(workoutDate, latestPlans)
              }
            },
          })
        }
      }
    } finally {
      restDayInProgress.current = false
      setSaving(false)
    }
  }

  const toggleRestDay = (workoutDate, dayPlans) => {
    if (dayPlans.some((plan) => plan.is_rest_day)) {
      performRestDayChange(workoutDate, dayPlans)
      return
    }
    setError('')
    setRetryAction(null)
    setSuccessMessage('')
    setConfirmation({ type: 'mark-rest-day', workoutDate })
  }

  const deletePlan = (plan) => {
    if (deleteInProgress.current || saving) return
    setError('')
    setRetryAction(null)
    setSuccessMessage('')
    setConfirmation({ type: 'delete-plan', plan })
  }

  const performDeletePlan = async (plan) => {
    if (deleteInProgress.current || saving) return
    deleteInProgress.current = true
    setSaving(true)
    setRetryAction(null)
    setError('')
    setSuccessMessage('')
    try {
      await submitRequest('delete-plan', { plan_id: plan.id })
      await refreshAfterSave('Workout plan deleted. Recorded history was preserved.')
    } catch (requestError) {
      const { week } = await reconcileAfterFailure()
      const stillExists = week?.plans.some((item) => Number(item.id) === Number(plan.id))
      if (week && !stillExists) {
        await refreshAfterSave('Workout plan deleted. Recorded history was preserved.')
      } else {
        setError(fitnessErrorMessage(requestError, 'Unable to delete the workout plan.'))
        const stillPending = week?.plans.some((item) => (
          Number(item.id) === Number(plan.id) && item.status !== 'completed'
        ))
        if (isRetryableRequestError(requestError) && stillPending) {
          setRetryAction({
            label: 'Retry delete',
            run: () => setConfirmation({ type: 'delete-plan', plan }),
          })
        }
      }
    } finally {
      deleteInProgress.current = false
      setSaving(false)
    }
  }

  const confirmContextAction = async () => {
    if (!confirmation || saving) return
    const action = confirmation
    setConfirmation(null)
    if (action.type === 'discard-plan') {
      planEditorInitial.current = null
      setPlanEditor(null)
      setPlanEditorError('')
      setPlanFieldErrors({})
      return
    }
    if (action.type === 'delete-plan') {
      await performDeletePlan(action.plan)
      return
    }
    if (action.type === 'mark-rest-day') {
      const dayPlans = weekData.plans.filter((plan) => plan.workout_date === action.workoutDate)
      await performRestDayChange(action.workoutDate, dayPlans)
      return
    }
    if (action.type === 'save-rest-day') {
      approvedRestDaySave.current = true
      await savePlan({ preventDefault() {} })
    }
  }

  const updatePlanStatus = async (plan, status) => {
    setStatusSaving(true)
    setSuccessMessage('')
    const previousPlans = weekData.plans
    const updatedPlans = previousPlans.map((item) => item.id === plan.id ? { ...item, status } : item)
    const plannedCount = updatedPlans.filter((item) => !item.is_rest_day).length
    const completedCount = updatedPlans.filter((item) => !item.is_rest_day && item.status === 'completed').length
    setWeekData((current) => ({ ...current, plans: updatedPlans }))
    setProgress((current) => current && ({
      ...current,
      planned_workouts: plannedCount,
      completed_workouts: completedCount,
      remaining_workouts: plannedCount - completedCount,
      completion_percentage: plannedCount ? Math.round((completedCount / plannedCount) * 100) : null,
    }))
    setError('')
    try {
      await submitRequest('set-plan-status', { plan_id: plan.id, status })
      setSuccessMessage(status === 'completed' ? 'Workout marked complete.' : 'Workout marked incomplete.')
      await Promise.all([loadWeek(), loadHistory()])
    } catch (requestError) {
      setWeekData((current) => ({ ...current, plans: previousPlans }))
      const plannedCountBefore = previousPlans.filter((item) => !item.is_rest_day).length
      const completedCountBefore = previousPlans.filter((item) => !item.is_rest_day && item.status === 'completed').length
      setProgress((current) => current && ({
        ...current,
        planned_workouts: plannedCountBefore,
        completed_workouts: completedCountBefore,
        remaining_workouts: plannedCountBefore - completedCountBefore,
        completion_percentage: plannedCountBefore
          ? Math.round((completedCountBefore / plannedCountBefore) * 100)
          : null,
      }))
      await reconcileAfterFailure()
      setError(fitnessErrorMessage(requestError, 'Unable to update workout status.'))
    } finally {
      setStatusSaving(false)
    }
  }

  const openWorkoutEditor = (plan = null, session = null) => {
    const sourceExercises = session?.exercises || plan?.exercises || []
    setWorkoutEditor({
      session_id: session?.id || '',
      planned_workout_id: session?.planned_workout_id || plan?.id || '',
      request_key: session || plan ? '' : createWorkoutRequestKey(),
      workout_date: session?.workout_date || plan?.workout_date || getManilaToday(),
      status: session?.status || (plan?.status === 'partial'
        ? 'partial'
        : plan?.status === 'skipped' ? 'skipped' : 'completed'),
      duration_minutes: session?.duration_minutes ? String(session.duration_minutes) : '',
      notes: session?.notes || '',
      exercises: sourceExercises.length
        ? sourceExercises.map((exercise) => makeEmptyActualExercise(exercise))
        : [makeEmptyActualExercise()],
    })
    setError('')
    setSuccessMessage('')
  }

  const saveWorkout = async (event) => {
    event.preventDefault()
    if (workoutEditor.status !== 'skipped'
      && !workoutEditor.exercises.some((exercise) => exercise.exercise_name.trim())) {
      setError('Add at least one exercise for a completed or partially completed workout.')
      return
    }
    setSaving(true)
    setError('')
    try {
      const exercises = workoutEditor.exercises
        .filter((exercise) => exercise.exercise_name.trim())
        .map((exercise) => ({
          ...exercise,
          planned_exercise_id: exercise.planned_exercise_id || null,
        }))
      await submitRequest('save-workout', {
        ...workoutEditor,
        exercises,
      })
      setWorkoutEditor(null)
      await refreshAfterSave('Workout results saved.')
    } catch (requestError) {
      await reconcileAfterFailure()
      setError(fitnessErrorMessage(requestError, 'Unable to save workout results.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-6 space-y-5">
      {error && (
        <div role="alert" className="flex items-start justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          <div>
            <p>{error}</p>
            {retryAction && (
              <button
                type="button"
                disabled={saving}
                onClick={() => {
                  const retry = retryAction
                  setRetryAction(null)
                  retry.run()
                }}
                className="mt-2 font-semibold underline disabled:cursor-not-allowed disabled:opacity-60"
              >
                {retryAction.label}
              </button>
            )}
          </div>
          <button type="button" onClick={() => setError('')} aria-label="Dismiss error" className="font-semibold">×</button>
        </div>
      )}
      {successMessage && (
        <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          {successMessage}
        </div>
      )}

      {(sections === 'all' || sections === 'workout') && (
      <section id="member-workout-plan" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3">
            <span className="rounded-xl bg-violet-50 p-3 text-violet-700"><FaDumbbell /></span>
            <div>
              <p className="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Your personal checklist</p>
              <h2 className="text-lg font-semibold text-slate-900">My Workout Plan</h2>
              <p className="mt-1 text-xs font-medium text-violet-700">
                This week{weekData.week?.week_start ? ` · ${formatWeekRange(weekData.week.week_start)}` : ''}
              </p>
            </div>
          </div>
        </div>

        {loading ? (
          <p className="py-10 text-center text-sm text-slate-500">Loading your weekly plan...</p>
        ) : weekError ? (
          <div role="alert" className="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p>{weekError}</p>
            <button type="button" onClick={loadWeek} className={`${buttonClass} mt-3`}>Retry loading plan</button>
          </div>
        ) : (
          <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {weekDays.map((dayName, index) => {
              const date = addCalendarDays(weekStart, index)
              const dayPlans = weekData.plans.filter((plan) => plan.workout_date === date)
              const isRestDay = dayPlans.some((plan) => plan.is_rest_day)
              return (
                <article key={dayName} className="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <h3 className="font-semibold text-slate-800">{dayName}</h3>
                  </div>
                  <div className="flex-1 space-y-2">
                    {dayPlans.map((plan) => (
                      <div key={plan.id} className={`rounded-xl border p-3 ${plan.is_rest_day ? 'border-slate-200 bg-slate-100' : plan.status === 'completed' ? 'border-emerald-200 bg-emerald-50/70' : plan.workout_date > manilaToday ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-white'}`}>
                        <div className="flex items-start gap-2">
                          {!plan.is_rest_day && (
                            <input
                              type="checkbox"
                              aria-label={`Mark ${plan.title} ${plan.status === 'completed' ? 'incomplete' : 'complete'}`}
                              checked={plan.status === 'completed'}
                              disabled={saving || statusSaving || plan.workout_date > manilaToday}
                              title={plan.workout_date > manilaToday
                                ? `Available on ${weekDays[getWeekdayIndex(plan.workout_date)]}`
                                : undefined}
                              onChange={(event) => updatePlanStatus(plan, event.target.checked ? 'completed' : 'planned')}
                              className="mt-0.5 h-5 w-5 shrink-0 accent-emerald-600"
                            />
                          )}
                          <div className="min-w-0 flex-1">
                            <p className={`break-words text-sm font-semibold ${plan.status === 'completed' ? 'text-emerald-900 line-through' : 'text-slate-900'}`}>{plan.title}</p>
                            {plan.description && <p className="mt-1 whitespace-pre-wrap break-words text-xs text-slate-600">{plan.description}</p>}
                            <p className={`mt-1 text-xs font-medium ${plan.status === 'completed' ? 'text-emerald-800' : plan.workout_date > manilaToday ? 'text-amber-800' : 'text-slate-500'}`}>
                              {plan.is_rest_day
                                ? 'Rest day'
                                : plan.status === 'completed'
                                  ? 'Completed'
                                  : plan.workout_date > manilaToday
                                    ? `Available on ${weekDays[getWeekdayIndex(plan.workout_date)]}`
                                    : 'Pending'}
                            </p>
                          </div>
                          <div className="flex shrink-0 gap-1">
                            <button type="button" disabled={saving || plan.status === 'completed'} title={plan.status === 'completed' ? 'Uncheck this activity before editing it.' : undefined} aria-label={`Edit ${plan.title}`} onClick={() => openEditPlan(plan)} className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"><FaEdit /></button>
                            <button type="button" disabled={saving || plan.status === 'completed'} title={plan.status === 'completed' ? 'Uncheck this activity before deleting it.' : undefined} aria-label={`Delete ${plan.title}`} onClick={() => deletePlan(plan)} className="flex h-9 w-9 items-center justify-center rounded-lg text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40"><FaTrash /></button>
                          </div>
                        </div>
                      </div>
                    ))}
                    {dayPlans.length === 0 && <p className="py-2 text-xs text-slate-400">No activities yet</p>}
                  </div>
                  <div className="mt-3 grid gap-2">
                    {!isRestDay && (
                      <button type="button" disabled={saving} onClick={() => openNewPlan(date)} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-violet-200 bg-white px-3 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-60">
                        <FaPlus /> Add Workout
                      </button>
                    )}
                    <button
                      type="button"
                      disabled={saving}
                      onClick={() => toggleRestDay(date, dayPlans)}
                      className="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {isRestDay ? 'Undo Rest Day' : 'Mark as a Rest Day'}
                    </button>
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </section>
      )}

      {(sections === 'all' || sections === 'progress') && (
      <section id="member-progress" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3">
            <span className="rounded-xl bg-emerald-50 p-3 text-emerald-700"><FaChartLine /></span>
            <div>
              <p className="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Your activity</p>
              <h2 className="text-lg font-semibold text-slate-900">My Progress</h2>
            </div>
          </div>
          <button type="button" className={buttonClass} onClick={() => openWorkoutEditor()}>
            <FaPlus /> Log a workout
          </button>
        </div>
        {loading ? (
          <p className="mt-4 text-sm text-slate-500">Loading progress...</p>
        ) : weekError ? (
          <div role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p>{weekError}</p>
            <button type="button" onClick={loadWeek} className={`${buttonClass} mt-3`}>Retry loading progress</button>
          </div>
        ) : progress ? (
          <>
            <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
              {[
                ['Planned activities', progress.planned_workouts],
                ['Completed', progress.completed_workouts],
                ['Remaining', progress.remaining_workouts],
                ['Weekly completion', progress.completion_percentage == null ? '—' : `${progress.completion_percentage}%`],
              ].map(([label, value]) => (
                <div key={label} className="rounded-xl bg-slate-50 p-3">
                  <p className="text-xs text-slate-500">{label}</p>
                  <p className="mt-1 text-xl font-semibold text-slate-900">{value}</p>
                </div>
              ))}
            </div>
            {progress.completion_percentage != null && (
              <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" aria-label={`Weekly completion ${progress.completion_percentage}%`}>
                <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${progress.completion_percentage}%` }} />
              </div>
            )}
            {progress.planned_workouts === 0 && (
              <div className="mt-4 rounded-xl border border-dashed border-violet-200 bg-violet-50/50 p-4">
                <p className="text-sm font-medium text-slate-800">Your week is ready for a first workout.</p>
                <p className="mt-1 text-xs text-slate-600">Add an activity to any day and check it off when you finish.</p>
                <button type="button" onClick={() => openNewPlan(addCalendarDays(weekStart, 0))} className={`${buttonClass} mt-3`}>
                  <FaPlus /> Add Your First Workout
                </button>
              </div>
            )}
          </>
        ) : (
          <div role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p>Progress is not available.</p>
            <button type="button" onClick={loadWeek} className={`${buttonClass} mt-3`}>Retry loading progress</button>
          </div>
        )}

        <div className="mt-6 border-t border-slate-100 pt-4">
          <div className="flex items-center gap-2">
            <FaHistory className="text-violet-700" />
            <h3 className="font-semibold text-slate-900">Workout History</h3>
          </div>
          <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <label className="text-xs font-medium text-slate-600">Completed from<input aria-label="History from date" type="date" value={historyFrom} onChange={(event) => { setHistoryLoading(true); setHistoryFrom(event.target.value) }} className={fieldClass} /></label>
            <label className="text-xs font-medium text-slate-600">To<input aria-label="History to date" type="date" value={historyTo} onChange={(event) => { setHistoryLoading(true); setHistoryTo(event.target.value) }} className={fieldClass} /></label>
            <button type="button" disabled={historyLoading} className={buttonClass} onClick={loadHistory}>{historyLoading ? 'Loading...' : 'Filter history'}</button>
          </div>
          <div className="mt-3 space-y-2">
            {historyLoading ? (
              <p className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500">Loading workout history...</p>
            ) : historyError ? (
              <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <p>{historyError}</p>
                <button type="button" onClick={loadHistory} className={`${buttonClass} mt-3`}>Retry loading history</button>
              </div>
            ) : Object.keys(historyGroups).length === 0 ? (
              <p className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500">No completed or manually logged workouts in this date range.</p>
            ) : Object.entries(historyGroups).map(([week, entries]) => (
              <section key={week} className="rounded-xl border border-slate-200 p-3">
                <h4 className="mb-3 text-sm font-semibold text-slate-800">Week of {formatWeekRange(week)}</h4>
                <div className="space-y-2">
                  {entries.map((entry) => {
                    const session = entry.session || (entry.source === 'manual' || entry.source === 'planned_record' ? entry : null)
                    return (
                      <details key={entry.id} className="rounded-xl border border-slate-100 bg-slate-50/70 p-3">
                        <summary className="cursor-pointer list-none">
                          <span className="flex flex-wrap items-center justify-between gap-2">
                            <span className="min-w-0">
                              <span className="block font-medium text-slate-900">{entry.title}</span>
                              <span className="mt-1 block text-xs text-slate-600">
                                {entry.source === 'planned' ? 'Planned activity completion' : entry.source === 'planned_record' ? 'Planned workout results' : 'Manually logged workout'}
                              </span>
                            </span>
                            <span className="text-xs text-slate-600">
                              Completed {displayDate(entry.completion_date, { year: 'numeric', month: 'short', day: 'numeric' })}
                            </span>
                          </span>
                        </summary>
                        {entry.source === 'planned' && (
                          <p className="mt-2 text-xs text-slate-600">
                            Scheduled for {weekDays[getWeekdayIndex(entry.workout_date)]} · Completed
                          </p>
                        )}
                        {entry.description && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{entry.description}</p>}
                        {session?.notes && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{session.notes}</p>}
                        {session && (
                          <>
                            <p className="mt-2 text-xs capitalize text-slate-500">
                              {session.status}{session.duration_minutes ? ` · ${session.duration_minutes} min` : ''} · {session.exercises.length} exercises
                            </p>
                            {session.exercises.length > 0 && (
                              <ul className="mt-2 space-y-1 text-sm text-slate-700">
                                {session.exercises.map((exercise) => (
                                  <li key={exercise.id}>
                                    {exercise.exercise_name}
                                    {exercise.sets_completed ? ` · ${exercise.sets_completed} sets` : ''}
                                    {exercise.reps_completed ? ` × ${exercise.reps_completed} reps` : ''}
                                    {exercise.actual_weight_kg ? ` · ${exercise.actual_weight_kg} kg` : ''}
                                    {!exercise.is_completed ? ' · not completed' : ''}
                                  </li>
                                ))}
                              </ul>
                            )}
                            <button type="button" onClick={() => openWorkoutEditor(null, session)} className="mt-3 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-white">Edit recorded workout</button>
                          </>
                        )}
                      </details>
                    )
                  })}
                </div>
              </section>
            ))}
          </div>
        </div>
      </section>
      )}

      {sections !== 'workout' && (
      <section id="member-bmi" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex items-center gap-3">
          <span className="rounded-xl bg-sky-50 p-3 text-sky-700"><FaCalculator /></span>
          <div>
            <p className="text-xs font-medium uppercase tracking-[0.2em] text-slate-500">Personal wellness</p>
            <h2 className="text-lg font-semibold text-slate-900">BMI Calculator</h2>
          </div>
        </div>
        <div className="mt-4 grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm font-medium text-slate-700">
              Height (cm)
              <input aria-label="Height in centimeters" type="number" min="80" max="250" step="0.1" inputMode="decimal" value={heightCm} onChange={(event) => setHeightCm(event.target.value)} className={fieldClass} placeholder="e.g. 170" />
            </label>
            <label className="text-sm font-medium text-slate-700">
              Weight (kg)
              <input aria-label="Weight in kilograms" type="number" min="20" max="500" step="0.1" inputMode="decimal" value={weightKg} onChange={(event) => setWeightKg(event.target.value)} className={fieldClass} placeholder="e.g. 65" />
            </label>
          </div>
          {bmiResult && (
            <div className="min-w-44 rounded-xl bg-sky-50 px-5 py-3 text-center">
              <p className="text-xs font-medium uppercase tracking-wide text-sky-700">Your BMI</p>
              <p className="text-3xl font-bold text-sky-950">{bmiResult.value}</p>
              <p className="text-sm font-medium text-sky-800">
                {age !== null && age < 20 ? 'Adult category not applicable' : bmiResult.category}
              </p>
            </div>
          )}
        </div>
        {(heightCm || weightKg) && !bmiResult && (
          <p role="alert" className="mt-3 text-sm text-rose-700">Enter a height from 80–250 cm and a weight from 20–500 kg.</p>
        )}
        <p className="mt-3 text-xs leading-relaxed text-slate-500">
          BMI is an adult screening measure, not a diagnosis. It does not directly measure body fat or distinguish muscle from fat.
          {age !== null && age < 20
            ? ' Adult BMI screening categories are not appropriate for children or teenagers.'
            : ' The result is calculated in your browser and is not saved.'}
        </p>
      </section>
      )}

      {planEditor && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="plan-editor-title"
          className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/60 p-3 sm:p-5"
        >
          <form onSubmit={savePlan} className="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
            <div className="flex items-center justify-between gap-3">
              <div>
                <p className="text-xs uppercase tracking-wider text-slate-500">Personal workout</p>
                <h3 id="plan-editor-title" className="text-lg font-semibold">{planEditor.plan_id ? 'Edit activity' : 'Add activity'}</h3>
                <p className="mt-1 text-sm text-slate-600">{weekDays[getWeekdayIndex(planEditor.workout_date)]}</p>
              </div>
              <button type="button" disabled={saving} onClick={requestClosePlanEditor} className="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60">Close</button>
            </div>
            {planEditorError && (
              <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                {planEditorError}
                {retryAction && (
                  <button
                    type="button"
                    disabled={saving}
                    onClick={() => {
                      const retry = retryAction
                      setRetryAction(null)
                      retry.run()
                    }}
                    className="ml-2 font-semibold underline disabled:opacity-60"
                  >
                    {retryAction.label}
                  </button>
                )}
              </p>
            )}
            <label className="mt-4 flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={planEditor.is_rest_day}
                onChange={(event) => {
                  setPlanEditorError('')
                  setPlanFieldErrors({})
                  setRetryAction(null)
                  setPlanEditor({
                    ...planEditor,
                    is_rest_day: event.target.checked,
                    title: event.target.checked ? 'Rest day' : '',
                    description: event.target.checked ? '' : planEditor.description,
                  })
                }}
              />
              Mark this day as a rest day
            </label>
            {!planEditor.is_rest_day && (
              <>
                <label className="mt-4 block text-sm font-medium text-slate-700">
                  Workout name
                    <input required autoFocus maxLength={100} value={planEditor.title} aria-invalid={Boolean(planFieldErrors.title)} aria-describedby={planFieldErrors.title ? 'plan-title-error' : undefined} onChange={(event) => { setPlanEditorError(''); setPlanFieldErrors((current) => ({ ...current, title: '' })); setRetryAction(null); setPlanEditor({ ...planEditor, title: event.target.value }) }} className={`${fieldClass} ${planFieldErrors.title ? 'border-rose-400 focus:border-rose-500' : ''}`} placeholder="e.g. Upper Body Workout" />
                    {planFieldErrors.title && <span id="plan-title-error" className="mt-1 block text-xs text-rose-700">{planFieldErrors.title}</span>}
                  </label>
                  <label className="mt-3 block text-sm font-medium text-slate-700">
                    Notes (optional)
                    <textarea maxLength={1000} rows="3" value={planEditor.description} aria-invalid={Boolean(planFieldErrors.description)} aria-describedby={planFieldErrors.description ? 'plan-description-error' : undefined} onChange={(event) => { setPlanEditorError(''); setPlanFieldErrors((current) => ({ ...current, description: '' })); setRetryAction(null); setPlanEditor({ ...planEditor, description: event.target.value }) }} className={`${fieldClass} ${planFieldErrors.description ? 'border-rose-400 focus:border-rose-500' : ''}`} placeholder="Add a short note or reminder" />
                    {planFieldErrors.description && <span id="plan-description-error" className="mt-1 block text-xs text-rose-700">{planFieldErrors.description}</span>}
                  </label>
              </>
            )}
            {planEditor.is_rest_day && <p className="mt-3 text-sm text-slate-600">Rest days are excluded from your planned and remaining activity totals.</p>}
            <div className="mt-5 flex justify-end gap-2"><button type="button" disabled={saving} onClick={requestClosePlanEditor} className="rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60">Cancel</button><button type="submit" disabled={saving} className={buttonClass}>{saving ? 'Saving...' : 'Save activity'}</button></div>
          </form>
        </div>
      )}

      <ConfirmationModal
        open={Boolean(confirmation)}
        title={confirmation?.type === 'delete-plan'
          ? 'Delete this workout?'
          : confirmation?.type === 'mark-rest-day'
            ? `Mark ${weekDays[getWeekdayIndex(confirmation.workoutDate)]} as a rest day?`
            : confirmation?.type === 'save-rest-day'
              ? 'Save this day as a rest day?'
              : 'Discard unsaved changes?'}
        message={confirmation?.type === 'delete-plan'
          ? `Delete "${confirmation.plan?.title}" from this week's plan? Completed and historical workout records are preserved.`
          : confirmation?.type === 'mark-rest-day'
            ? ''
            : confirmation?.type === 'save-rest-day'
              ? 'This day will be excluded from planned and remaining activity totals. Existing workout activities will be preserved and prevent this change.'
              : 'Your unsaved workout name or notes will be lost.'}
        confirmText={confirmation?.type === 'delete-plan'
          ? 'Delete'
          : confirmation?.type === 'mark-rest-day'
            ? 'Mark as Rest Day'
            : confirmation?.type === 'save-rest-day'
              ? 'Save Rest Day'
              : 'Discard Changes'}
        cancelText="Cancel"
        variant={confirmation?.type === 'delete-plan' ? 'danger' : 'warning'}
        loading={saving}
        loadingText={confirmation?.type === 'delete-plan' ? 'Deleting...' : 'Saving...'}
        onConfirm={confirmContextAction}
        onCancel={() => {
          if (!saving) setConfirmation(null)
        }}
      />

      {workoutEditor && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/60 p-3 sm:p-5">
          <form onSubmit={saveWorkout} className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
            <div className="flex items-center justify-between gap-3">
              <div><p className="text-xs uppercase tracking-wider text-slate-500">Actual workout results</p><h3 className="text-lg font-semibold">{workoutEditor.session_id ? 'Edit recorded workout' : 'Record workout'}</h3></div>
              <button type="button" onClick={() => setWorkoutEditor(null)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">Close</button>
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
              <label className="text-sm font-medium">Workout date<input required type="date" max={getManilaToday()} value={workoutEditor.workout_date} onChange={(event) => setWorkoutEditor({ ...workoutEditor, workout_date: event.target.value })} className={fieldClass} /></label>
              <label className="text-sm font-medium">Status<select value={workoutEditor.status} onChange={(event) => setWorkoutEditor({ ...workoutEditor, status: event.target.value, exercises: event.target.value !== 'skipped' && workoutEditor.exercises.length === 0 ? [makeEmptyActualExercise()] : workoutEditor.exercises })} className={fieldClass}><option value="completed">Completed</option><option value="partial">Partially completed</option><option value="skipped">Skipped</option></select></label>
              <label className="text-sm font-medium">Duration (minutes)<input type="number" min="1" max="1440" value={workoutEditor.duration_minutes} onChange={(event) => setWorkoutEditor({ ...workoutEditor, duration_minutes: event.target.value })} className={fieldClass} /></label>
            </div>
            <label className="mt-3 block text-sm font-medium">Notes<textarea maxLength={2000} rows="2" value={workoutEditor.notes} onChange={(event) => setWorkoutEditor({ ...workoutEditor, notes: event.target.value })} className={fieldClass} /></label>
            <div className="mt-4 space-y-3">
              <div className="flex items-center justify-between"><h4 className="font-semibold">Exercises performed</h4><button type="button" onClick={() => setWorkoutEditor({ ...workoutEditor, exercises: [...workoutEditor.exercises, makeEmptyActualExercise()] })} className="rounded-lg border border-violet-200 px-3 py-2 text-xs font-semibold text-violet-700"><FaPlus className="mr-1 inline" /> Add exercise</button></div>
              {workoutEditor.exercises.map((exercise, index) => (
                <div key={`${index}-${exercise.planned_exercise_id || 'new'}`} className="grid gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
                  <label className="text-xs font-medium">Exercise<input required={workoutEditor.status !== 'skipped'} value={exercise.exercise_name} onChange={(event) => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.map((item, i) => i === index ? { ...item, exercise_name: event.target.value } : item) })} className={fieldClass} /></label>
                  <label className="text-xs font-medium">Sets completed<input type="number" min="1" max="100" value={exercise.sets_completed} onChange={(event) => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.map((item, i) => i === index ? { ...item, sets_completed: event.target.value } : item) })} className={fieldClass} /></label>
                  <label className="text-xs font-medium">Reps completed<input maxLength={40} value={exercise.reps_completed} onChange={(event) => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.map((item, i) => i === index ? { ...item, reps_completed: event.target.value } : item) })} className={fieldClass} /></label>
                  <label className="text-xs font-medium">Weight used (kg)<input type="number" min="0.01" max="9999.99" step="0.01" value={exercise.actual_weight_kg} onChange={(event) => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.map((item, i) => i === index ? { ...item, actual_weight_kg: event.target.value } : item) })} className={fieldClass} /></label>
                  <label className="mt-6 flex items-center gap-2 text-xs"><input type="checkbox" checked={exercise.is_completed} onChange={(event) => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.map((item, i) => i === index ? { ...item, is_completed: event.target.checked } : item) })} />Completed</label>
                  <button type="button" disabled={workoutEditor.exercises.length <= 1 && workoutEditor.status !== 'skipped'} onClick={() => setWorkoutEditor({ ...workoutEditor, exercises: workoutEditor.exercises.filter((_, i) => i !== index) })} className="rounded-lg px-3 py-2 text-xs text-rose-700 disabled:opacity-40">Remove exercise</button>
                </div>
              ))}
            </div>
            <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setWorkoutEditor(null)} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancel</button><button type="submit" disabled={saving} className={buttonClass}><FaCheckCircle />{saving ? 'Saving...' : 'Save results'}</button></div>
          </form>
        </div>
      )}
    </div>
  )
}
