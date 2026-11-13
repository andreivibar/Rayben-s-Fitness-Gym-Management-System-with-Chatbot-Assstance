import { useEffect, useState } from 'react'
import { FaStar } from 'react-icons/fa'
import api from '../services/api'

const ratingLabels = {
  1: 'Very Poor',
  2: 'Poor',
  3: 'Average',
  4: 'Good',
  5: 'Excellent',
}

function emptyForm() {
  return { rating: 0, comments: '', suggestions: '', is_anonymous: false }
}

function formatDate(value) {
  if (!value) return '—'
  const raw = String(value)
  const date = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  return Number.isNaN(date.getTime())
    ? raw
    : new Intl.DateTimeFormat('en-PH', {
      timeZone: 'Asia/Manila',
      dateStyle: 'medium',
    }).format(date)
}

export default function MemberFeedback({ bookings = [], selectedBookingId = '', onTrainerFeedbackSubmitted }) {
  const [feedback, setFeedback] = useState([])
  const [forms, setForms] = useState({})
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [refreshToken, setRefreshToken] = useState(0)

  useEffect(() => {
    let active = true
    const fetchFeedback = async () => {
      try {
        const response = await api.get('/member_feedback.php')
        if (!response.data?.success) {
          throw new Error(response.data?.message || 'Unable to load trainer ratings.')
        }
        if (active) {
          setFeedback((response.data.feedback || []).filter((entry) => entry.feedback_category === 'trainer'))
          setError('')
        }
      } catch (requestError) {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load trainer ratings.')
      } finally {
        if (active) setLoading(false)
      }
    }
    fetchFeedback()
    return () => { active = false }
  }, [refreshToken])

  const selectedBooking = bookings.find((booking) => (
    String(booking.id) === String(selectedBookingId)
    && booking.status === 'completed'
    && Number(booking.trainer_id) > 0
  ))
  const bookingKey = String(selectedBookingId || '')
  const form = forms[bookingKey] || emptyForm()
  const saved = feedback.find((entry) => String(entry.booking_id) === String(selectedBooking?.id))

  const updateForm = (field, value) => {
    setForms((current) => ({
      ...current,
      [bookingKey]: { ...(current[bookingKey] || emptyForm()), [field]: value },
    }))
  }

  const submitFeedback = async () => {
    if (!selectedBooking || !form.rating) {
      setError('Choose a completed booking and a star rating before submitting.')
      return
    }

    setSubmitting(true)
    setError('')
    setNotice('')
    try {
      const response = await api.post('/member_feedback.php', {
        booking_id: selectedBooking.id,
        feedback_category: 'trainer',
        rating: form.rating,
        comments: form.comments,
        suggestions: form.suggestions,
        is_anonymous: form.is_anonymous,
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to submit trainer rating.')
      }
      setForms((current) => ({ ...current, [bookingKey]: emptyForm() }))
      setNotice(response.data.message || 'Thank you for sharing your trainer rating.')
      setRefreshToken((value) => value + 1)
      onTrainerFeedbackSubmitted?.(selectedBooking.id, response.data.feedback_id)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to submit trainer rating.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section id="member-feedback-heading" aria-labelledby="trainer-feedback-heading" className="mt-4 rounded-2xl bg-white p-4 shadow-sm sm:p-6">
      <div>
        <p className="text-xs font-medium uppercase tracking-[0.24em] text-slate-500">Booking feedback</p>
        <h2 id="trainer-feedback-heading" className="mt-1 text-lg font-semibold text-slate-900">Trainer Ratings</h2>
        <p className="mt-1 max-w-2xl text-sm text-slate-600">Ratings are available for completed bookings.</p>
      </div>

      {loading && <p role="status" className="mt-4 text-sm text-slate-600">Loading your trainer ratings…</p>}
      {error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {notice && <p role="status" className="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}

      {!loading && (
        <>
          {selectedBooking && (
            <div className="mt-4 rounded-2xl border border-slate-200 p-4 sm:p-5">
              <h3 className="font-semibold text-slate-900">Rate your trainer</h3>
              <p className="mt-1 text-xs text-slate-500">
                Booking {selectedBooking.booking_code || `#${selectedBooking.id}`} · {formatDate(selectedBooking.booking_date)}
              </p>
              {saved ? (
                <div className="mt-3 rounded-xl bg-slate-50 p-4 text-sm">
                  <p className="font-semibold text-slate-900">{'★'.repeat(Number(saved.rating))}{'☆'.repeat(5 - Number(saved.rating))} · {ratingLabels[saved.rating]}</p>
                  <p className="mt-1 text-slate-600">Submitted {formatDate(saved.created_at)}</p>
                  {saved.comments && <p className="mt-3 whitespace-pre-wrap break-words text-slate-700">{saved.comments}</p>}
                  {saved.suggestions && <p className="mt-2 whitespace-pre-wrap break-words text-slate-700"><span className="font-medium">Suggestion:</span> {saved.suggestions}</p>}
                  {Number(saved.is_hidden) === 1 && <p className="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">An administrator has hidden this comment from trainer-facing feedback views. Your original feedback remains on record.</p>}
                </div>
              ) : (
                <div className="mt-4 space-y-4">
                  <fieldset>
                    <legend className="text-sm font-medium text-slate-700">Your rating</legend>
                    <div className="mt-2 flex flex-wrap items-center gap-1">
                      {[1, 2, 3, 4, 5].map((rating) => (
                        <button
                          key={rating}
                          type="button"
                          onClick={() => updateForm('rating', rating)}
                          aria-label={`${rating} star${rating === 1 ? '' : 's'}: ${ratingLabels[rating]}`}
                          aria-pressed={Number(form.rating) === rating}
                          className={`flex h-11 w-11 items-center justify-center rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-400 ${Number(form.rating) >= rating ? 'text-amber-500' : 'text-slate-300'}`}
                        >
                          <FaStar aria-hidden="true" className="text-2xl" />
                        </button>
                      ))}
                      <span className="ml-2 text-sm text-slate-600">{form.rating ? ratingLabels[form.rating] : 'Choose 1–5 stars'}</span>
                    </div>
                  </fieldset>

                  <label className="block text-sm font-medium text-slate-700">
                    Comments <span className="font-normal text-slate-500">(optional)</span>
                    <textarea
                      value={form.comments}
                      onChange={(event) => updateForm('comments', event.target.value)}
                      maxLength={1000}
                      rows={3}
                      className="mt-1 block w-full resize-y rounded-xl border border-slate-300 px-3 py-2 text-sm"
                      placeholder="Share your experience with this trainer."
                    />
                  </label>

                  <label className="block text-sm font-medium text-slate-700">
                    Suggestions for improvement <span className="font-normal text-slate-500">(optional)</span>
                    <textarea
                      value={form.suggestions}
                      onChange={(event) => updateForm('suggestions', event.target.value)}
                      maxLength={1000}
                      rows={2}
                      className="mt-1 block w-full resize-y rounded-xl border border-slate-300 px-3 py-2 text-sm"
                      placeholder="Anything we could improve?"
                    />
                  </label>

                  <label className="flex items-start gap-3 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={form.is_anonymous}
                      onChange={(event) => updateForm('is_anonymous', event.target.checked)}
                      className="mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-700 focus:ring-violet-500"
                    />
                    <span>
                      Submit anonymously
                      <span className="mt-0.5 block text-xs text-slate-500">Your identity remains available to authorized administrators; trainers will not see your personal details.</span>
                    </span>
                  </label>

                  <button
                    type="button"
                    onClick={submitFeedback}
                    disabled={submitting}
                    className="min-h-11 rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {submitting ? 'Submitting…' : 'Submit trainer rating'}
                  </button>
                </div>
              )}
            </div>
          )}

          <div className="mt-6 border-t border-slate-200 pt-5">
            <h3 className="font-semibold text-slate-900">Your trainer rating history</h3>
            {feedback.length === 0 ? (
              <p className="mt-2 text-sm text-slate-500">Your submitted trainer ratings will appear here.</p>
            ) : (
              <ul className="mt-3 space-y-3">
                {feedback.map((entry) => (
                  <li key={entry.id} className="rounded-xl border border-slate-200 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold text-slate-900">{'★'.repeat(Number(entry.rating))}{'☆'.repeat(5 - Number(entry.rating))} · Trainer rating</p>
                        <p className="mt-1 text-xs text-slate-500">
                          Booking {entry.booking_code || `#${entry.booking_id}`} · Session {formatDate(entry.attendance_date)}
                          {' · '}Submitted {formatDate(entry.created_at)}
                          {entry.trainer_name ? ` · Trainer: ${entry.trainer_name}` : ''}
                        </p>
                      </div>
                      <span className="text-xs text-slate-500">{Number(entry.is_anonymous) ? 'Anonymous' : 'Identified'}</span>
                    </div>
                    {entry.comments && <p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">{entry.comments}</p>}
                    {entry.suggestions && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700"><span className="font-medium">Suggestion:</span> {entry.suggestions}</p>}
                    {Number(entry.is_hidden) === 1 && <p className="mt-3 text-xs text-amber-800">An administrator hid this comment from trainer-facing feedback views; your original record remains preserved.</p>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </section>
  )
}
