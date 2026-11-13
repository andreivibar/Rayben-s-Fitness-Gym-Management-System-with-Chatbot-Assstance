import { Link } from 'react-router-dom'
import { FaSignInAlt, FaArrowRight } from 'react-icons/fa'
import RegistrationForm from '../components/RegistrationForm.jsx'

function MemberRegistration() {
  return (
    <div className="min-h-screen bg-slate-100 text-slate-900 flex flex-col selection:bg-violet-100 selection:text-violet-900">
      {/* 1. TOP HEADER */}
      <header className="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-md">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3.5 sm:px-6 lg:px-8">
          {/* Left: Brand Logo & Name */}
          <Link to="/" className="flex items-center gap-3 group">
            <img
              src={`${import.meta.env.BASE_URL}images/gym-logo.png`}
              alt="Gym logo"
              onError={(event) => { event.currentTarget.style.visibility = 'hidden' }}
              className="h-15 w-14 object-contain transition group-hover:scale-105"
            />
            <div>
              <span className="text-base font-bold tracking-tight text-slate-900 block leading-tight">
                Rayben&apos;s Fitness Gym
              </span>
              <span className="text-[11px] font-semibold uppercase tracking-wider text-violet-700 block">
                Member Registration
              </span>
            </div>
          </Link>

          {/* Right: Login Link/Button */}
          <div className="flex items-center gap-3">
            <span className="hidden sm:inline text-xs font-medium text-slate-500">
              Already have an account?
            </span>
            <Link
              to="/login"
              className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-700 shadow-xs transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-800"
            >
              <FaSignInAlt className="text-xs text-slate-400" />
              <span>Log in</span>
            </Link>
          </div>
        </div>
      </header>

      {/* Main Registration Content */}
      <main className="flex-1 py-8 sm:py-12 px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl">
          {/* 2. REGISTRATION HEADER */}
          <div className="text-center mb-8 sm:mb-10">
            <div className="mx-auto mb-4 inline-flex items-center gap-2 rounded-full border border-violet-200 bg-violet-50/80 px-4 py-1 text-xs font-bold uppercase tracking-widest text-violet-700 shadow-xs">
              <span className="h-1.5 w-1.5 rounded-full bg-violet-600"></span>
              Join Our Gym Community
            </div>
            <h1 className="text-2xl sm:text-4xl font-extrabold tracking-tight text-slate-900">
              Create your member account
            </h1>
            <p className="mt-3 text-sm text-slate-600">
              Just visiting today? Choose <span className="font-semibold text-violet-700">1 Day Pass — ₱100</span> above to create your Member account.
            </p>
          </div>

          {/* Form Container */}
          <RegistrationForm />

          {/* 10. BOTTOM LOGIN LINK */}
          <div className="mt-8 rounded-3xl border border-slate-200/80 bg-white p-5 text-center shadow-sm">
            <p className="text-xs sm:text-sm text-slate-600">
              Already have an account?{' '}
              <Link
                to="/login"
                className="font-bold text-violet-700 hover:text-violet-800 transition inline-flex items-center gap-1 ml-1"
              >
                Log in here
                <FaArrowRight className="text-[10px]" />
              </Link>
            </p>
          </div>
        </div>
      </main>

      {/* Minimal Footer */}
      <footer className="border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">
        <div className="mx-auto max-w-7xl px-4">
          <p>© {new Date().getFullYear()} Rayben&apos;s Fitness Gym. All rights reserved.</p>
        </div>
      </footer>
    </div>
  )
}

export default MemberRegistration
