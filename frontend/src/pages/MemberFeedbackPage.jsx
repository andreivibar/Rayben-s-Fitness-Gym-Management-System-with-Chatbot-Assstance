import { useCallback, useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { FaRegCommentDots, FaStar, FaHistory } from 'react-icons/fa'
import api from '../services/api'
import MemberFeedback from '../components/MemberFeedback.jsx'
import { getStoredUser } from '../utils/authState'

const sections = [
  { key: 'overall', title: 'Overall Gym Experience', description: 'How was your overall experience at the gym?' },
  { key: 'facilities', title: 'Facilities and Equipment', description: 'Tell us about the equipment, cleanliness, and environment.' },
  { key: 'services', title: 'Gym Services', description: 'How can we improve our services and management?' },
  { key: 'system', title: 'System Experience', description: 'Share suggestions about the Smart Gym Management System.' },
]

const ratingLabels = {
  1: 'Very poor',
  2: 'Poor',
  3: 'Average',
  4: 'Good',
  5: 'Excellent',
}

function emptyForm() {
  return Object.fromEntries(sections.flatMap(({ key }) => [
    [`${key}_rating`, 0],
    [`${key}_comments`, ''],
  ]))
}

function formatDate(value, options = { dateStyle: 'long' }) {
  if (!value) return '—'
  const raw = String(value)
  const date = new Date(raw.length === 10 ? `${raw}T00:00:00+08:00` : `${raw.replace(' ', 'T')}+08:00`)
  return Number.isNaN(date.getTime())
    ? raw
    : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', ...options }).format(date)
}

function hasFeedback(form) {
  return sections.some(({ key }) => (
    Number(form[`${key}_rating`]) > 0 || String(form[`${key}_comments`]).trim() !== ''
  ))
}

function millisecondsUntilNextManilaMidnight() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  const nextDate = new Date(`${values.year}-${values.month}-${values.day}T00:00:00Z`)
  nextDate.setUTCDate(nextDate.getUTCDate() + 1)
  const nextMidnight = new Date(`${nextDate.toISOString().slice(0, 10)}T00:00:00+08:00`)
  return Math.max(1000, nextMidnight.getTime() - Date.now() + 1000)
}

/* ── Star rating row (shared between form cards) ── */
function StarRating({ value, hovered, onSelect, onHover, onLeave }) {
  return (
    <fieldset className="mt-3">
      <legend className="text-xs font-medium text-slate-500">Your rating</legend>
      <div className="mt-1.5 flex items-center gap-0.5" onMouseLeave={onLeave}>
        {[1, 2, 3, 4, 5].map((star) => {
          const filled = star <= (hovered || value)
          return (
            <button
              key={star}
              type="button"
              onClick={() => onSelect(value === star ? 0 : star)}
              onMouseEnter={() => onHover(star)}
              onFocus={() => onHover(star)}
              onBlur={onLeave}
              aria-label={`${star} star${star === 1 ? '' : 's'}: ${ratingLabels[star]}`}
              aria-pressed={value === star}
              className="group flex h-9 w-9 items-center justify-center rounded-md transition-colors hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500"
            >
              <FaStar
                aria-hidden="true"
                className={`text-lg transition-colors ${filled ? 'text-amber-400' : 'text-slate-200 group-hover:text-amber-200'}`}
              />
            </button>
          )
        })}
        <span className="ml-2 text-xs font-medium text-slate-500">
          {value ? ratingLabels[value] : 'No rating'}
        </span>
      </div>
    </fieldset>
  )
}

