import MemberAttendanceHistory from '../components/MemberAttendanceHistory.jsx'
import MemberAttendanceScan from './MemberAttendanceScan.jsx'

export default function MemberAttendancePage() {
  return (
    <div className="space-y-5">
      <header>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">My Gym</p>
        <h1 className="mt-1 text-2xl font-semibold text-slate-900">Attendance</h1>
      </header>
      <MemberAttendanceScan />
      <MemberAttendanceHistory />
    </div>
  )
}
