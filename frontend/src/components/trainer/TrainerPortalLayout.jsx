import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { FaCalendarAlt, FaClipboardCheck, FaHome, FaSignOutAlt, FaUser } from 'react-icons/fa'
import ProfilePictureEditor from '../ProfilePictureEditor.jsx'
import LogoutConfirmationModal from '../ui/LogoutConfirmationModal.jsx'
import { formatFullName } from '../../utils/nameFormatter'
import { logout } from '../../utils/auth'
import { useTrainerPortal } from './TrainerPortalContext.js'
import { useState } from 'react'

const navigation = [
  { to: '/trainer', end: true, label: 'Dashboard', icon: FaHome },
  { to: '/trainer/schedule', label: 'Schedule', icon: FaCalendarAlt },
  { to: '/trainer/attendance', label: 'Attendance', icon: FaClipboardCheck },
]

function NavigationLinks({ mobile = false }) {
  return navigation.map(({ to, end, label, icon: Icon }) => (
    <NavLink
      key={to}
      to={to}
      end={end}
      className={({ isActive }) => mobile
        ? `flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl text-[11px] font-semibold ${isActive ? 'bg-cyan-50 text-cyan-900' : 'text-slate-600'}`
        : `flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold ${isActive ? 'bg-cyan-50 text-cyan-900' : 'text-slate-700 hover:bg-slate-50'}`}
    >
      <Icon aria-hidden="true" className={mobile ? 'text-base' : 'shrink-0'} />
      {label}
    </NavLink>
  ))
}

export default function TrainerPortalLayout({ children }) {
  const { trainer, loading, error } = useTrainerPortal()
  const navigate = useNavigate()
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false)

  const handleLogout = async () => logout(navigate)
  const profileName = trainer ? formatFullName(trainer) : 'Trainer profile'

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 lg:h-screen lg:overflow-hidden">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-200 bg-white p-4 lg:flex">
        <div className="mb-4 border-b border-slate-100 pb-4">
          <Link to="/trainer/profile" className="flex items-center gap-3 rounded-xl p-2 hover:bg-slate-50">
            <ProfilePictureEditor entity="trainer" profilePicture={trainer?.profile_picture} name={profileName} code={trainer?.trainer_code} compact displayOnly />
            <span className="min-w-0">
              <span className="block break-words text-sm font-semibold text-slate-900">{loading ? 'Loading profile…' : profileName}</span>
              <span className="mt-1 block break-all font-mono text-xs text-slate-500">{trainer?.trainer_code || 'Trainer ID'}</span>
            </span>
          </Link>
          <NavLink to="/trainer/profile" className={({ isActive }) => `mt-2 flex min-h-10 items-center gap-2 rounded-xl px-3 text-sm font-semibold ${isActive ? 'bg-cyan-50 text-cyan-900' : 'text-slate-700 hover:bg-slate-50'}`}>
            <FaUser aria-hidden="true" /> My Profile
          </NavLink>
        </div>

        <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Workspace</p>
        <nav aria-label="Trainer navigation" className="space-y-1">
          <NavigationLinks />
        </nav>
        <div className="mt-auto border-t border-slate-100 pt-3">
          <button type="button" onClick={() => setLogoutConfirmOpen(true)} className="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">
            <FaSignOutAlt aria-hidden="true" /> Log out
          </button>
        </div>
      </aside>

      <div className="hidden border-b border-slate-200 bg-white px-3 py-2 sm:block lg:hidden">
        <nav aria-label="Trainer navigation" className="mx-auto grid max-w-2xl grid-cols-3 gap-1">
          <NavigationLinks />
        </nav>
        <NavLink to="/trainer/profile" className={({ isActive }) => `mx-auto mt-1 flex min-h-10 max-w-2xl items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold ${isActive ? 'bg-cyan-50 text-cyan-900' : 'text-slate-700 hover:bg-slate-50'}`}>
          <FaUser aria-hidden="true" /> My Profile
        </NavLink>
      </div>

      <div className="min-w-0 lg:ml-64 lg:h-screen lg:overflow-y-auto lg:overscroll-contain">
        {error && !trainer ? (
          <div className="mx-auto max-w-xl p-6">
            <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>
          </div>
        ) : (
          children || <Outlet />
        )}
      </div>

      <nav aria-label="Mobile trainer navigation" className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-slate-200 bg-white/95 px-1 pb-[max(env(safe-area-inset-bottom),0.5rem)] pt-2 sm:hidden">
        <NavigationLinks mobile />
        <NavLink to="/trainer/profile" className={({ isActive }) => `flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl text-[11px] font-semibold ${isActive ? 'bg-cyan-50 text-cyan-900' : 'text-slate-600'}`}>
          <FaUser aria-hidden="true" className="text-base" />Profile
        </NavLink>
        <button type="button" onClick={() => setLogoutConfirmOpen(true)} className="flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl text-[11px] font-semibold text-rose-700">
          <FaSignOutAlt aria-hidden="true" className="text-base" />Log out
        </button>
      </nav>

      <LogoutConfirmationModal open={logoutConfirmOpen} onConfirm={handleLogout} onCancel={() => setLogoutConfirmOpen(false)} />
    </div>
  )
}
