import { useEffect, useState } from 'react'
import { FaCalendarCheck, FaCheckDouble, FaClock, FaTimes } from 'react-icons/fa'
import StatusBadge from '../ui/StatusBadge.jsx'
import { formatTime12Hour } from '../../utils/timeFormat'

function formatBookingDate(value) {
  if (!value) return '—'
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`)
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric' })
}

export default function TrainerBookingCard({
  booking,
  processing = false,
  compact = false,
  onAccept,
  onReject,
  onPropose,
  onCancel,
  onNoShow,
  onComplete,
}) {
  const [currentTime, setCurrentTime] = useState(0)
  useEffect(() => {
    const updateTime = () => setCurrentTime(Date.now())
    updateTime()
    const interval = window.setInterval(updateTime, 60_000)
    return () => window.clearInterval(interval)
  }, [])
  const isPending = booking.status === 'pending'
  const isConfirmed = booking.status === 'confirmed'
  const sessionEnd = new Date(`${String(booking.booking_date || '').slice(0, 10)}T${String(booking.end_time || '').slice(0, 5)}:00+08:00`).getTime()
  const sessionEnded = currentTime > 0 && sessionEnd > 0 && sessionEnd <= currentTime
  const cardTone = isPending
    ? 'border-amber-200 bg-amber-50/40'
    : isConfirmed
      ? 'border-cyan-200 bg-cyan-50/30'
      : 'border-slate-200 bg-white'

  return (
    <article className={`min-w-0 rounded-2xl border p-3 sm:p-4 ${cardTone}`}>
      <div className="flex min-w-0 flex-col gap-3">
        <div className="flex min-w-0 flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="break-words text-sm font-semibold text-slate-900">{booking.member_name || 'Member'}</p>
            <p className="mt-0.5 break-all font-mono text-[11px] text-slate-500">{booking.member_code || 'Member ID unavailable'}</p>
          </div>
          <StatusBadge status={booking.status} className="shrink-0" />
        </div>

        <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-600">
          <span className="inline-flex items-center gap-1.5"><FaCalendarCheck aria-hidden="true" className="shrink-0 text-cyan-700" />{formatBookingDate(booking.booking_date)}</span>
          <span className="inline-flex items-center gap-1.5"><FaClock aria-hidden="true" className="shrink-0 text-cyan-700" />{formatTime12Hour(booking.start_time)}–{formatTime12Hour(booking.end_time)}</span>
          <span className="font-medium text-slate-700">{booking.duration_minutes} min</span>
        </div>

        <div className="flex min-w-0 flex-wrap gap-1.5">
          {booking.session_items?.length ? booking.session_items.map((session, index) => (
            <span key={`${booking.id}-${session.session_type_id ?? index}`} className="max-w-full break-words rounded-lg bg-white/80 px-2 py-1 text-[11px] text-slate-700 ring-1 ring-slate-200">
              {session.session_name} · {session.duration_minutes} min{session.unit_price !== null && session.unit_price !== undefined ? ` · ${session.currency || 'PHP'} ${Number(session.unit_price).toFixed(2)}` : ''}
            </span>
          )) : (
            <span className="max-w-full break-words rounded-lg bg-white/80 px-2 py-1 text-[11px] text-slate-700 ring-1 ring-slate-200">
              {booking.session_type || 'Session'} · {booking.duration_minutes} min
            </span>
          )}
        </div>

        {booking.subtotal !== null && booking.subtotal !== undefined && (
          <div className="rounded-lg bg-white/70 px-2.5 py-2 text-xs text-slate-700">
            <p>Subtotal: {booking.price_currency || 'PHP'} {Number(booking.subtotal).toFixed(2)}</p>
            {Number(booking.credit_amount) > 0 && <p className="font-medium text-emerald-700">Free Personal Training credit {booking.credit_event_type === 'reserved' ? 'reserved' : booking.credit_event_type === 'released' ? 'released' : booking.credit_event_type === 'expired' ? 'expired' : booking.credit_event_type === 'consumed' ? 'applied' : 'not applied'}: {booking.price_currency || 'PHP'} {Number(booking.credit_amount).toFixed(2)}</p>}
            <p className="font-semibold">Due: {booking.price_currency || 'PHP'} {Number(booking.amount_due).toFixed(2)} · {Number(booking.amount_due) > 0 ? 'Payment required after approval' : 'Credit covered'}</p>
          </div>
        )}

        {!compact && booking.notes && <p className="break-words rounded-lg bg-white/70 px-2.5 py-2 text-xs leading-5 text-slate-600"><span className="font-medium text-slate-700">Note: </span>{booking.notes}</p>}
        {isPending && booking.response_deadline && <p className="text-xs font-medium text-amber-800">Staff response deadline: {String(booking.response_deadline).replace('T', ' ').slice(0, 16)}</p>}
        {booking.confirmation_issue && <p className="break-words rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-xs text-rose-700">{booking.confirmation_issue}</p>}
        {!booking.within_normal_shift && isPending && <p className="text-xs font-medium text-amber-800">Outside normal shift</p>}
        {booking.has_pending_conflict && <p className="break-words text-xs leading-5 text-amber-800">Another request overlaps this time; pending requests do not reserve the slot.</p>}

        {(isPending || isConfirmed) && onAccept && (
          <div className="grid grid-cols-2 gap-2 border-t border-slate-200/80 pt-2.5 sm:flex sm:flex-wrap">
            {isPending && (
              <>
                <button type="button" onClick={() => onAccept(booking)} disabled={Boolean(booking.confirmation_issue) || processing} className="min-h-11 rounded-xl bg-cyan-800 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-900 disabled:cursor-not-allowed disabled:opacity-50">Accept</button>
                <button type="button" onClick={() => onReject(booking)} disabled={processing} className="min-h-11 rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">Reject</button>
                <button type="button" onClick={() => onPropose(booking)} disabled={processing} className="col-span-2 min-h-11 rounded-xl border border-cyan-200 bg-white px-3 py-2 text-sm font-semibold text-cyan-900 hover:bg-cyan-50 disabled:opacity-50 sm:col-span-1">Propose time</button>
              </>
            )}
            {isConfirmed && (
              <>
                <button type="button" onClick={() => onComplete(booking)} disabled={processing} className="min-h-11 rounded-xl bg-cyan-800 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-900 disabled:opacity-50"><FaCheckDouble aria-hidden="true" className="mr-1 inline" />Complete</button>
                <button type="button" onClick={() => onCancel(booking)} disabled={processing} className="min-h-11 rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50"><FaTimes aria-hidden="true" className="mr-1 inline" />Cancel</button>
                {sessionEnded && onNoShow && <button type="button" onClick={() => onNoShow(booking)} disabled={processing} className="col-span-2 min-h-11 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50 sm:col-span-1">Mark no-show</button>}
              </>
            )}
          </div>
        )}
      </div>
    </article>
  )
}
