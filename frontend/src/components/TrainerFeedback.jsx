import { useEffect, useState } from 'react'
import { FaStar } from 'react-icons/fa'
import api from '../services/api'

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium' }).format(date)
}

export default function TrainerFeedback({ compact = false }) {
  const [summary, setSummary] = useState(null)
  const [feedback, setFeedback] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    const loadFeedback = async () => {
      try {
        const response = await api.get('/trainer_feedback.php')
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load your feedback.')
        if (!active) return
        setSummary(response.data.summary || null)
        setFeedback(response.data.feedback || [])
      } catch (requestError) {
        if (active) setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load your feedback.')
      } finally {
        if (active) setLoading(false)
      }
    }
    loadFeedback()
    const intervalId = window.setInterval(loadFeedback, 30000)
    window.addEventListener('focus', loadFeedback)
    return () => {
      active = false
      window.clearInterval(intervalId)
      window.removeEventListener('focus', loadFeedback)
    }
  }, [])

  const recentComments = feedback.length === 0 ? (
    <p className="mt-2 text-sm text-slate-500">No visible feedback yet.</p>
  ) : (
    <ul className="mt-3 space-y-3">
      {feedback.map((entry) => (
        <li key={entry.id} className="min-w-0 rounded-xl border border-slate-200 bg-slate-50/60 p-3 sm:p-4">
          <div className="flex flex-wrap justify-between gap-2">
            <p aria-label={`${entry.rating} out of 5 stars`} className="font-semibold tracking-wide text-amber-600">{'★'.repeat(Number(entry.rating))}{'☆'.repeat(Math.max(0, 5 - Number(entry.rating)))}</p>
            <span className="text-xs text-slate-500">{Number(entry.is_anonymous) ? 'Anonymous' : 'Member'} · {formatDate(entry.feedback_date)}</span>
          </div>
          {entry.comments && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700">{entry.comments}</p>}
          {entry.suggestions && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700"><span className="font-medium">Suggestion:</span> {entry.suggestions}</p>}
        </li>
      ))}
    </ul>
  )

  return (
    <section aria-labelledby="trainer-feedback-heading" className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
      <p className="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">Member feedback</p>
      <h2 id="trainer-feedback-heading" className="mt-1 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">Your service ratings</h2>
      {loading ? (
        <p role="status" className="mt-4 text-sm text-slate-500">Loading your ratings…</p>
      ) : error ? (
        <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>
      ) : (
        <>
          <div className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <div className="flex items-center gap-4 rounded-xl bg-cyan-50 p-4">
              <div>
                <p className="text-xs font-medium text-slate-600">Average rating</p>
                <p className="mt-1 flex items-center gap-1.5 text-3xl font-bold tracking-tight text-cyan-900">
                  {summary?.average_rating ?? '—'} <FaStar aria-hidden="true" className="text-xl text-amber-500" />
                </p>
              </div>
              <div className="ml-auto border-l border-cyan-200 pl-4">
                <p className="text-xs font-medium text-slate-600">Total ratings</p>
                <p className="mt-1 text-2xl font-bold text-slate-900">{summary?.total ?? 0}</p>
              </div>
            </div>
            <div className="rounded-xl border border-slate-200 p-3 sm:p-4">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Rating distribution</p>
              <div className="space-y-2">
                {[5, 4, 3, 2, 1].map((rating) => {
                  const count = Number(summary?.distribution?.[rating] ?? 0)
                  const total = Number(summary?.total ?? 0)
                  const percentage = total > 0 ? Math.min(100, (count / total) * 100) : 0
                  return (
                    <div key={rating} className="grid grid-cols-[2.5rem_minmax(0,1fr)_2rem] items-center gap-2 text-xs">
                      <span className="whitespace-nowrap text-slate-600">{rating} <FaStar aria-hidden="true" className="inline text-amber-500" /></span>
                      <span className="h-2 overflow-hidden rounded-full bg-slate-100">
                        <span className="block h-full rounded-full bg-cyan-600" style={{ width: `${percentage}%` }} />
                      </span>
                      <span className="text-right font-medium text-slate-700">{count}</span>
                    </div>
                  )
                })}
              </div>
            </div>
          </div>
          <p className="mt-3 text-xs leading-5 text-slate-500">Aggregate ratings include all submitted feedback. Moderated comments are not shown here; member names and personal details are never shared with trainers.</p>
          {compact ? (
            <details className="mt-4 border-t border-slate-200 pt-3">
              <summary className="min-h-10 cursor-pointer py-2 text-sm font-semibold text-slate-700">
                Recent comments ({feedback.length})
              </summary>
              {recentComments}
            </details>
          ) : (
            <div className="mt-5 border-t border-slate-200 pt-4">
              <h3 className="text-sm font-semibold text-slate-900">Recent comments</h3>
              {recentComments}
            </div>
          )}
        </>
      )}
    </section>
  )
}
