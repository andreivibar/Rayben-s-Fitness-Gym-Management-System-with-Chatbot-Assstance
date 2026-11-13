import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FaArrowLeft,
  FaCalendarAlt,
  FaCheckCircle,
  FaExclamationCircle,
  FaEye,
  FaEyeSlash,
  FaLock,
  FaSpinner,
  FaUserShield,
} from 'react-icons/fa'
import api from '../services/api'
import ProfilePictureEditor from '../components/ProfilePictureEditor.jsx'
import { clearAuthState, getStoredUser, persistStoredUser } from '../utils/authState'

const emptyProfile = {
  first_name: '',
  middle_name: '',
  last_name: '',
  email: '',
}

function formatDate(value) {
  if (!value) return 'Not available'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Not available'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'long' }).format(date)
}

export default function AdminProfile() {
  const navigate = useNavigate()
  const [profile, setProfile] = useState(null)
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [form, setForm] = useState(emptyProfile)
  const [savingProfile, setSavingProfile] = useState(false)
  const [profileError, setProfileError] = useState('')
  const [profileMessage, setProfileMessage] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    new_password: '',
    confirm_password: '',
  })
  const [savingPassword, setSavingPassword] = useState(false)
  const [passwordError, setPasswordError] = useState('')
  const [visiblePasswords, setVisiblePasswords] = useState({})

  useEffect(() => {
    let active = true
    const loadProfile = async () => {
      try {
        const response = await api.get('/admin_profile.php')
        if (!response.data?.success || !response.data?.admin) {
          throw new Error(response.data?.message || 'Unable to load your Admin profile.')
        }
        if (active) {
          setProfile(response.data.admin)
          setForm({
            first_name: response.data.admin.first_name || '',
            middle_name: response.data.admin.middle_name || '',
            last_name: response.data.admin.last_name || '',
            email: response.data.admin.email || '',
          })
        }
      } catch (error) {
        if (active) setPageError(error?.response?.data?.message || error?.message || 'Unable to load your Admin profile.')
      } finally {
        if (active) setLoading(false)
      }
    }
    loadProfile()
    return () => { active = false }
  }, [])

  const updateForm = (event) => {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
  }

  const saveProfile = async (event) => {
    event.preventDefault()
    setProfileError('')
    setProfileMessage('')
    const normalized = {
      first_name: form.first_name.trim(),
      middle_name: form.middle_name.trim(),
      last_name: form.last_name.trim(),
      email: form.email.trim(),
    }
    const errors = {}
    if (!normalized.first_name || normalized.first_name.length > 50) {
      errors.first_name = 'Enter a first name up to 50 characters.'
    }
    if (normalized.middle_name.length > 50) {
      errors.middle_name = 'Middle name must be 50 characters or fewer.'
    }
    if (!normalized.last_name || normalized.last_name.length > 50) {
      errors.last_name = 'Enter a last name up to 50 characters.'
    }
    if (normalized.email.length > 100 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized.email)) {
      errors.email = 'Enter a valid email address up to 100 characters.'
    }
    setFieldErrors(errors)
    if (Object.keys(errors).length) {
      setProfileError('Please correct the highlighted fields.')
      return
    }

    setSavingProfile(true)
    try {
      const response = await api.put('/admin_profile.php', normalized)
      if (!response.data?.success || !response.data?.admin) {
        throw new Error(response.data?.message || 'Unable to update your profile.')
      }
      const updated = response.data.admin
      setProfile(updated)
      setForm({
        first_name: updated.first_name || '',
        middle_name: updated.middle_name || '',
        last_name: updated.last_name || '',
        email: updated.email || '',
      })
      const storedUser = getStoredUser()
      if (storedUser) {
        persistStoredUser({
          ...storedUser,
          first_name: updated.first_name,
          middle_name: updated.middle_name,
          last_name: updated.last_name,
          full_name: updated.full_name,
          email: updated.email,
        })
      }
      setProfileMessage('Your profile has been updated.')
    } catch (error) {
      setProfileError(error?.response?.data?.message || error?.message || 'Unable to update your profile.')
    } finally {
      setSavingProfile(false)
    }
  }

  const changePassword = async (event) => {
    event.preventDefault()
    setPasswordError('')
    const newPasswordBytes = new TextEncoder().encode(passwordForm.new_password).length
    if (!passwordForm.current_password) {
      setPasswordError('Enter your current password.')
      return
    }
    if (newPasswordBytes < 8 || newPasswordBytes > 72) {
      setPasswordError('New password must be between 8 and 72 bytes.')
      return
    }
    if (passwordForm.new_password !== passwordForm.new_password.trim()) {
      setPasswordError('New password cannot start or end with whitespace.')
      return
    }
    if (passwordForm.new_password !== passwordForm.confirm_password) {
      setPasswordError('New password and confirmation do not match.')
      return
    }

    setSavingPassword(true)
    try {
      const response = await api.post('/admin_change_password.php', passwordForm)
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to change your password.')
      }
      clearAuthState()
      navigate('/login', {
        replace: true,
        state: { message: 'Your password was changed. Please sign in with your new password.' },
      })
    } catch (error) {
      setPasswordError(error?.response?.data?.message || error?.message || 'Unable to change your password.')
      setSavingPassword(false)
    }
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 text-slate-600">
        <FaSpinner className="mr-3 animate-spin text-xl text-cyan-700" />
        Loading your profile…
      </div>
    )
  }

  if (pageError || !profile) {
    return (
      <main className="min-h-screen bg-slate-50 px-4 py-10">
        <div className="mx-auto max-w-3xl rounded-2xl border border-rose-200 bg-white p-6 text-rose-700 shadow-sm">
          <div className="flex items-start gap-3">
            <FaExclamationCircle className="mt-1 shrink-0" />
            <div>
              <h1 className="font-semibold">Unable to open Admin Profile</h1>
              <p className="mt-1 text-sm">{pageError || 'Profile not found.'}</p>
              <button type="button" onClick={() => navigate('/dashboard')} className="mt-4 rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900">
                Return to dashboard
              </button>
            </div>
          </div>
        </div>
      </main>
    )
  }

  const fullName = [profile.first_name, profile.middle_name, profile.last_name].filter(Boolean).join(' ')

  return (
    <main className="min-h-screen bg-slate-50 px-4 py-6 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-6xl">
        <button type="button" onClick={() => navigate('/dashboard')} className="mb-5 inline-flex items-center gap-2 text-sm font-semibold text-cyan-800 transition hover:text-cyan-950">
          <FaArrowLeft /> Back to Dashboard
        </button>

        <header className="mb-6">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">Account Settings</p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-[1.75rem]">Admin Profile</h1>
          <p className="mt-2 max-w-2xl text-sm text-slate-600">Manage your personal information, profile picture, and account password.</p>
        </header>

        <section className="mb-6 grid gap-5 lg:grid-cols-[minmax(250px,0.85fr)_minmax(0,1.4fr)]">
          <div className="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-8">
            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-cyan-50 text-2xl text-cyan-800">
              <FaUserShield />
            </div>
            <h2 className="mb-5 text-sm font-semibold text-slate-800">Profile Photo</h2>
              <ProfilePictureEditor
                entity="admin"
                accent="cyan"
                removeLabel="Remove Photo"
                large
                profilePicture={profile.profile_picture}
                name={fullName}
                code={`ADMIN-${profile.id}`}
                onUpdated={(profilePicture) => setProfile((current) => ({ ...current, profile_picture: profilePicture }))}
              />
            <p className="mt-4 max-w-xs text-xs leading-5 text-slate-500">Choose a clear profile photo. JPG, PNG, or WEBP up to 5 MB.</p>
            <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
              <span className="text-base font-semibold text-slate-900">{fullName}</span>
              <span className="rounded-full border border-cyan-200 bg-cyan-50 px-2.5 py-1 text-xs font-semibold capitalize text-cyan-800">{profile.role}</span>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
              <div className="mb-5">
                <h2 className="text-lg font-semibold text-slate-900">Personal information</h2>
                <p className="mt-1 text-sm text-slate-500">Update the name and email address associated with your account.</p>
              </div>
              {profileMessage && (
                <p role="status" className="mb-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                  <FaCheckCircle /> {profileMessage}
                </p>
              )}
              {profileError && (
                <p role="alert" className="mb-4 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                  <FaExclamationCircle /> {profileError}
                </p>
              )}
              <form onSubmit={saveProfile} noValidate className="grid gap-4 sm:grid-cols-2">
                {[
                  ['first_name', 'First name', true],
                  ['middle_name', 'Middle name', false],
                  ['last_name', 'Last name', true],
                  ['email', 'Email address', true],
                ].map(([name, label, required]) => (
                  <label key={name} className={name === 'email' ? 'sm:col-span-2' : ''}>
                    <span className="mb-1.5 block text-sm font-medium text-slate-700">{label}</span>
                    <input
                      name={name}
                      type={name === 'email' ? 'email' : 'text'}
                      value={form[name]}
                      onChange={updateForm}
                      required={required}
                      maxLength={name === 'email' ? 100 : 50}
                      autoComplete={{ first_name: 'given-name', middle_name: 'additional-name', last_name: 'family-name', email: 'email' }[name]}
                      aria-invalid={Boolean(fieldErrors[name])}
                      aria-describedby={fieldErrors[name] ? `${name}-error` : undefined}
                      className={`h-11 w-full rounded-xl border bg-white px-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-100 ${fieldErrors[name] ? 'border-rose-400' : 'border-slate-300'}`}
                    />
                    {fieldErrors[name] && <span id={`${name}-error`} className="mt-1 block text-xs text-rose-700">{fieldErrors[name]}</span>}
                  </label>
                ))}
                <div className="sm:col-span-2">
                  <button type="submit" disabled={savingProfile} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-cyan-800 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-900 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto">
                    {savingProfile && <FaSpinner className="animate-spin" />}
                    {savingProfile ? 'Saving…' : 'Save Changes'}
                  </button>
                </div>
              </form>
          </div>
        </section>

        <div className="grid gap-6 lg:grid-cols-2">
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div className="mb-5 flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-50 text-cyan-800"><FaUserShield /></span>
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Account information</h2>
                <p className="text-sm text-slate-500">Details associated with your Admin account.</p>
              </div>
            </div>
            <dl className="divide-y divide-slate-100">
              <div className="flex flex-wrap justify-between gap-2 py-3">
                <dt className="text-sm text-slate-500">Account ID</dt>
                <dd className="text-sm font-semibold text-slate-900">{profile.id}</dd>
              </div>
              <div className="flex flex-wrap justify-between gap-2 py-3">
                <dt className="text-sm text-slate-500">Role</dt>
                <dd className="text-sm font-semibold capitalize text-slate-900">{profile.role}</dd>
              </div>
              <div className="flex flex-wrap justify-between gap-2 py-3">
                <dt className="inline-flex items-center gap-2 text-sm text-slate-500"><FaCalendarAlt /> Account created</dt>
                <dd className="text-right text-sm font-semibold text-slate-900">{formatDate(profile.created_at)}</dd>
              </div>
            </dl>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div className="mb-5 flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-50 text-cyan-800"><FaLock /></span>
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Change password</h2>
                <p className="text-sm text-slate-500">You will need to sign in again after changing it.</p>
              </div>
            </div>
            {passwordError && (
              <p role="alert" className="mb-4 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                <FaExclamationCircle /> {passwordError}
              </p>
            )}
            <form onSubmit={changePassword} className="space-y-4">
              {[
                ['current_password', 'Current password', 'current-password'],
                ['new_password', 'New password', 'new-password'],
                ['confirm_password', 'Confirm new password', 'new-password'],
              ].map(([name, label, autoComplete]) => (
                <label key={name} className="block">
                  <span className="mb-1.5 block text-sm font-medium text-slate-700">{label}</span>
                  <span className="relative block">
                    <input
                      type={visiblePasswords[name] ? 'text' : 'password'}
                      name={name}
                      value={passwordForm[name]}
                      onChange={(event) => setPasswordForm((current) => ({ ...current, [name]: event.target.value }))}
                      autoComplete={autoComplete}
                      required
                      className="h-11 w-full rounded-xl border border-slate-300 bg-white px-3.5 pr-11 text-sm text-slate-900 outline-none transition focus:border-cyan-600 focus:ring-2 focus:ring-cyan-100"
                    />
                    <button
                      type="button"
                      onClick={() => setVisiblePasswords((current) => ({ ...current, [name]: !current[name] }))}
                      aria-label={`${visiblePasswords[name] ? 'Hide' : 'Show'} ${label.toLowerCase()}`}
                      className="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center text-slate-500 hover:text-cyan-800"
                    >
                      {visiblePasswords[name] ? <FaEyeSlash /> : <FaEye />}
                    </button>
                  </span>
                </label>
              ))}
              <button type="submit" disabled={savingPassword} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-cyan-800 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-900 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto">
                {savingPassword && <FaSpinner className="animate-spin" />}
                {savingPassword ? 'Updating…' : 'Update Password'}
              </button>
            </form>
          </section>
        </div>
      </div>
    </main>
  )
}
