import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaCalendarCheck, FaCamera, FaClock, FaUsers } from 'react-icons/fa'
import api from '../services/api'
import TrainerWeeklySchedule from '../components/TrainerWeeklySchedule.jsx'
import TrainerFeedback from '../components/TrainerFeedback.jsx'
import NotificationCenter from '../components/NotificationCenter.jsx'
import TrainerBookingCard from '../components/trainer/TrainerBookingCard.jsx'
import { useTrainerPortal } from '../components/trainer/TrainerPortalContext.js'
import { getManilaDateString } from '../utils/manilaDate'
import { getUpcomingSessions } from '../utils/trainerDashboard.js'
import { useFeedback } from '../components/ui/FeedbackContext.js'

function SectionHeading({ eyebrow, title, action }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div>
        {eyebrow && <p className="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">{eyebrow}</p>}
        <h2 className="mt-1 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">{title}</h2>
      </div>
      {action}
    </div>
  )
}

export default function TrainerDashboard() {
  const { trainer, loading: profileLoading, error: profileError } = useTrainerPortal()
  const { showRequestError } = useFeedback()
  const [bookings, setBookings] = useState([])
  const [loadingBookings, setLoadingBookings] = useState(true)
  const [attendanceSummary, setAttendanceSummary] = useState({ scheduled: 0, present: 0, late: 0, absent: 0 })
  const [activeAttendance, setActiveAttendance] = useState(null)

  useEffect(() => {
    let active = true
    if (profileLoading) {
      return () => { active = false }
    }
    if (!trainer) {
      void Promise.resolve().then(() => {
        if (active) setLoadingBookings(false)
      })
      return () => { active = false }
    }

    const loadBookings = async () => {
      try {
        const response = await api.get('/bookings.php', { params: { role: 'trainer' } })
        if (!response.data?.success) throw new Error('Unable to load assigned bookings.')
        if (active) setBookings(response.data.bookings || [])
      } catch (requestError) {
        if (active) showRequestError(requestError, 'Unable to load your assigned sessions. Try again later.', 'Sessions could not be loaded')
      } finally {
        if (active) setLoadingBookings(false)
      }
    }
    void loadBookings()
    return () => { active = false }
  }, [profileLoading, showRequestError, trainer])

  useEffect(() => {
    let active = true
    api.get('/trainer_attendance.php')
      .then((response) => {
        if (!response.data?.success) throw new Error('Unable to load attendance summary.')
        if (active) {
          setAttendanceSummary({
            ...(response.data.summary || { scheduled: 0, present: 0, late: 0, absent: 0 }),
            scheduled: Array.isArray(response.data.schedule)
              ? response.data.schedule.filter((record) => record.availability_id !== null && record.availability_id !== undefined).length
              : Number(response.data.summary?.scheduled || 0),
          })
          setActiveAttendance(response.data.active_attendance || null)
        }
      })
      .catch(() => {
        if (active) setAttendanceSummary({ scheduled: 0, present: 0, late: 0, absent: 0 })
      })
    return () => { active = false }
  }, [])

  if (profileLoading) {
    return <div className="px-4 py-12 text-center text-sm text-slate-500">Loading your trainer dashboard…</div>
  }
  if (profileError || !trainer) {
    return <div role="alert" className="mx-auto max-w-xl p-5 text-sm text-rose-700">{profileError || 'Trainer profile is unavailable.'}</div>
  }

  const todayDate = getManilaDateString()
  const todaySessionsCount = bookings.filter((booking) => booking.booking_date === todayDate && booking.status !== 'cancelled').length
  const assignedMembersCount = new Set(bookings.map((booking) => booking.member_id)).size
  const upcomingSessions = getUpcomingSessions(bookings, new Date(), 4)

  return (
    <div className="min-h-screen overflow-x-hidden pb-24 sm:pb-8">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <div className="min-w-0">
            <p className="text-xs font-medium text-slate-500">Welcome back</p>
            <h1 className="truncate text-lg font-bold text-slate-900 sm:text-xl">{trainer.full_name}</h1>
          </div>
          <NotificationCenter />
        </div>
      </header>

      <main className="mx-auto max-w-6xl space-y-5 px-4 py-5 sm:px-6 sm:py-6">
        <section aria-labelledby="trainer-overview-heading">
          <div className="mb-3">
            <p className="text-sm text-slate-500">Your training overview</p>
            <h2 id="trainer-overview-heading" className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Dashboard</h2>
          </div>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <article className="rounded-2xl border border-slate-200 bg-white p-4">
              <div className="flex items-center justify-between gap-2"><p className="text-sm font-medium text-slate-600">Assigned members</p><FaUsers aria-hidden="true" className="text-cyan-800" /></div>
              <p className="mt-3 text-3xl font-bold text-slate-900">{loadingBookings ? '—' : assignedMembersCount}</p>
              <p className="mt-1 text-xs text-slate-500">Members with assigned bookings</p>
            </article>
            <article className="rounded-2xl border border-slate-200 bg-white p-4">
              <div className="flex items-center justify-between gap-2"><p className="text-sm font-medium text-slate-600">Today’s sessions</p><FaCalendarCheck aria-hidden="true" className="text-cyan-800" /></div>
              <p className="mt-3 text-3xl font-bold text-slate-900">{loadingBookings ? '—' : todaySessionsCount}</p>
              <p className="mt-1 text-xs text-slate-500">Bookings scheduled for today</p>
            </article>
            <article className="col-span-2 rounded-2xl border border-slate-200 bg-white p-4 lg:col-span-1">
              <div className="flex items-center justify-between gap-2"><p className="text-sm font-medium text-slate-600">Account status</p><span className={`rounded-full px-3 py-1 text-xs font-semibold ${String(trainer.status).toLowerCase() === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>{trainer.status}</span></div>
              <p className="mt-3 text-lg font-bold text-slate-900">Trainer account</p>
              <p className="mt-1 text-xs text-slate-500">Status managed by your administrator</p>
            </article>
          </div>
        </section>

        <section aria-labelledby="upcoming-sessions-heading" className="rounded-2xl border border-cyan-200 bg-white p-4 sm:p-5">
          <SectionHeading
            eyebrow="Next on your schedule"
            title="Upcoming sessions"
            action={<Link to="/trainer/schedule" className="min-h-11 rounded-xl px-3 py-2 text-sm font-semibold text-cyan-900 hover:bg-cyan-50">View all bookings <span aria-hidden="true">→</span></Link>}
          />
          {loadingBookings ? (
            <p role="status" className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Loading upcoming sessions…</p>
          ) : upcomingSessions.length === 0 ? (
            <div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
              <p className="text-sm font-semibold text-slate-700">No upcoming sessions</p>
              <p className="mt-1 text-sm text-slate-500">New assigned sessions will appear here.</p>
            </div>
          ) : (
            <div className="mt-4 grid gap-3 xl:grid-cols-2">
              {upcomingSessions.map((booking) => <TrainerBookingCard key={booking.id} booking={booking} compact />)}
            </div>
          )}
        </section>

        <section aria-label="Weekly shift assignments">
          <TrainerWeeklySchedule schedule={trainer.availability || []} />
        </section>

        <section aria-labelledby="attendance-summary-heading" className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
          <SectionHeading
            eyebrow="Time and attendance"
            title="Today’s attendance"
            action={<Link to="/trainer/attendance" className="min-h-11 rounded-xl px-3 py-2 text-sm font-semibold text-cyan-900 hover:bg-cyan-50">View attendance <span aria-hidden="true">→</span></Link>}
          />
          {activeAttendance && (
            <p className="mt-3 break-words rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
              Checked in at {activeAttendance.time_in}. Remember to check out.
            </p>
          )}
          <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              { label: 'Shifts', value: attendanceSummary.scheduled, color: 'bg-slate-50 text-slate-900' },
              { label: 'Present', value: attendanceSummary.present, color: 'bg-emerald-50 text-emerald-800' },
              { label: 'Late', value: attendanceSummary.late, color: 'bg-amber-50 text-amber-800' },
              { label: 'Absent', value: attendanceSummary.absent, color: 'bg-rose-50 text-rose-800' },
            ].map((item) => <div key={item.label} className={`rounded-xl p-3 ${item.color}`}><p className="text-xs font-medium">{item.label}</p><p className="mt-1 text-xl font-bold">{item.value}</p></div>)}
          </div>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">
            <Link to="/trainer/attendance/scan" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-cyan-800 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-900"><FaCamera aria-hidden="true" />Scan gym QR</Link>
            <Link to="/trainer/attendance" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"><FaClock aria-hidden="true" />Attendance details</Link>
          </div>
          <h3 id="attendance-summary-heading" className="sr-only">Attendance summary</h3>
        </section>

        <section aria-label="Member feedback summary">
          <TrainerFeedback compact />
        </section>
      </main>
    </div>
  )
}
