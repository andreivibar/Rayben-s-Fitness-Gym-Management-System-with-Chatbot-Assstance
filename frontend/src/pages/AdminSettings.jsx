import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  LuArrowLeft,
  LuArrowUpRight,
  LuBuilding2,
  LuCalendarCog,
  LuCreditCard,
  LuInfo,
  LuLockKeyhole,
  LuPlus,
  LuSearch,
  LuShieldCheck,
  LuUserPlus,
  LuUsersRound,
  LuX,
} from 'react-icons/lu'
import api from '../services/api'
import BookingSettingsPanel from '../components/BookingSettingsPanel.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import GcashSettingsPanel from '../components/GcashSettingsPanel.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'
import { getStoredUser } from '../utils/authState'

const sections = [
  {
    id: 'accounts',
    label: 'Account Management',
    shortLabel: 'Accounts',
    description: 'Manage Admin access and account lifecycle.',
    icon: LuUsersRound,
    title: 'Account Management',
    detail: 'A central place to manage administrator access for your gym.',
    status: 'Admin access',
    content: '',
  },
  {
    id: 'security',
    label: 'Security',
    shortLabel: 'Security',
    description: 'Keep administrator accounts secure.',
    icon: LuShieldCheck,
    title: 'Security',
    detail: 'Manage your own password and review security-related activity.',
    status: 'Password changes available',
    content: 'Change your password from your Admin Profile. Security activity history will be integrated with Feature #10.',
  },
  {
    id: 'booking',
    label: 'Booking Configuration',
    shortLabel: 'Bookings',
    description: 'Configure booking options and policies.',
    icon: LuCalendarCog,
    title: 'Booking Configuration',
    detail: 'Manage session types, operating hours, and how far in advance members can book.',
    status: 'Booking controls',
    content: '',
  },
  {
    id: 'payments',
    label: 'Payment Methods',
    shortLabel: 'Payments',
    description: 'Review supported payment workflows.',
    icon: LuCreditCard,
    title: 'Payment Methods',
    detail: 'Manage the GCash collection details shown throughout member payment flows.',
    status: 'GCash details',
    content: '',
  },
  {
    id: 'gym',
    label: 'Gym Information',
    shortLabel: 'Gym info',
    description: 'Gym profile details and contact information.',
    icon: LuBuilding2,
    title: 'Gym Information',
    detail: 'Gym information settings are not configured yet.',
    status: 'Details to be defined',
    content: 'Editable gym information will be added after the fields and where they appear in the system are approved.',
  },
]

const EMPTY_ADMIN_FORM = {
  username: '',
  first_name: '',
  middle_name: '',
  last_name: '',
  email: '',
  password: '',
  confirm_password: '',
}

function formatAccountDate(value) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? '—'
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

