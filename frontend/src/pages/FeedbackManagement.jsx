import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaChevronLeft, FaChevronRight, FaEye, FaEyeSlash, FaSearch, FaStar, FaTimes } from 'react-icons/fa'
import api from '../services/api'

const TABS = [
  { id: 'gym_system', label: 'Gym & System Feedback' },
  { id: 'trainer', label: 'Trainer Ratings' },
]

const GYM_CATEGORIES = [
  { key: 'overall', label: 'Overall gym experience', color: '#6d28d9' },
  { key: 'facilities', label: 'Facilities and equipment', color: '#0284c7' },
  { key: 'services', label: 'Gym services', color: '#059669' },
  { key: 'system', label: 'System experience', color: '#d97706' },
]

const createInitialFilters = () => ({
  gym_system: {
    feedback_section: 'all',
    rating: '',
    moderation: 'all',
    date_from: '',
    date_to: '',
    search: '',
  },
  trainer: {
    trainer_id: '',
    rating: '',
    moderation: 'all',
    date_from: '',
    date_to: '',
    search: '',
  },
})

const formatDate = (value, options = { dateStyle: 'medium' }) => {
  if (!value) return '—'
  const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}+08:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', ...options }).format(date)
}

const ratingValue = (value) => value === null || value === undefined || value === '' ? null : Number(value)

function RatingStars({ value, compact = false }) {
  const rating = ratingValue(value)
  if (rating === null) return <span className="text-sm text-slate-400">Not provided</span>
  return (
    <span className={`inline-flex items-center gap-1 ${compact ? 'text-xs' : 'text-sm'}`} aria-label={`${rating} out of 5 stars`}>
      <span className="tracking-wide text-amber-500" aria-hidden="true">{'★'.repeat(rating)}{'☆'.repeat(5 - rating)}</span>
      <span className="font-medium text-slate-700">{rating}/5</span>
    </span>
  )
}

function StatusBadge({ hidden }) {
  return hidden
    ? <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800"><FaEyeSlash aria-hidden="true" /> Hidden</span>
    : <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800"><FaEye aria-hidden="true" /> Visible</span>
}

function MetricCard({ label, value, detail, loading }) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      {loading ? (
        <div className="mt-3 h-8 w-24 animate-pulse rounded bg-slate-100" aria-label="Loading statistic" />
      ) : (
        <>
          <p className="mt-2 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
          {detail && <p className="mt-1 text-xs text-slate-500">{detail}</p>}
        </>
      )}
    </article>
  )
}

function RatingDistribution({ summary }) {
  const total = Number(summary?.total || 0)
  return (
    <div className="mt-4 space-y-2.5">
      {[5, 4, 3, 2, 1].map((rating) => {
        const count = Number(summary?.distribution?.[rating] || 0)
        const percent = total ? Math.round((count / total) * 100) : 0
        return (
          <div key={rating} className="grid grid-cols-[2.5rem_1fr_5.25rem] items-center gap-3 text-xs">
            <span className="font-medium text-slate-600">{rating} <span className="text-amber-500">★</span></span>
            <div
              className="h-2 overflow-hidden rounded-full bg-slate-100"
              role="progressbar"
              aria-label={`${rating}-star feedback`}
              aria-valuemin="0"
              aria-valuemax="100"
              aria-valuenow={percent}
            >
              <div className="h-full rounded-full bg-violet-600 transition-[width]" style={{ width: `${percent}%` }} />
            </div>
            <span className="text-right tabular-nums text-slate-500">{count} · {percent}%</span>
          </div>
        )
      })}
    </div>
  )
}

