import { formatTime12Hour } from '../utils/timeFormat'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const SHIFT_LABELS = {
  morning: 'Morning Shift',
  afternoon: 'Afternoon Shift',
  night: 'Night Shift',
  custom: 'Custom Schedule',
}

export function ScheduleHours({ slot }) {
  const start = formatTime12Hour(slot.start_time ?? slot.scheduled_start)
  const end = formatTime12Hour(slot.end_time ?? slot.scheduled_end)
  const shift = SHIFT_LABELS[slot.schedule_type]
  if (!slot.lunch_start || !slot.lunch_end) {
    return <span>{shift && <strong>{shift}: </strong>}{start} – {end}</span>
  }

  const lunchStart = formatTime12Hour(slot.lunch_start)
  const lunchEnd = formatTime12Hour(slot.lunch_end)
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      {shift && <strong>{shift}: </strong>}
      <span>{start} – {lunchStart}</span>
      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Lunch break {lunchStart} – {lunchEnd}</span>
      <span>{lunchEnd} – {end}</span>
    </span>
  )
}

export default function TrainerWeeklySchedule({ schedule = [] }) {
  const activeByDay = DAYS.reduce((groups, day) => {
    groups[day] = schedule
      .filter((slot) => slot.day_of_week === day && String(slot.status).toLowerCase() === 'active')
      .sort((first, second) => String(first.start_time ?? first.scheduled_start).localeCompare(String(second.start_time ?? second.scheduled_start)))
    return groups
  }, {})

  const activeSlots = Object.values(activeByDay).flat()
  const shiftTypes = [...new Set(activeSlots.map((slot) => slot.schedule_type).filter((type) => SHIFT_LABELS[type]))]
  const scheduleTitle = shiftTypes.length === 1
    ? SHIFT_LABELS[shiftTypes[0]]
    : shiftTypes.length > 1
      ? 'Mixed Shift Assignments'
      : 'Assigned Weekly Hours'

  return (
    <section aria-labelledby="trainer-weekly-schedule-heading" className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">Weekly shifts</p>
          <h2 id="trainer-weekly-schedule-heading" className="mt-1 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">{scheduleTitle}</h2>
        </div>
        <span className="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600">Read only</span>
      </div>

      {activeSlots.length === 0 ? (
        <p className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">
          You have not been assigned a schedule yet. Please contact an administrator.
        </p>
      ) : (
        <div className="mt-4 grid grid-cols-1 gap-2 min-[360px]:grid-cols-2 sm:grid-cols-3 xl:grid-cols-4">
          {DAYS.map((day) => {
            const slots = activeByDay[day]
            return (
              <article key={day} className={`min-w-0 rounded-xl border p-3 ${slots.length ? 'border-cyan-200 bg-cyan-50/50' : 'border-slate-200 bg-slate-50'}`}>
                <div className="flex items-center justify-between gap-2">
                  <h3 className={`text-sm font-semibold ${slots.length ? 'text-slate-900' : 'text-slate-500'}`}>{day}</h3>
                  <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${slots.length ? 'bg-cyan-600' : 'bg-slate-300'}`} aria-label={slots.length ? 'Working day' : 'Day off'} />
                </div>
                {slots.length === 0 ? (
                  <p className="mt-2 text-sm text-slate-500">Day off</p>
                ) : (
                  slots.map((slot) => (
                    <div key={slot.id} className="mt-2 min-w-0 border-t border-cyan-100 pt-2 text-sm leading-6 text-slate-700">
                      <p className="break-words"><ScheduleHours slot={slot} /></p>
                    </div>
                  ))
                )}
              </article>
            )
          })}
        </div>
      )}
    </section>
  )
}