function AccountStatusBadge({ status }) {
  const active = status === 'active'
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${
      active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'
    }`}>
      <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
      {active ? 'Active' : 'Deactivated'}
    </span>
  )
}

function AdminAccountForm({ onClose, onCreated }) {
  const [form, setForm] = useState(EMPTY_ADMIN_FORM)
  const [fieldErrors, setFieldErrors] = useState({})
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const updateField = (event) => {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setError('')
  }

  const createAccount = async (event) => {
    event.preventDefault()
    const errors = {}
    if (!/^[A-Za-z0-9_]{3,30}$/.test(form.username.trim())) {
      errors.username = 'Use 3–30 letters, numbers, or underscores.'
    }
    if (!form.first_name.trim() || form.first_name.trim().length > 50) {
      errors.first_name = 'Enter a first name up to 50 characters.'
    }
    if (form.middle_name.trim().length > 50) {
      errors.middle_name = 'Middle name must be 50 characters or fewer.'
    }
    if (!form.last_name.trim() || form.last_name.trim().length > 50) {
      errors.last_name = 'Enter a last name up to 50 characters.'
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) || form.email.trim().length > 100) {
      errors.email = 'Enter a valid email address up to 100 characters.'
    }
    if (form.password.length < 8 || form.password.length > 72 || /^\s|\s$/.test(form.password)) {
      errors.password = 'Use 8–72 characters without leading or trailing spaces.'
    }
    if (form.password !== form.confirm_password) {
      errors.confirm_password = 'Passwords do not match.'
    }
    setFieldErrors(errors)
    if (Object.keys(errors).length) return

    setSaving(true)
    setError('')
    try {
      const response = await api.post('/admin_accounts.php', {
        ...form,
        username: form.username.trim(),
        first_name: form.first_name.trim(),
        middle_name: form.middle_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
      })
      if (!response.data?.success || !response.data?.account) {
        throw new Error(response.data?.message || 'Unable to create the Admin account.')
      }
      setForm(EMPTY_ADMIN_FORM)
      onCreated(response.data.account)
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to create the Admin account.')
    } finally {
      setSaving(false)
    }
  }

  const fields = [
    { name: 'username', label: 'Username', autoComplete: 'username', maxLength: 30 },
    { name: 'first_name', label: 'First name', autoComplete: 'given-name', maxLength: 50 },
    { name: 'middle_name', label: 'Middle name (optional)', autoComplete: 'additional-name', maxLength: 50 },
    { name: 'last_name', label: 'Last name', autoComplete: 'family-name', maxLength: 50 },
    { name: 'email', label: 'Email address', type: 'email', autoComplete: 'email', maxLength: 100 },
    { name: 'password', label: 'Initial password', type: 'password', autoComplete: 'new-password', maxLength: 72 },
    { name: 'confirm_password', label: 'Confirm password', type: 'password', autoComplete: 'new-password', maxLength: 72 },
  ]

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-admin-title"
        className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-2xl"
      >
        <div className="flex items-start justify-between border-b border-slate-100 px-5 py-5 sm:px-7">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-800">Account management</p>
            <h3 id="create-admin-title" className="mt-1 text-xl font-semibold text-slate-900">Create Admin account</h3>
            <p className="mt-1 text-sm text-slate-600">The new account will be active and must sign in with its initial password.</p>
          </div>
          <button type="button" onClick={onClose} disabled={saving} aria-label="Close dialog" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-50">
            <LuX aria-hidden="true" size={20} />
          </button>
        </div>
        <form onSubmit={createAccount} className="space-y-5 px-5 py-5 sm:px-7">
          {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
          <div className="grid gap-4 sm:grid-cols-2">
            {fields.map((field) => (
              <label key={field.name} className={`block text-sm font-medium text-slate-700 ${field.name === 'username' || field.name === 'email' ? 'sm:col-span-2' : ''}`}>
                {field.label}
                <input
                  type={field.type || 'text'}
                  name={field.name}
                  value={form[field.name]}
                  onChange={updateField}
                  autoComplete={field.autoComplete}
                  maxLength={field.maxLength}
                  required={field.name !== 'middle_name'}
                  disabled={saving}
                  aria-invalid={Boolean(fieldErrors[field.name])}
                  aria-describedby={fieldErrors[field.name] ? `${field.name}-error` : undefined}
                  className={`mt-1.5 min-h-11 w-full rounded-xl border bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:ring-2 ${
                    fieldErrors[field.name] ? 'border-rose-300 focus:ring-rose-200' : 'border-slate-300 focus:border-cyan-600 focus:ring-cyan-100'
                  }`}
                />
                {fieldErrors[field.name] && <span id={`${field.name}-error`} className="mt-1 block text-xs text-rose-700">{fieldErrors[field.name]}</span>}
              </label>
            ))}
          </div>
          <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
            <button type="button" onClick={onClose} disabled={saving} className="min-h-11 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
              Cancel
            </button>
            <button type="submit" disabled={saving} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-cyan-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-cyan-900 disabled:cursor-wait disabled:opacity-60">
              <LuUserPlus aria-hidden="true" size={17} />
              {saving ? 'Creating account…' : 'Create Admin account'}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}

function AdminAccountManagement() {
  const currentUser = getStoredUser()
  const currentAdminId = Number(currentUser?.id || 0)
  const { showSuccess, showRequestError } = useFeedback()
  const [accounts, setAccounts] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [search, setSearch] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [statusTarget, setStatusTarget] = useState(null)
  const [changingStatus, setChangingStatus] = useState(false)

  const loadAccounts = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const response = await api.get('/admin_accounts.php')
      if (!response.data?.success || !Array.isArray(response.data?.accounts)) {
        throw new Error(response.data?.message || 'Unable to load Admin accounts.')
      }
      setAccounts(response.data.accounts)
    } catch (requestError) {
      setLoadError(requestError?.response?.data?.message || requestError?.message || 'Unable to load Admin accounts.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(() => loadAccounts())
  }, [loadAccounts])

  const filteredAccounts = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return accounts
    return accounts.filter((account) => [
      account.id,
      account.full_name,
      account.username,
      account.email,
      account.account_status,
      account.creator_name,
      account.created_by_admin_id,
    ].some((value) => String(value ?? '').toLowerCase().includes(query)))
  }, [accounts, search])

  const handleCreated = async () => {
    setShowCreate(false)
    showSuccess('Admin account created. Its password will not be shown again.')
    await loadAccounts()
  }

  const updateStatus = async () => {
    if (!statusTarget) return
    setChangingStatus(true)
    try {
      const response = await api.put('/admin_accounts.php', {
        id: statusTarget.id,
        account_status: statusTarget.nextStatus,
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to update the Admin account status.')
      }
      showSuccess(response.data.message || 'Admin account status updated.')
      setStatusTarget(null)
      await loadAccounts()
    } catch (requestError) {
      showRequestError(requestError, 'Unable to update the Admin account status. Refresh and try again.', 'Account status update failed')
    } finally {
      setChangingStatus(false)
    }
  }

  const requestStatusChange = (account) => {
    const nextStatus = account.account_status === 'active' ? 'deactivated' : 'active'
    setStatusTarget({ ...account, nextStatus })
  }

  const statusDescription = statusTarget?.nextStatus === 'deactivated'
    ? `Deactivate ${statusTarget?.full_name || statusTarget?.username}? They will be signed out and must be reactivated by another Admin before signing in again.`
    : `Reactivate ${statusTarget?.full_name || statusTarget?.username}? They will need to sign in again.`

  return (
    <>
      <div className="mt-6 space-y-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <label className="relative block w-full sm:max-w-sm">
            <LuSearch aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={17} />
            <span className="sr-only">Search Admin accounts</span>
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search Admin accounts"
              className="min-h-11 w-full rounded-xl border border-slate-300 bg-white pl-10 pr-3 text-sm outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-100"
            />
          </label>
          <button type="button" onClick={() => setShowCreate(true)} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-900 focus:outline-none focus:ring-2 focus:ring-cyan-600 focus:ring-offset-2">
            <LuPlus aria-hidden="true" size={18} />
            Create Admin Account
          </button>
        </div>

        {loadError && (
          <div role="alert" className="flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 sm:flex-row sm:items-center sm:justify-between">
            <p>{loadError}</p>
            <button type="button" onClick={() => void loadAccounts()} disabled={loading} className="font-semibold underline disabled:opacity-50">Retry</button>
          </div>
        )}

        {loading ? (
          <div role="status" className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-14 text-center text-sm text-slate-500">
            Loading Admin accounts…
          </div>
        ) : !loadError && filteredAccounts.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-12 text-center">
            <LuUsersRound aria-hidden="true" className="mx-auto text-slate-400" size={30} />
            <h3 className="mt-3 font-semibold text-slate-900">{accounts.length ? 'No matching Admin accounts' : 'No Admin accounts found'}</h3>
            <p className="mt-1 text-sm text-slate-600">{accounts.length ? 'Try another search.' : 'Create an Admin account to begin managing administrator access.'}</p>
          </div>
        ) : (
          <>
            <div className="hidden overflow-hidden rounded-2xl border border-slate-200 lg:block">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1080px] divide-y divide-slate-200 text-left text-sm">
                  <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                      <th scope="col" className="px-4 py-3">Admin</th>
                      <th scope="col" className="px-4 py-3">Username</th>
                      <th scope="col" className="px-4 py-3">Email</th>
                      <th scope="col" className="px-4 py-3">Status</th>
                      <th scope="col" className="px-4 py-3">Created</th>
                      <th scope="col" className="px-4 py-3">Creator</th>
                      <th scope="col" className="px-4 py-3">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {filteredAccounts.map((account) => {
                      const isCurrent = Number(account.id) === currentAdminId
                      return (
                        <tr key={account.id} className="align-middle">
                          <td className="px-4 py-4">
                            <span className="font-semibold text-slate-900">{account.full_name || 'Name not set'}</span>
                            <span className="mt-0.5 block text-xs text-slate-500">ID #{account.id}{isCurrent ? ' · You' : ''}</span>
                          </td>
                          <td className="px-4 py-4 text-slate-700">{account.username || '—'}</td>
                          <td className="px-4 py-4 text-slate-700">{account.email || '—'}</td>
                          <td className="px-4 py-4"><AccountStatusBadge status={account.account_status} /></td>
                          <td className="px-4 py-4 whitespace-nowrap text-slate-600">{formatAccountDate(account.created_at)}</td>
                          <td className="px-4 py-4 text-slate-600">{account.creator_name || (account.created_by_admin_id ? `Admin #${account.created_by_admin_id}` : '—')}</td>
                          <td className="px-4 py-4">
                            {isCurrent ? (
                              <span className="text-xs font-medium text-slate-400">Current account</span>
                            ) : (
                              <button type="button" onClick={() => requestStatusChange(account)} className={`whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-1 ${account.account_status === 'active' ? 'border border-rose-200 text-rose-700 hover:bg-rose-50 focus:ring-rose-300' : 'border border-emerald-200 text-emerald-700 hover:bg-emerald-50 focus:ring-emerald-300'}`}>
                                {account.account_status === 'active' ? 'Deactivate' : 'Activate'}
                              </button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="grid gap-3 lg:hidden">
              {filteredAccounts.map((account) => {
                const isCurrent = Number(account.id) === currentAdminId
                return (
                  <article key={account.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <h3 className="break-words font-semibold text-slate-900">{account.full_name || 'Name not set'}</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Admin ID #{account.id}{isCurrent ? ' · You' : ''}</p>
                      </div>
                      <AccountStatusBadge status={account.account_status} />
                    </div>
                    <dl className="mt-4 grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2">
                      <div><dt className="text-xs text-slate-500">Username</dt><dd className="mt-0.5 break-all text-sm text-slate-800">{account.username || '—'}</dd></div>
                      <div><dt className="text-xs text-slate-500">Email</dt><dd className="mt-0.5 break-all text-sm text-slate-800">{account.email || '—'}</dd></div>
                      <div><dt className="text-xs text-slate-500">Created</dt><dd className="mt-0.5 text-sm text-slate-800">{formatAccountDate(account.created_at)}</dd></div>
                      <div><dt className="text-xs text-slate-500">Creator</dt><dd className="mt-0.5 text-sm text-slate-800">{account.creator_name || (account.created_by_admin_id ? `Admin #${account.created_by_admin_id}` : '—')}</dd></div>
                    </dl>
                    {!isCurrent && (
                      <button type="button" onClick={() => requestStatusChange(account)} className={`mt-4 min-h-10 w-full rounded-lg px-3 py-2 text-sm font-semibold transition ${account.account_status === 'active' ? 'border border-rose-200 text-rose-700 hover:bg-rose-50' : 'border border-emerald-200 text-emerald-700 hover:bg-emerald-50'}`}>
                        {account.account_status === 'active' ? 'Deactivate Admin' : 'Activate Admin'}
                      </button>
                    )}
                  </article>
                )
              })}
            </div>
          </>
        )}
      </div>

      {showCreate && <AdminAccountForm onClose={() => setShowCreate(false)} onCreated={handleCreated} />}
      <ConfirmationModal
        open={Boolean(statusTarget)}
        title={statusTarget?.nextStatus === 'deactivated' ? 'Deactivate Admin account?' : 'Reactivate Admin account?'}
        message={statusDescription}
        confirmText={statusTarget?.nextStatus === 'deactivated' ? 'Deactivate account' : 'Reactivate account'}
        variant={statusTarget?.nextStatus === 'deactivated' ? 'danger' : 'default'}
        loading={changingStatus}
        loadingText="Updating account…"
        onConfirm={updateStatus}
        onCancel={() => setStatusTarget(null)}
      />
    </>
  )
}