function TrendChart({ category, trends, feedbackSection = 'all' }) {
  const isGym = category === 'gym_system'
  const allSeries = isGym
    ? GYM_CATEGORIES.map((item) => ({
      ...item,
      value: (row) => ratingValue(row[`${item.key}_average`]),
    }))
    : [{ key: 'trainer', label: 'Trainer rating', color: '#6d28d9', value: (row) => ratingValue(row.average_rating) }]
  const series = isGym && feedbackSection !== 'all'
    ? allSeries.filter((item) => item.key === feedbackSection)
    : allSeries
  const chartRows = (trends || []).filter((row) => series.some((item) => item.value(row) !== null))
  const hasTrend = series.some((item) => chartRows.filter((row) => item.value(row) !== null).length > 1)

  if (!hasTrend) {
    return (
      <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
        <p className="text-sm font-medium text-slate-700">Not enough monthly data for a trend yet</p>
        <p className="mt-1 text-xs text-slate-500">A category needs ratings in at least two different months.</p>
      </div>
    )
  }

  const width = 760
  const height = 270
  const left = 42
  const right = 18
  const top = 14
  const bottom = 42
  const plotWidth = width - left - right
  const plotHeight = height - top - bottom
  const x = (index) => left + (chartRows.length === 1 ? plotWidth / 2 : (index * plotWidth) / (chartRows.length - 1))
  const y = (value) => top + ((5 - value) / 4) * plotHeight

  return (
    <div>
      <div className="mb-4 flex flex-wrap gap-x-5 gap-y-2">
        {series.map((item) => (
          <span key={item.key} className="inline-flex items-center gap-2 text-xs text-slate-600">
            <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: item.color }} aria-hidden="true" />
            {item.label}
          </span>
        ))}
      </div>
      <div className="overflow-x-auto">
        <svg
          className="h-auto min-w-[620px] w-full"
          viewBox={`0 0 ${width} ${height}`}
          role="img"
          aria-label={isGym ? 'Monthly average ratings by gym and system category' : 'Monthly average trainer rating'}
        >
          {[1, 2, 3, 4, 5].map((value) => (
            <g key={value}>
              <line x1={left} x2={width - right} y1={y(value)} y2={y(value)} stroke="#e2e8f0" strokeDasharray={value === 1 ? undefined : '3 5'} />
              <text x={left - 10} y={y(value) + 4} textAnchor="end" fill="#64748b" fontSize="11">{value}</text>
            </g>
          ))}
          {series.map((item) => {
            const points = chartRows.flatMap((row, index) => {
              const value = item.value(row)
              return value === null ? [] : [{ row, index, value }]
            })
            if (points.length < 2) return null
            return (
              <g key={item.key}>
                <polyline
                  fill="none"
                  stroke={item.color}
                  strokeWidth="2.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  points={points.map((point) => `${x(point.index)},${y(point.value)}`).join(' ')}
                />
                {points.map((point) => (
                  <circle key={point.row.month} cx={x(point.index)} cy={y(point.value)} r="4" fill="white" stroke={item.color} strokeWidth="2">
                    <title>{`${point.row.month}: ${Number(point.value).toFixed(2)} / 5`}</title>
                  </circle>
                ))}
              </g>
            )
          })}
          {chartRows.map((row, index) => (
            <text key={row.month} x={x(index)} y={height - 13} textAnchor="middle" fill="#64748b" fontSize="10">{row.month}</text>
          ))}
        </svg>
      </div>
      <p className="mt-2 text-xs text-slate-500">Monthly averages include only submitted ratings. Blank categories are not treated as zero.</p>
    </div>
  )
}

