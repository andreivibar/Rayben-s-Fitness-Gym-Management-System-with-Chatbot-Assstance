import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  FaBars,
  FaCalendarAlt,
  FaCalendarCheck,
  FaCreditCard,
  FaDumbbell,
  FaComments,
  FaHome,
  FaSignOutAlt,
  FaTimes,
  FaUser,
  FaChartLine,
  FaRobot,
} from 'react-icons/fa'
import LogoutConfirmationModal from './ui/LogoutConfirmationModal.jsx'
import { logout } from '../utils/auth'

const navigationSections = [
  {
    label: 'OVERVIEW',
    links: [{ label: 'Dashboard', to: '/member/dashboard', icon: FaHome }],
  },
  {
    label: 'MY GYM',
    links: [
      { label: 'My Profile', to: '/member/profile', icon: FaUser },
      { label: 'Membership', to: '/member/membership', icon: FaCreditCard },
      { label: 'Attendance', to: '/member/attendance', icon: FaCalendarAlt },
      { label: 'Bookings', to: '/member/bookings', icon: FaCalendarCheck },
      { label: 'Payments', to: '/member/payments', icon: FaCreditCard },
      { label: 'Feedback', to: '/member/feedback', icon: FaComments },
    ],
  },
  {
    label: 'FITNESS',
    links: [
      { label: 'Workout Plan', to: '/member/workout-plan', icon: FaDumbbell },
      { label: 'My Progress', to: '/member/progress', icon: FaChartLine },
      { label: 'Gym Assistant', to: '/member/chatbot', icon: FaRobot },
    ],
  },
]

function Navigation({ onNavigate, onLogout }) {
  return (
    <nav aria-label="Member navigation" className="space-y-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      {navigationSections.map((section) => (
        <section key={section.label}>
          <h2 className="px-3 text-[10px] font-bold tracking-[0.18em] text-slate-400">{section.label}</h2>
          <ul className="mt-2 space-y-1">
            {section.links.map(({ label, to, icon: Icon }) => (
              <li key={to}>
                <NavLink
                  to={to}
                  end={to === '/member/dashboard'}
                  onClick={onNavigate}
                  className={({ isActive }) => `flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition ${
                    isActive ? 'bg-cyan-50 text-cyan-800' : 'text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <Icon aria-hidden="true" className="shrink-0 text-base" />
                  {label}
                </NavLink>
              </li>
            ))}
          </ul>
        </section>
      ))}
      <section>
        <h2 className="px-3 text-[10px] font-bold tracking-[0.18em] text-slate-400">ACCOUNT</h2>
        <button
          type="button"
          onClick={onLogout}
          className="mt-2 flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50"
        >
          <FaSignOutAlt aria-hidden="true" className="shrink-0 text-base" />
          Logout
        </button>
      </section>
    </nav>
  )
}

export default function MemberPortalLayout() {
  const navigate = useNavigate()
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false)

  const confirmLogout = async () => {
    await logout(navigate)
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <span className="font-semibold">Rayben's Fitness</span>
        <button
          type="button"
          onClick={() => setSidebarOpen(true)}
          aria-label="Open member navigation"
          aria-expanded={sidebarOpen}
          aria-controls="member-mobile-navigation"
          className="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200"
        >
          <FaBars aria-hidden="true" />
        </button>
      </header>

      {sidebarOpen && (
        <button
          type="button"
          aria-label="Close member navigation"
          className="fixed inset-0 z-40 bg-slate-950/40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <aside id="member-mobile-navigation" aria-hidden={!sidebarOpen} inert={!sidebarOpen} className={`fixed inset-y-0 left-0 z-50 w-72 overflow-y-auto bg-slate-100 p-4 transition-transform lg:hidden ${
        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
      }`}>
        <div className="mb-3 flex items-center justify-between px-1">
          <span className="font-semibold">Member portal</span>
          <button
            type="button"
            onClick={() => setSidebarOpen(false)}
            aria-label="Close member navigation"
            className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white"
          >
            <FaTimes aria-hidden="true" />
          </button>
        </div>
        <Navigation onNavigate={() => setSidebarOpen(false)} onLogout={() => setLogoutConfirmOpen(true)} />
      </aside>

      <div className="mx-auto flex max-w-[1440px] gap-6 px-3 py-4 sm:px-5 lg:px-6 lg:py-6">
        <aside className="hidden w-60 shrink-0 lg:block xl:w-64">
          <div className="sticky top-6">
            <Navigation onLogout={() => setLogoutConfirmOpen(true)} />
          </div>
        </aside>
        <div className="min-w-0 flex-1">
          <Outlet />
        </div>
      </div>

      <LogoutConfirmationModal
        open={logoutConfirmOpen}
        onConfirm={confirmLogout}
        onCancel={() => setLogoutConfirmOpen(false)}
      />
    </div>
  )
}