export default function MemberFeedbackPage() {
  const location = useLocation()
  const [form, setForm] = useState(emptyForm)
  const [history, setHistory] = useState([])
  const [legacyHistory, setLegacyHistory] = useState([])
  const [todayFeedback, setTodayFeedback] = useState(null)
  const [alreadySubmitted, setAlreadySubmitted] = useState(false)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [hoveredRatings, setHoveredRatings] = useState({})
  const [refreshToken, setRefreshToken] = useState(0)
  const [bookings, setBookings] = useState([])
  const [bookingsError, setBookingsError] = useState('')

  const refreshBookings = useCallback(async () => {
    const user = getStoredUser()
    try {
      const response = await api.get('/bookings.php', {
        params: { role: 'member', email: user?.email },
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to load completed bookings for trainer ratings.')
      }
      setBookings(response.data.bookings || [])
      setBookingsError('')
    } catch (requestError) {
      setBookingsError(requestError?.response?.data?.message || requestError?.message || 'Unable to load completed bookings for trainer ratings.')
    }
  }, [])

  useEffect(() => {
    let active = true
    const fetchFeedback = async () => {
      try {
        const response = await api.get('/gym_system_feedback.php')
        if (!response.data?.success) {
          throw new Error(response.data?.message || 'Unable to load your feedback.')
        }
        if (!active) return
        setHistory(response.data.feedback || [])
        setLegacyHistory(response.data.legacy_feedback || [])
        setTodayFeedback(response.data.today_feedback || null)
        setAlreadySubmitted(Boolean(response.data.already_submitted))
        setError('')
      } catch (requestError) {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load your feedback.')
      } finally {
        if (active) setLoading(false)
      }
    }
    fetchFeedback()
    Promise.resolve().then(refreshBookings)
    const midnightTimer = window.setTimeout(() => {
      setRefreshToken((value) => value + 1)
    }, millisecondsUntilNextManilaMidnight())
    return () => {
      active = false
      window.clearTimeout(midnightTimer)
    }
  }, [refreshBookings, refreshToken])

  const updateForm = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
  }

  const submitFeedback = async (event) => {
    event.preventDefault()
    if (!hasFeedback(form)) {
      setError('Please add at least one rating or written comment.')
      return
    }

    setSubmitting(true)
    setError('')
    setNotice('')
    try {
      const payload = Object.fromEntries(Object.entries(form).map(([field, value]) => [
        field,
        field.endsWith('_rating') ? (Number(value) || null) : value.trim(),
      ]))
      const response = await api.post('/gym_system_feedback.php', payload)
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to submit your feedback.')
      }
      setForm(emptyForm())
      setAlreadySubmitted(true)
      setTodayFeedback({
        feedback_date: response.data.feedback_date,
        submitted_at: response.data.submitted_at,
      })
      setNotice(response.data.message || 'Thank you for sharing your feedback.')
      setRefreshToken((value) => value + 1)
    } catch (requestError) {
      const message = requestError?.response?.data?.message || requestError?.message || 'Unable to submit your feedback.'
      setError(message)
      if (requestError?.response?.status === 409) {
        setAlreadySubmitted(true)
        setRefreshToken((value) => value + 1)
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-5">
      {/* ── Page header ── */}
      <div className="overflow-hidden rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-600 px-5 py-5 text-white shadow-sm sm:px-7 sm:py-6">
        <div className="flex items-center gap-3.5">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15">
            <FaRegCommentDots aria-hidden="true" className="text-lg" />
          </div>
          <div className="min-w-0">
            <h1 className="text-lg font-bold sm:text-xl">Share Your Feedback</h1>
            <p className="mt-0.5 text-xs leading-relaxed text-cyan-100 sm:text-sm">Help us improve your gym experience. Your feedback is always appreciated.</p>
          </div>
        </div>
      </div>

      {/* ── Alerts ── */}
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{error}</p>}
      {notice && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}

      {/* ── Gym & System Feedback form ── */}
      <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-100 px-5 py-4 sm:px-6">
          <h2 className="text-base font-semibold text-slate-900">Gym and System Feedback</h2>
          <p className="mt-0.5 text-xs text-slate-500">All questions are optional. You can submit one response per Philippine calendar day.</p>
        </div>

        <div className="px-5 py-4 sm:px-6 sm:py-5">
          {loading ? (
            <p role="status" className="text-sm text-slate-500">Loading your feedback status…</p>
          ) : alreadySubmitted ? (
            <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
              <p className="text-sm font-semibold text-emerald-900">Feedback submitted for today</p>
              <p className="mt-0.5 text-xs text-emerald-700">Submitted {formatDate(todayFeedback?.submitted_at || todayFeedback?.feedback_date)}. You can share feedback again after the next Philippine calendar day begins.</p>
            </div>
          ) : (
            <form onSubmit={submitFeedback} className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                {sections.map(({ key, title, description }) => {
                  const ratingField = `${key}_rating`
                  const commentsField = `${key}_comments`
                  const hovered = hoveredRatings[key] || 0
                  return (
                    <div key={key} className="rounded-xl border border-slate-150 bg-slate-50/50 p-4">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                          <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{description}</p>
                        </div>
                        <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-400">Optional</span>
                      </div>

                      <StarRating
                        value={Number(form[ratingField])}
                        hovered={hovered}
                        sectionKey={key}
                        onSelect={(rating) => updateForm(ratingField, rating)}
                        onHover={(star) => setHoveredRatings((current) => ({ ...current, [key]: star }))}
                        onLeave={() => setHoveredRatings((current) => ({ ...current, [key]: 0 }))}
                      />

                      <label className="mt-3 block">
                        <span className="text-xs font-medium text-slate-500">Comments <span className="font-normal text-slate-400">(optional)</span></span>
                        <textarea
                          value={form[commentsField]}
                          onChange={(event) => updateForm(commentsField, event.target.value)}
                          maxLength={1000}
                          rows={2}
                          aria-describedby={`${commentsField}-counter`}
                          className="mt-1 block w-full resize-y rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-100"
                          placeholder="Write any comments or suggestions…"
                        />
                        <span id={`${commentsField}-counter`} className="mt-0.5 block text-right text-[10px] text-slate-400">
                          {form[commentsField].length}/1000
                        </span>
                      </label>
                    </div>
                  )
                })}
              </div>

              <div className="flex flex-col items-start justify-between gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center">
                <p className="text-xs text-slate-400">At least one rating or comment is needed to submit. Feedback is always optional.</p>
                <button
                  type="submit"
                  disabled={submitting || !hasFeedback(form)}
                  className="min-h-10 w-full rounded-xl bg-cyan-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-cyan-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                >
                  {submitting ? 'Submitting…' : 'Submit Feedback'}
                </button>
              </div>
            </form>
          )}
        </div>
      </section>

      {/* ── Feedback History ── */}
      <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-100 px-5 py-4 sm:px-6">
          <div className="flex items-center gap-2">
            <FaHistory aria-hidden="true" className="text-sm text-slate-400" />
            <h2 className="text-base font-semibold text-slate-900">My Feedback History</h2>
          </div>
        </div>

        <div className="px-5 py-4 sm:px-6 sm:py-5">
          {loading ? (
            <p className="text-sm text-slate-500">Loading your feedback history…</p>
          ) : history.length === 0 && legacyHistory.length === 0 ? (
            <div className="flex flex-col items-center py-6 text-center">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100">
                <FaRegCommentDots aria-hidden="true" className="text-base text-slate-400" />
              </div>
              <p className="mt-2 text-sm font-medium text-slate-600">No feedback yet</p>
              <p className="mt-0.5 text-xs text-slate-400">Your submitted feedback will appear here.</p>
            </div>
          ) : (
            <>
              {history.length > 0 && (
                <ol className="space-y-3">
                  {history.map((entry) => (
                    <li key={entry.id} className="rounded-xl border border-slate-150 p-4">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-semibold text-slate-800">{formatDate(entry.submitted_at, { dateStyle: 'medium', timeStyle: 'short' })}</p>
                        {Number(entry.is_hidden) === 1 ? (
                          <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-semibold text-amber-800">Moderated</span>
                        ) : (
                          <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-semibold text-emerald-700">Visible</span>
                        )}
                      </div>
                      <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {sections.map(({ key, title }) => {
                          const rating = Number(entry[`${key}_rating`]) || 0
                          const comment = entry[`${key}_comments`]
                          if (!rating && !comment) return null
                          return (
                            <div key={key} className="rounded-lg bg-slate-50 p-3">
                              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{title}</p>
                              {rating > 0 && (
                                <p className="mt-1 text-sm">
                                  <span className="text-amber-400">{'★'.repeat(rating)}</span>
                                  <span className="text-slate-200">{'★'.repeat(5 - rating)}</span>
                                  <span className="ml-1.5 text-xs text-slate-500">{ratingLabels[rating]}</span>
                                </p>
                              )}
                              {comment && <p className="mt-1.5 whitespace-pre-wrap break-words text-xs leading-relaxed text-slate-600">{comment}</p>}
                            </div>
                          )
                        })}
                      </div>
                      {Number(entry.is_hidden) === 1 && entry.moderation_reason && (
                        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Moderation note: {entry.moderation_reason}</p>
                      )}
                    </li>
                  ))}
                </ol>
              )}

              {legacyHistory.length > 0 && (
                <div className={history.length > 0 ? 'mt-5 border-t border-slate-100 pt-5' : ''}>
                  <h3 className="text-sm font-semibold text-slate-700">Previous gym visit ratings</h3>
                  <p className="mt-0.5 text-xs text-slate-400">These historical ratings were submitted through the former visit-based workflow.</p>
                  <ul className="mt-3 space-y-2">
                    {legacyHistory.map((entry) => (
                      <li key={entry.id} className="rounded-xl border border-slate-150 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <p className="text-sm font-semibold text-slate-800">
                            <span className="text-amber-400">{'★'.repeat(Number(entry.rating))}</span>
                            <span className="text-slate-200">{'★'.repeat(5 - Number(entry.rating))}</span>
                            <span className="ml-1.5 text-xs font-normal text-slate-500">· {formatDate(entry.created_at, { dateStyle: 'medium', timeStyle: 'short' })}</span>
                          </p>
                          {Number(entry.is_hidden) === 1 ? (
                            <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-semibold text-amber-800">Moderated</span>
                          ) : (
                            <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-semibold text-emerald-700">Visible</span>
                          )}
                        </div>
                        <p className="mt-1 text-xs text-slate-400">Gym visit: {formatDate(entry.attendance_date)}{entry.time_in ? ` at ${entry.time_in}` : ''}</p>
                        {entry.comments && <p className="mt-2 whitespace-pre-wrap break-words text-xs leading-relaxed text-slate-600">{entry.comments}</p>}
                        {entry.suggestions && <p className="mt-1.5 whitespace-pre-wrap break-words text-xs leading-relaxed text-slate-600"><span className="font-medium">Suggestion:</span> {entry.suggestions}</p>}
                        {Number(entry.is_hidden) === 1 && entry.moderation_reason && <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Moderation note: {entry.moderation_reason}</p>}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>
      </section>

      {/* ── Trainer Ratings ── */}
      {bookingsError && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{bookingsError}</p>}
      <MemberFeedback
        bookings={bookings}
        selectedBookingId={location.state?.bookingId || ''}
        onTrainerFeedbackSubmitted={(bookingId, feedbackId) => {
          setBookings((current) => current.map((booking) => (
            String(booking.id) === String(bookingId)
              ? { ...booking, trainer_feedback_id: feedbackId }
              : booking
          )))
          refreshBookings()
        }}
      />
    </div>
  )
}