function RecordDetails({ entry, onClose, onHide, busy, error }) {
  const closeButton = useRef(null)
  const dialogPanel = useRef(null)
  const [reason, setReason] = useState('')
  const isGym = entry.feedback_type === 'gym_system'
  const isHidden = Number(entry.is_hidden) === 1

  useEffect(() => {
    closeButton.current?.focus()
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onClose()
      if (event.key === 'Tab') {
        const focusable = dialogPanel.current?.querySelectorAll('button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), a[href]')
        if (!focusable?.length) return
        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  const sections = isGym
    ? GYM_CATEGORIES.map((item) => ({
      ...item,
      rating: entry[`${item.key}_rating`],
      comments: entry[`${item.key}_comments`],
    }))
    : []

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/40 p-0 sm:items-center sm:p-4"
      onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="feedback-details-title"
        ref={dialogPanel}
        className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl"
      >
        <header className="sticky top-0 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-violet-700">Feedback details</p>
            <h2 id="feedback-details-title" className="mt-1 text-lg font-semibold text-slate-900">
              {isGym ? 'Gym & System Feedback' : `Trainer rating for ${entry.trainer_name || 'trainer'}`}
            </h2>
          </div>
          <button ref={closeButton} type="button" onClick={onClose} aria-label="Close feedback details" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-500">
            <FaTimes aria-hidden="true" />
          </button>
        </header>

        <div className="space-y-5 p-5">
          <div className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
            {isGym ? (
              <>
                <div><p className="text-xs text-slate-500">Member</p><p className="mt-1 text-sm font-medium text-slate-800">{entry.member_name || `Member #${entry.member_id}`}</p></div>
                <div><p className="text-xs text-slate-500">Submitted</p><p className="mt-1 text-sm font-medium text-slate-800">{formatDate(entry.submitted_at, { dateStyle: 'medium', timeStyle: 'short' })}</p></div>
              </>
            ) : (
              <>
                <div><p className="text-xs text-slate-500">Booking reference</p><p className="mt-1 text-sm font-medium text-slate-800">{entry.booking_code || `#${entry.booking_id}`}</p></div>
                <div><p className="text-xs text-slate-500">Booking date</p><p className="mt-1 text-sm font-medium text-slate-800">{formatDate(entry.attendance_date)}</p></div>
                <div><p className="text-xs text-slate-500">Rating submitted</p><p className="mt-1 text-sm font-medium text-slate-800">{formatDate(entry.created_at, { dateStyle: 'medium', timeStyle: 'short' })}</p></div>
              </>
            )}
            <div><p className="text-xs text-slate-500">Moderation status</p><div className="mt-1"><StatusBadge hidden={isHidden} /></div></div>
          </div>

          {isGym ? (
            <div className="space-y-3">
              {sections.map((section) => (
                <article key={section.key} className="rounded-xl border border-slate-200 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold text-slate-800">{section.label}</h3>
                    <RatingStars value={section.rating} />
                  </div>
                  <p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-600">{section.comments || 'No written comment provided.'}</p>
                </article>
              ))}
            </div>
          ) : (
            <article className="rounded-xl border border-slate-200 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-800">Trainer rating</h3>
                <RatingStars value={entry.rating} />
              </div>
              <p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">{entry.comments || 'No written feedback provided.'}</p>
              {entry.suggestions && <p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700"><span className="font-medium">Suggestion:</span> {entry.suggestions}</p>}
            </article>
          )}

          <section className="rounded-xl border border-slate-200 p-4">
            <h3 className="text-sm font-semibold text-slate-800">Latest moderation action</h3>
            {entry.moderated_at ? (
              <div className="mt-2 space-y-1 text-sm text-slate-600">
                <p>{isHidden ? 'Comments hidden' : 'Moderation record'}</p>
                {entry.moderation_reason && <p><span className="font-medium">Reason:</span> {entry.moderation_reason}</p>}
                <p>{formatDate(entry.moderated_at, { dateStyle: 'medium', timeStyle: 'short' })}{entry.moderated_by_name ? ` · ${entry.moderated_by_name}` : ''}</p>
              </div>
            ) : <p className="mt-2 text-sm text-slate-500">No moderation action recorded.</p>}
            <p className="mt-2 text-xs text-slate-500">The current feedback record stores its latest moderation action; earlier actions are not available in the existing data.</p>
          </section>

          {!isHidden && (
            <form onSubmit={(event) => { event.preventDefault(); onHide(entry, reason) }} className="rounded-xl border border-rose-100 bg-rose-50/50 p-4">
              <label htmlFor="moderation-reason" className="text-sm font-semibold text-slate-800">Hide written comments</label>
              <p className="mt-1 text-xs text-slate-500">The original rating and feedback record will remain preserved.</p>
              {error && <p role="alert" className="mt-3 rounded-lg bg-rose-100 px-3 py-2 text-sm text-rose-800">{error}</p>}
              <textarea
                id="moderation-reason"
                required
                maxLength={500}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                rows={2}
                className="mt-3 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100"
                placeholder="Reason for hiding these comments"
              />
              <button type="submit" disabled={busy || !reason.trim()} className="mt-3 min-h-10 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
                {busy ? 'Saving…' : 'Hide comments'}
              </button>
            </form>
          )}
        </div>
      </section>
    </div>
  )
}

function TrainerFeedbackDialog({ trainer, report, loading, error, page, onClose, onRetry, onPageChange }) {
  const closeButton = useRef(null)
  const dialogPanel = useRef(null)
  const [focusReturnTarget] = useState(() => document.activeElement)
  const trainerSummary = report?.trainer_ratings?.find((item) => Number(item.trainer_id) === Number(trainer.trainer_id))
  const records = report?.feedback || []
  const pagination = report?.pagination

  useEffect(() => {
    closeButton.current?.focus()
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') onClose()
      if (event.key === 'Tab') {
        const focusable = dialogPanel.current?.querySelectorAll('button:not([disabled]), a[href]')
        if (!focusable?.length) return
        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      focusReturnTarget?.focus?.()
    }
  }, [focusReturnTarget, onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/40 p-0 sm:items-center sm:p-4"
      onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}
    >
      <section
        ref={dialogPanel}
        role="dialog"
        aria-modal="true"
        aria-labelledby="trainer-feedback-title"
        aria-busy={loading}
        className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl"
      >
        <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-violet-700">Trainer feedback</p>
            <h2 id="trainer-feedback-title" className="mt-1 text-lg font-semibold text-slate-900">{trainer.trainer_name}</h2>
            <p className="mt-1 text-sm text-slate-600">Booking-linked ratings and member comments</p>
          </div>
          <button ref={closeButton} type="button" onClick={onClose} aria-label="Close trainer feedback" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-violet-500">
            <FaTimes aria-hidden="true" />
          </button>
        </header>

        <div className="space-y-5 p-5">
          {error && (
            <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
              <p>{error}</p>
              <button type="button" onClick={onRetry} className="rounded-lg border border-rose-300 bg-white px-3 py-2 font-semibold hover:bg-rose-100">Retry</button>
            </div>
          )}

          <section aria-label="Selected trainer summary" className="grid gap-3 sm:grid-cols-2">
            <MetricCard
              label="Average rating"
              value={trainerSummary?.average_rating === null || trainerSummary?.average_rating === undefined ? '—' : `${Number(trainerSummary.average_rating).toFixed(2)} / 5`}
              detail={trainerSummary ? 'Based on current filters' : loading ? '' : 'No matching ratings'}
              loading={loading && !report}
            />
            <MetricCard
              label="Total ratings"
              value={trainerSummary?.total ?? '—'}
              detail={trainerSummary ? 'For this trainer' : loading ? '' : 'No matching ratings'}
              loading={loading && !report}
            />
          </section>

          <section>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h3 className="text-base font-semibold text-slate-900">Individual feedback</h3>
              {report && <p className="text-xs text-slate-500">{pagination?.total ?? 0} matching ratings</p>}
            </div>

            {loading && !report ? (
              <div className="mt-3 space-y-3" aria-label="Loading trainer feedback">
                {[1, 2, 3].map((item) => <div key={item} className="h-24 animate-pulse rounded-xl bg-slate-100" />)}
              </div>
            ) : error && !report ? (
              <p className="mt-3 rounded-lg bg-slate-50 p-5 text-center text-sm text-slate-500">Trainer feedback could not be loaded.</p>
            ) : records.length === 0 ? (
              <p className="mt-3 rounded-lg bg-slate-50 p-5 text-center text-sm text-slate-600">No feedback matches this trainer and the current filters.</p>
            ) : (
              <ul className="mt-3 space-y-3">
                {records.map((entry) => (
                  <li key={entry.id} className="rounded-xl border border-slate-200 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <RatingStars value={entry.rating} />
                        <p className="mt-1 text-xs text-slate-500">
                          {Number(entry.is_anonymous) === 1 ? 'Anonymous member' : (entry.member_name || `Member #${entry.member_id}`)}
                          {' · '}Submitted {formatDate(entry.created_at, { dateStyle: 'medium', timeStyle: 'short' })}
                        </p>
                      </div>
                      <StatusBadge hidden={Number(entry.is_hidden) === 1} />
                    </div>
                    <p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">{entry.comments || 'No written comment provided.'}</p>
                    {entry.suggestions && <p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700"><span className="font-medium">Suggestion:</span> {entry.suggestions}</p>}
                    <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                      Booking: {entry.booking_code || `#${entry.booking_id}`} · Session date: {formatDate(entry.attendance_date)}
                    </p>
                  </li>
                ))}
              </ul>
            )}

            {pagination?.pages > 1 && (
              <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                <button type="button" onClick={() => onPageChange(Math.max(1, page - 1))} disabled={page <= 1 || loading} className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                  <FaChevronLeft aria-hidden="true" /> Previous
                </button>
                <span className="text-sm text-slate-500">Page {page} of {pagination.pages}</span>
                <button type="button" onClick={() => onPageChange(Math.min(pagination.pages, page + 1))} disabled={page >= pagination.pages || loading} className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                  Next <FaChevronRight aria-hidden="true" />
                </button>
              </div>
            )}
          </section>
        </div>
      </section>
    </div>
  )
}

function FeedbackManagement() {
  const [activeTab, setActiveTab] = useState('gym_system')
  const [draftByTab, setDraftByTab] = useState(createInitialFilters)
  const [appliedByTab, setAppliedByTab] = useState(createInitialFilters)
  const [pageByTab, setPageByTab] = useState({ gym_system: 1, trainer: 1 })
  const [reports, setReports] = useState({})
  const [loadingByTab, setLoadingByTab] = useState({ gym_system: true, trainer: false })
  const [errors, setErrors] = useState({})
  const [refreshToken, setRefreshToken] = useState(0)
  const [selectedEntry, setSelectedEntry] = useState(null)
  const [moderatingId, setModeratingId] = useState(null)
  const [moderationError, setModerationError] = useState('')
  const [selectedTrainer, setSelectedTrainer] = useState(null)
  const [trainerDetail, setTrainerDetail] = useState(null)
  const [trainerDetailPage, setTrainerDetailPage] = useState(1)
  const [trainerDetailLoading, setTrainerDetailLoading] = useState(false)
  const [trainerDetailError, setTrainerDetailError] = useState('')
  const [trainerDetailRetry, setTrainerDetailRetry] = useState(0)
  const recordsRef = useRef(null)

  const filters = appliedByTab[activeTab]
  const draft = draftByTab[activeTab]
  const page = pageByTab[activeTab]
  const report = reports[activeTab]
  const loading = loadingByTab[activeTab]
  const error = errors[activeTab]

  useEffect(() => {
    let active = true
    const fetchReport = async () => {
      try {
        const response = await api.get('/feedback_management.php', {
          params: { category: activeTab, ...filters, page, per_page: 20 },
        })
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load feedback reports.')
        if (!active) return
        setReports((current) => ({ ...current, [activeTab]: response.data }))
        setErrors((current) => ({ ...current, [activeTab]: '' }))
      } catch (requestError) {
        if (active) setErrors((current) => ({
          ...current,
          [activeTab]: requestError?.response?.data?.message || requestError?.message || 'Unable to load feedback reports.',
        }))
      } finally {
        if (active) setLoadingByTab((current) => ({ ...current, [activeTab]: false }))
      }
    }

    fetchReport()
    const intervalId = window.setInterval(fetchReport, 30000)
    window.addEventListener('focus', fetchReport)
    return () => {
      active = false
      window.clearInterval(intervalId)
      window.removeEventListener('focus', fetchReport)
    }
  }, [activeTab, filters, page, refreshToken])

  useEffect(() => {
    if (!selectedTrainer) return undefined

    const controller = new AbortController()
    let active = true
    setTrainerDetailLoading(true)
    setTrainerDetailError('')
    setTrainerDetail(null)

    const fetchTrainerDetail = async () => {
      try {
        const response = await api.get('/feedback_management.php', {
          params: {
            category: 'trainer',
            ...appliedByTab.trainer,
            trainer_id: selectedTrainer.trainer_id,
            page: trainerDetailPage,
            per_page: 20,
          },
          signal: controller.signal,
        })
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load trainer feedback.')
        if (active) setTrainerDetail(response.data)
      } catch (requestError) {
        if (active && requestError?.code !== 'ERR_CANCELED') {
          setTrainerDetailError(requestError?.response?.data?.message || requestError?.message || 'Unable to load trainer feedback.')
        }
      } finally {
        if (active) setTrainerDetailLoading(false)
      }
    }

    const requestId = window.setTimeout(fetchTrainerDetail, 0)
    return () => {
      active = false
      window.clearTimeout(requestId)
      controller.abort()
    }
  }, [selectedTrainer, trainerDetailPage, trainerDetailRetry, appliedByTab.trainer])

  const updateDraft = (key, value) => {
    setDraftByTab((current) => ({
      ...current,
      [activeTab]: { ...current[activeTab], [key]: value },
    }))
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setLoadingByTab((current) => ({ ...current, [activeTab]: true }))
    setErrors((current) => ({ ...current, [activeTab]: '' }))
    setReports((current) => ({ ...current, [activeTab]: null }))
    setAppliedByTab((current) => ({ ...current, [activeTab]: { ...draft } }))
    setPageByTab((current) => ({ ...current, [activeTab]: 1 }))
  }

  const resetFilters = () => {
    const initial = createInitialFilters()[activeTab]
    setDraftByTab((current) => ({ ...current, [activeTab]: initial }))
    setAppliedByTab((current) => ({ ...current, [activeTab]: initial }))
    setPageByTab((current) => ({ ...current, [activeTab]: 1 }))
    setLoadingByTab((current) => ({ ...current, [activeTab]: true }))
    setReports((current) => ({ ...current, [activeTab]: null }))
  }

  const selectTab = (tab) => {
    setActiveTab(tab)
    if (!reports[tab]) setLoadingByTab((current) => ({ ...current, [tab]: true }))
  }

  const changePage = (nextPage) => {
    setLoadingByTab((current) => ({ ...current, [activeTab]: true }))
    setReports((current) => ({ ...current, [activeTab]: null }))
    setPageByTab((current) => ({ ...current, [activeTab]: nextPage }))
  }

  const hideFeedback = async (entry, reason) => {
    setModeratingId(entry.id)
    setModerationError('')
    setErrors((current) => ({ ...current, [activeTab]: '' }))
    try {
      const response = await api.post('/feedback_management.php', {
        action: 'hide',
        feedback_id: entry.id,
        feedback_type: entry.feedback_type || entry.feedback_category,
        moderation_reason: reason.trim(),
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to moderate feedback.')
      setSelectedEntry(null)
      setModerationError('')
      setReports((current) => ({ ...current, [activeTab]: null }))
      setLoadingByTab((current) => ({ ...current, [activeTab]: true }))
      setRefreshToken((value) => value + 1)
    } catch (requestError) {
      setModerationError(requestError?.response?.data?.message || requestError?.message || 'Unable to moderate feedback.')
    } finally {
      setModeratingId(null)
    }
  }

  const trainerSummary = report?.aggregates?.trainer
  const trainerRatings = report?.trainer_ratings || []
  const qualifiedTrainers = trainerRatings.filter((trainer) => trainer.total >= 5 && trainer.average_rating !== null)
  const highestRated = qualifiedTrainers[0] || null
  const mostRated = [...trainerRatings].filter((trainer) => trainer.total > 0).sort((a, b) => b.total - a.total || (b.average_rating || 0) - (a.average_rating || 0))[0] || null
  const gymCategories = useMemo(
    () => GYM_CATEGORIES.filter((item) => filters.feedback_section === 'all' || filters.feedback_section === item.key),
    [filters.feedback_section],
  )

  const viewTrainerFeedback = (trainer) => {
    if (String(selectedTrainer?.trainer_id) === String(trainer.trainer_id)) return
    setTrainerDetail(null)
    setTrainerDetailPage(1)
    setTrainerDetailError('')
    setSelectedTrainer(trainer)
  }

  const closeTrainerFeedback = useCallback(() => {
    setSelectedTrainer(null)
    setTrainerDetail(null)
    setTrainerDetailError('')
  }, [])

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-violet-700">Reports & insights</p>
            <h1 className="mt-1 text-2xl font-semibold tracking-tight">Feedback Management</h1>
            <p className="mt-1 text-sm text-slate-600">Monitor member experiences, review feedback, and evaluate trainer performance.</p>
          </div>
          <Link to="/dashboard" className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-violet-500">
            Back to dashboard
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <div role="tablist" aria-label="Feedback categories" className="flex w-full max-w-xl rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              id={`feedback-tab-${tab.id}`}
              type="button"
              role="tab"
              aria-selected={activeTab === tab.id}
              aria-controls="feedback-tab-panel"
              onClick={() => selectTab(tab.id)}
              className={`min-h-11 flex-1 rounded-lg px-3 py-2 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-1 ${
                activeTab === tab.id ? 'bg-violet-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div id="feedback-tab-panel" role="tabpanel" aria-labelledby={`feedback-tab-${activeTab}`} className="space-y-6">
          <form onSubmit={applyFilters} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-sm font-semibold text-slate-900">Filters</h2>
                <p className="mt-0.5 text-xs text-slate-500">Analytics and records update together for this tab.</p>
              </div>
              <button type="button" onClick={resetFilters} className="rounded-md px-2 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-500">Reset filters</button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
              {activeTab === 'gym_system' ? (
                <label className="text-xs font-semibold text-slate-600">
                  Feedback category
                  <select value={draft.feedback_section} onChange={(event) => updateDraft('feedback_section', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="all">All categories</option>
                    {GYM_CATEGORIES.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
                  </select>
                </label>
              ) : (
                <label className="text-xs font-semibold text-slate-600">
                  Trainer
                  <select value={draft.trainer_id} onChange={(event) => updateDraft('trainer_id', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="">All trainers</option>
                    {(reports.trainer?.trainers || []).map((trainer) => <option key={trainer.trainer_id} value={trainer.trainer_id}>{trainer.trainer_name}</option>)}
                  </select>
                </label>
              )}
              <label className="text-xs font-semibold text-slate-600">
                Rating
                <select value={draft.rating} onChange={(event) => updateDraft('rating', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100">
                  <option value="">All ratings</option>
                  {[5, 4, 3, 2, 1].map((rating) => <option key={rating} value={rating}>{rating} star{rating === 1 ? '' : 's'}</option>)}
                </select>
              </label>
              <label className="text-xs font-semibold text-slate-600">
                Moderation status
                <select value={draft.moderation} onChange={(event) => updateDraft('moderation', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100">
                  <option value="all">All statuses</option>
                  <option value="visible">Visible</option>
                  <option value="hidden">Hidden</option>
                </select>
              </label>
              <label className="text-xs font-semibold text-slate-600">
                Submitted from
                <input type="date" value={draft.date_from} onChange={(event) => updateDraft('date_from', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100" />
              </label>
              <label className="text-xs font-semibold text-slate-600">
                Submitted through
                <input type="date" value={draft.date_to} onChange={(event) => updateDraft('date_to', event.target.value)} className="mt-1.5 min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal text-slate-800 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100" />
              </label>
              <label className="text-xs font-semibold text-slate-600">
                Search feedback
                <span className="relative mt-1.5 block">
                  <FaSearch aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input value={draft.search} onChange={(event) => updateDraft('search', event.target.value)} maxLength={100} className="min-h-10 w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm font-normal text-slate-800 placeholder:text-slate-400 focus:border-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-100" placeholder="Comments, name or booking" />
                </span>
              </label>
              <div className="flex items-end sm:col-span-2 lg:col-span-3 xl:col-span-6">
                <button type="submit" className="min-h-10 rounded-lg bg-violet-700 px-5 py-2 text-sm font-semibold text-white transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2">Apply filters</button>
              </div>
            </div>
            {(filters.date_from || filters.date_to || filters.rating || filters.moderation !== 'all' || filters.search || filters.feedback_section && filters.feedback_section !== 'all' || filters.trainer_id) && (
              <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500" aria-live="polite">
                Filters active
                {filters.date_from ? ` · From ${filters.date_from}` : ''}
                {filters.date_to ? ` · Through ${filters.date_to}` : ''}
                {filters.rating ? ` · ${filters.rating} stars` : ''}
                {filters.moderation !== 'all' ? ` · ${filters.moderation}` : ''}
                {filters.search ? ' · Search applied' : ''}
              </p>
            )}
          </form>

          {error && (
            <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
              <p>{error}</p>
              <button type="button" onClick={() => { setLoadingByTab((current) => ({ ...current, [activeTab]: true })); setRefreshToken((value) => value + 1) }} className="rounded-lg border border-rose-300 bg-white px-3 py-2 font-semibold hover:bg-rose-100">Retry</button>
            </div>
          )}

          {report && (
            <p className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">
              Hidden feedback remains included in rating summaries when status is set to “All” or “Hidden.” Hiding removes written comments from trainer-facing views; original ratings and records are retained.
            </p>
          )}

          {activeTab === 'gym_system' ? (
            <>
              <section aria-label="Gym and system feedback summary" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <MetricCard label="Total feedback submissions" value={report?.aggregates?.gym_system?.total ?? '—'} detail={report ? 'Daily member submissions' : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
                {GYM_CATEGORIES.map((item) => {
                  const summary = report?.category_summaries?.[item.key]
                  return <MetricCard key={item.key} label={`Average ${item.key === 'overall' ? 'overall' : item.label.toLowerCase()}`} value={summary?.average_rating === null || summary?.average_rating === undefined ? '—' : `${Number(summary.average_rating).toFixed(2)} / 5`} detail={summary ? `${summary.total} ratings` : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
                })}
              </section>

              <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-end justify-between gap-2">
                  <div><h2 className="text-base font-semibold">Rating distribution</h2><p className="mt-1 text-xs text-slate-500">Counts and percentages use only records with a rating in that category.</p></div>
                </div>
                {!report && loading ? (
                  <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">{GYM_CATEGORIES.map((item) => <div key={item.key} className="h-40 animate-pulse rounded-xl bg-slate-100" />)}</div>
                ) : gymCategories.length === 0 || !report ? (
                  <p className="mt-5 rounded-lg bg-slate-50 p-5 text-center text-sm text-slate-500">Rating distributions are unavailable until feedback loads.</p>
                ) : (
                  <div className="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    {gymCategories.map((item) => {
                      const summary = report.category_summaries?.[item.key]
                      return (
                        <article key={item.key} className="rounded-xl border border-slate-200 p-4">
                          <div className="flex items-start justify-between gap-2">
                            <h3 className="text-sm font-semibold text-slate-800">{item.label}</h3>
                            <span className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500"><FaStar className="text-amber-500" aria-hidden="true" /> {summary?.average_rating ?? '—'}</span>
                          </div>
                          <RatingDistribution summary={summary} />
                          <p className="mt-3 text-xs text-slate-500">{summary?.total ?? 0} submitted rating{summary?.total === 1 ? '' : 's'}</p>
                        </article>
                      )
                    })}
                  </div>
                )}
              </section>
            </>
          ) : (
            <>
              <section aria-label="Trainer ratings summary" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Total trainer ratings" value={trainerSummary?.total ?? '—'} detail={report ? 'Booking-linked feedback' : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
                <MetricCard label="Average trainer rating" value={trainerSummary?.average_rating === null || trainerSummary?.average_rating === undefined ? '—' : `${Number(trainerSummary.average_rating).toFixed(2)} / 5`} detail={report ? 'Across matching ratings' : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
                <MetricCard label="Highest-rated trainer" value={highestRated?.trainer_name || '—'} detail={highestRated ? `${highestRated.average_rating} / 5 · ${highestRated.total} ratings (minimum 5)` : report ? 'At least 5 ratings required' : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
                <MetricCard label="Most ratings" value={mostRated?.trainer_name || '—'} detail={mostRated ? `${mostRated.total} ratings · ${mostRated.average_rating ?? '—'} / 5` : report ? 'No ratings match these filters' : loading ? '' : 'Statistics unavailable'} loading={!report && loading} />
              </section>

              <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                  <h2 className="text-base font-semibold">Trainer performance</h2>
                  <p className="mt-1 text-xs text-slate-500">Trainer comparisons use the current filters. Highest-rated requires at least five ratings.</p>
                </div>
                {!report && loading ? <div className="mt-4 h-40 animate-pulse rounded-lg bg-slate-100" /> : trainerRatings.length === 0 ? (
                  <p className="mt-4 rounded-lg bg-slate-50 p-5 text-center text-sm text-slate-500">No trainer ratings match these filters.</p>
                ) : (
                  <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[680px] text-left text-sm">
                      <thead><tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500"><th className="py-3 pr-4">Trainer</th><th className="py-3 pr-4">Average</th><th className="py-3 pr-4">Ratings</th><th className="py-3 pr-4">Distribution · 5 to 1 stars</th><th className="py-3 text-right">Action</th></tr></thead>
                      <tbody>
                        {trainerRatings.map((trainer) => (
                          <tr key={trainer.trainer_id} className="border-b border-slate-100 last:border-0">
                            <td className="py-3 pr-4 font-medium text-slate-800">{trainer.trainer_name}</td>
                            <td className="py-3 pr-4"><RatingStars value={trainer.average_rating} compact /></td>
                            <td className="py-3 pr-4 tabular-nums text-slate-600">{trainer.total}</td>
                            <td className="py-3 pr-4">
                              <div className="flex flex-wrap gap-1.5">
                                {[5, 4, 3, 2, 1].map((rating) => <span key={rating} className="rounded-md bg-slate-50 px-1.5 py-1 text-[11px] text-slate-600">{rating}★ {trainer.distribution?.[rating] ?? 0}</span>)}
                              </div>
                            </td>
                            <td className="py-3 text-right">
                              <button type="button" onClick={() => viewTrainerFeedback(trainer)} className="rounded-md px-2.5 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-500">View feedback</button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>
            </>
          )}

          <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4">
              <h2 className="text-base font-semibold">{activeTab === 'gym_system' ? 'Monthly satisfaction trends' : 'Monthly trainer rating trend'}</h2>
              <p className="mt-1 text-xs text-slate-500">Monthly rating averages over the most recent 12 months, constrained by the active filters.</p>
            </div>
            {!report && loading ? <div className="h-64 animate-pulse rounded-lg bg-slate-100" /> : report ? <TrendChart category={activeTab} trends={report.monthly_trends} feedbackSection={filters.feedback_section} /> : null}
          </section>

          <section ref={recordsRef} className="scroll-mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-end justify-between gap-2">
              <div>
                <h2 className="text-base font-semibold">Feedback records</h2>
                <p className="mt-1 text-xs text-slate-500">Review details and the latest moderation action.</p>
              </div>
              <p className="text-sm font-medium tabular-nums text-slate-600">{report?.pagination?.total ?? 0} matching submissions</p>
            </div>
            {!report && loading ? (
              <div className="mt-4 space-y-3" aria-label="Loading feedback records">
                {[1, 2, 3].map((item) => <div key={item} className="h-20 animate-pulse rounded-lg bg-slate-100" />)}
              </div>
            ) : !report ? (
              <p className="mt-4 rounded-lg bg-slate-50 p-5 text-center text-sm text-slate-500">Feedback records could not be loaded.</p>
            ) : report.feedback.length === 0 ? (
              <p className="mt-4 rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-600">No feedback matches the selected filters. Try adjusting or resetting the filters.</p>
            ) : (
              <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-sm">
                  <thead>
                    {activeTab === 'gym_system' ? (
                      <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500"><th className="py-3 pr-4">Member</th><th className="py-3 pr-4">Submitted</th><th className="py-3 pr-4">Overall rating</th><th className="py-3 pr-4">Categories answered</th><th className="py-3 pr-4">Status</th><th className="py-3 text-right">Details</th></tr>
                    ) : (
                      <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500"><th className="py-3 pr-4">Trainer</th><th className="py-3 pr-4">Booking</th><th className="py-3 pr-4">Booking date</th><th className="py-3 pr-4">Rating</th><th className="py-3 pr-4">Submitted</th><th className="py-3 pr-4">Status</th><th className="py-3 text-right">Details</th></tr>
                    )}
                  </thead>
                  <tbody>
                    {report.feedback.map((entry) => {
                      const answeredCount = GYM_CATEGORIES.filter((item) => ratingValue(entry[`${item.key}_rating`]) !== null || entry[`${item.key}_comments`]).length
                      return activeTab === 'gym_system' ? (
                        <tr key={entry.id} className="border-b border-slate-100 last:border-0">
                          <td className="py-3 pr-4 font-medium text-slate-800">{entry.member_name || `Member #${entry.member_id}`}</td>
                          <td className="py-3 pr-4 text-slate-600">{formatDate(entry.submitted_at, { dateStyle: 'medium', timeStyle: 'short' })}</td>
                          <td className="py-3 pr-4"><RatingStars value={entry.overall_rating} compact /></td>
                          <td className="py-3 pr-4 text-slate-600">{answeredCount} of 4</td>
                          <td className="py-3 pr-4"><StatusBadge hidden={Number(entry.is_hidden) === 1} /></td>
                          <td className="py-3 text-right"><button type="button" onClick={() => { setModerationError(''); setSelectedEntry(entry) }} className="rounded-md px-2.5 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-500">View details</button></td>
                        </tr>
                      ) : (
                        <tr key={entry.id} className="border-b border-slate-100 last:border-0">
                          <td className="py-3 pr-4 font-medium text-slate-800">{entry.trainer_name || 'Trainer'}</td>
                          <td className="py-3 pr-4 text-slate-600">{entry.booking_code || `#${entry.booking_id}`}</td>
                          <td className="py-3 pr-4 text-slate-600">{formatDate(entry.attendance_date)}</td>
                          <td className="py-3 pr-4"><RatingStars value={entry.rating} compact /></td>
                          <td className="py-3 pr-4 text-slate-600">{formatDate(entry.created_at, { dateStyle: 'medium', timeStyle: 'short' })}</td>
                          <td className="py-3 pr-4"><StatusBadge hidden={Number(entry.is_hidden) === 1} /></td>
                          <td className="py-3 text-right"><button type="button" onClick={() => { setModerationError(''); setSelectedEntry(entry) }} className="rounded-md px-2.5 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-50 focus:outline-none focus:ring-2 focus:ring-violet-500">View details</button></td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
            {report?.pagination?.pages > 1 && (
              <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                <button type="button" onClick={() => changePage(Math.max(1, page - 1))} disabled={page <= 1 || loading} className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"><FaChevronLeft aria-hidden="true" /> Previous</button>
                <span className="text-sm text-slate-500">Page {page} of {report.pagination.pages}</span>
                <button type="button" onClick={() => changePage(Math.min(report.pagination.pages, page + 1))} disabled={page >= report.pagination.pages || loading} className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">Next <FaChevronRight aria-hidden="true" /></button>
              </div>
            )}
          </section>
        </div>
      </main>

      {selectedEntry && <RecordDetails entry={selectedEntry} onClose={() => { setSelectedEntry(null); setModerationError('') }} onHide={hideFeedback} busy={moderatingId === selectedEntry.id} error={moderationError} />}
      {selectedTrainer && (
        <TrainerFeedbackDialog
          key={selectedTrainer.trainer_id}
          trainer={selectedTrainer}
          report={trainerDetail}
          loading={trainerDetailLoading}
          error={trainerDetailError}
          page={trainerDetailPage}
          onClose={closeTrainerFeedback}
          onRetry={() => setTrainerDetailRetry((value) => value + 1)}
          onPageChange={(nextPage) => setTrainerDetailPage(nextPage)}
        />
      )}
    </div>
  )
}

export default FeedbackManagement