function SecurityContent() {
  return (
    <div className="mt-6 flex flex-col gap-5 rounded-2xl border border-sky-100 bg-sky-50/70 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
      <div className="flex items-start gap-4">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-sky-700 shadow-sm">
          <LuLockKeyhole aria-hidden="true" size={20} />
        </span>
        <div>
          <h3 className="font-semibold text-slate-900">Change your password</h3>
          <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
            Your Admin Profile already provides the secure password-change flow. You will need to sign in again after changing it.
          </p>
        </div>
      </div>
      <Link
        to="/admin/profile"
        className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-900 focus:outline-none focus:ring-2 focus:ring-cyan-600 focus:ring-offset-2"
      >
        Open Admin Profile
        <LuArrowUpRight aria-hidden="true" size={16} />
      </Link>
    </div>
  )
}

export default function AdminSettings() {
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedSectionId = searchParams.get('section')
  const activeSectionId = sections.some((section) => section.id === requestedSectionId)
    ? requestedSectionId
    : sections[0].id
  const activeSection = sections.find((section) => section.id === activeSectionId) || sections[0]
  const ActiveIcon = activeSection.icon

  return (
    <main className="min-h-screen overflow-x-hidden bg-slate-100 text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-6">
          <div>
            <Link
              to="/dashboard"
              className="inline-flex items-center gap-2 text-sm font-medium text-slate-600 transition hover:text-cyan-800"
            >
              <LuArrowLeft aria-hidden="true" size={16} />
              Back to Dashboard
            </Link>
            <p className="mt-5 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-800">Administration</p>
            <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Admin Settings</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
              A central place for administrator, booking, payment, and gym preferences.
            </p>
          </div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full border border-sky-100 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800">
            <LuInfo aria-hidden="true" size={14} />
            Settings foundation
          </span>
        </div>
      </header>

      <div className="mx-auto grid max-w-6xl gap-5 px-4 py-5 sm:px-6 sm:py-7">
        <nav aria-label="Admin settings sections" className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
          {sections.map((section) => {
            const Icon = section.icon
            const isActive = section.id === activeSectionId
            return (
              <button
                key={section.id}
                type="button"
                aria-pressed={isActive}
                onClick={() => {
                  setSearchParams({ section: section.id }, { replace: true })
                }}
                className={`flex min-h-[76px] items-center gap-3 rounded-2xl border p-3 text-left transition focus:outline-none focus:ring-2 focus:ring-cyan-600 focus:ring-offset-2 sm:min-h-[88px] sm:flex-col sm:items-start sm:justify-center sm:p-4 lg:min-h-[112px] ${
                  isActive
                    ? 'border-cyan-200 bg-white text-cyan-900 shadow-sm ring-1 ring-cyan-100'
                    : 'border-slate-200 bg-white/70 text-slate-600 hover:border-slate-300 hover:bg-white'
                }`}
              >
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${isActive ? 'bg-cyan-50 text-cyan-800' : 'bg-slate-100 text-slate-500'}`}>
                  <Icon aria-hidden="true" size={19} />
                </span>
                <span className="min-w-0">
                  <span className="hidden text-sm font-semibold leading-5 sm:block">{section.label}</span>
                  <span className="text-sm font-semibold sm:hidden">{section.shortLabel}</span>
                  <span className="mt-1 hidden text-xs leading-4 text-slate-500 lg:block">{section.description}</span>
                </span>
              </button>
            )
          })}
        </nav>

        <section
          aria-labelledby="settings-section-title"
          className="min-h-[320px] rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"
        >
          <div className="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex items-start gap-3">
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-cyan-50 text-cyan-800">
                <ActiveIcon aria-hidden="true" size={21} />
              </span>
              <div>
                <h2 id="settings-section-title" className="text-lg font-semibold text-slate-900 sm:text-xl">
                  {activeSection.title}
                </h2>
                <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{activeSection.detail}</p>
              </div>
            </div>
            <span className="inline-flex w-fit shrink-0 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600">
              {activeSection.status}
            </span>
          </div>

          {activeSection.id === 'accounts' ? (
            <AdminAccountManagement />
          ) : activeSection.id === 'security' ? (
            <SecurityContent />
          ) : activeSection.id === 'booking' ? (
            <BookingSettingsPanel />
          ) : activeSection.id === 'payments' ? (
            <div className="mt-6">
              <GcashSettingsPanel />
              <p className="mt-3 text-xs leading-5 text-slate-500">
                These collection details are displayed to members and applicants. Subscription payments still require the existing staff verification process.
              </p>
            </div>
          ) : (
            <div className="mt-6 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
              <span className="mt-0.5 text-slate-400">
                <LuInfo aria-hidden="true" size={18} />
              </span>
              <p className="text-sm leading-6 text-slate-600">{activeSection.content}</p>
            </div>
          )}
        </section>
      </div>
    </main>
  )
}
