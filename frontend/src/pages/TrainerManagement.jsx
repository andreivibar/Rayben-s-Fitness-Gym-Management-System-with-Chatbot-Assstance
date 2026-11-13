import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSearch, FaPlus, FaUserTie, FaExchangeAlt, FaUser, FaCalendarCheck, FaKey } from 'react-icons/fa'
import api from '../services/api'
import TrainerFormModal from '../components/TrainerFormModal'
import TrainerDetailsModal from '../components/TrainerDetailsModal'
import ActionMenu from '../components/ui/ActionMenu.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import ResetPasswordModal from '../components/ResetPasswordModal.jsx'
import { formatFullName } from '../utils/nameFormatter'
import { profilePictureUrl } from '../utils/profilePictureUrl'

function TrainerManagement() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [trainers, setTrainers] = useState([])
  const [loading, setLoading] = useState(false)
  const [page, setPage] = useState(1)
  const [perPage] = useState(25)
  const [meta, setMeta] = useState(null)
  const [summary, setSummary] = useState(null)

  const [showCreate, setShowCreate] = useState(false)
  const [showEdit, setShowEdit] = useState(false)
  const [showView, setShowView] = useState(false)
  const [formData, setFormData] = useState({})
  const [errors, setErrors] = useState({})
  const [activeTrainer, setActiveTrainer] = useState(null)
  const [resetTrainer, setResetTrainer] = useState(null)
  const [pendingResetPassword, setPendingResetPassword] = useState('')
  const [resetPasswordLoading, setResetPasswordLoading] = useState(false)
  const [resetPasswordError, setResetPasswordError] = useState('')
  const [notice, setNotice] = useState('')
  const [operationError, setOperationError] = useState('')
  const [savingTrainer, setSavingTrainer] = useState(false)
  const [statusUpdatingTrainerId, setStatusUpdatingTrainerId] = useState(null)

  const fetchTrainers = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/trainers.php', { params: { search: search || undefined, status: statusFilter, page, per_page: perPage } })
      if (res?.data?.success) {
        setTrainers(res.data.trainers || [])
        setMeta(res.data.meta || null)
        setSummary(res.data.summary || null)
        setOperationError('')
      } else {
        setOperationError(res?.data?.message || 'Unable to load trainers.')
      }
    } catch (err) {
      setOperationError(err?.response?.data?.message || 'Unable to load trainers. Check your connection and try again.')
    } finally { setLoading(false) }
  }, [search, statusFilter, page, perPage])

  // The request owns its loading state; the effect only triggers the async fetch.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { fetchTrainers() }, [fetchTrainers])

  const openCreate = () => {
    setFormData({
      first_name: '',
      middle_name: '',
      last_name: '',
      username: '',
      email: '',
      phone: '',
      password: '',
      confirm_password: '',
      schedule: []
    })
    setErrors({})
    setShowCreate(true)
  }

  const handleChange = (field, value) => setFormData((s) => ({ ...s, [field]: value }))

  const createTrainer = async () => {
    if (savingTrainer) return
    setSavingTrainer(true)
    setErrors({})
    try {
      const payload = {
        first_name: (formData.first_name || '').trim(),
        middle_name: (formData.middle_name || '').trim(),
        last_name: (formData.last_name || '').trim(),
        username: (formData.username || '').trim(),
        email: (formData.email || '').trim(),
        phone: (formData.phone || '').trim(),
        password: formData.password,
        confirm_password: formData.confirm_password,
        availability: formData.schedule || []
      }
      const res = await api.post('/trainers.php?action=create', payload)
      if (res?.data?.success) {
        setShowCreate(false)
        fetchTrainers()
      } else {
        setErrors({ general: res?.data?.message || 'Unable to create trainer.' })
      }
    } catch (err) {
      setErrors({ general: err?.response?.data?.message || 'Unable to create trainer.' })
    } finally {
      setSavingTrainer(false)
    }
  }

  const openEdit = (t) => {
    setFormData({
      id: t.trainer_id,
      first_name: t.first_name || '',
      middle_name: t.middle_name || '',
      last_name: t.last_name || '',
      email: t.email || '',
      phone: t.phone || ''
    })
    setErrors({})
    setShowEdit(true)
  }

  const saveEdit = async () => {
    if (savingTrainer) return
    setSavingTrainer(true)
    setErrors({})
    try {
      const payload = {
        id: formData.id,
        first_name: (formData.first_name || '').trim(),
        middle_name: (formData.middle_name || '').trim(),
        last_name: (formData.last_name || '').trim(),
        email: (formData.email || '').trim(),
        phone: (formData.phone || '').trim()
      }
      const res = await api.post('/trainers.php?action=update', payload)
      if (res?.data?.success) {
        setShowEdit(false)
        fetchTrainers()
      } else {
        setErrors({ general: res?.data?.message || 'Unable to update trainer.' })
      }
    } catch (err) {
      setErrors({ general: err?.response?.data?.message || 'Unable to update trainer.' })
    } finally {
      setSavingTrainer(false)
    }
  }

  const toggleStatus = async (t) => {
    if (statusUpdatingTrainerId !== null) return
    const currentStatus = String(t.status || '').toLowerCase().trim()
    const next = currentStatus === 'active' ? 'inactive' : 'active'
    setStatusUpdatingTrainerId(t.trainer_id)
    setOperationError('')
    setNotice('')
    try {
      const res = await api.post('/trainers.php?action=status', { id: t.trainer_id, status: next })
      if (res?.data?.success) {
        setNotice(`Trainer ${next === 'active' ? 'activated' : 'deactivated'} successfully. ${next === 'active' ? 'They must log in again to access their account.' : 'Their current session has been revoked.'}`)
        await fetchTrainers()
      } else {
        setOperationError(res?.data?.message || 'Unable to update trainer status.')
      }
    } catch (err) {
      setOperationError(err?.response?.data?.message || 'Unable to update trainer status. Check your connection and try again.')
    } finally {
      setStatusUpdatingTrainerId(null)
    }
  }

  const openResetPassword = (trainer) => {
    setResetPasswordError('')
    setResetTrainer(trainer)
  }

  const resetTrainerPassword = async (password) => {
    if (!resetTrainer || resetPasswordLoading) return

    setResetPasswordLoading(true)
    setResetPasswordError('')
    setNotice('')
    try {
      const res = await api.post('/trainers.php?action=reset-password', {
        id: resetTrainer.trainer_id,
        password,
      })
      if (!res?.data?.success) {
        setResetPasswordError(res?.data?.message || 'Unable to reset the trainer password.')
        setPendingResetPassword('')
        return
      }

      setNotice(`Password reset successfully for ${formatFullName(resetTrainer) || resetTrainer.full_name}.`)
      setResetTrainer(null)
      setPendingResetPassword('')
    } catch (err) {
      setResetPasswordError(err?.response?.data?.message || 'Unable to reset the trainer password. Check your connection and try again.')
      setPendingResetPassword('')
    } finally {
      setResetPasswordLoading(false)
    }
  }

  const openView = (t) => { setActiveTrainer(t); setShowView(true) }

  const handleStatusFilterChange = (value) => {
    setStatusFilter(value)
    setPage(1)
  }

  const handleTrainerPictureUpdated = (profilePicture) => {
    setTrainers((current) => current.map((trainer) => (
      trainer.trainer_id === activeTrainer?.trainer_id ? { ...trainer, profile_picture: profilePicture } : trainer
    )))
    setActiveTrainer((current) => current ? { ...current, profile_picture: profilePicture } : current)
  }

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <header className="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-20">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div>
            <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Gym Management System</p>
            <h1 className="text-2xl font-semibold text-slate-900">Trainer Management</h1>
            <p className="text-sm text-slate-500">Manage gym trainers and trainer accounts.</p>
          </div>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-3">
              <button onClick={() => navigate('/trainer/attendance/records')} className="rounded-2xl border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-medium text-violet-700"><FaCalendarCheck className="mr-2 inline-block" />Attendance Records</button>
              <button onClick={() => navigate('/dashboard')} className="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">Back to Dashboard</button>
            </div>
            <button onClick={openCreate} className="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-medium text-white"><FaPlus className="mr-2 inline-block" /> Add Trainer</button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-6 py-8">
        {notice && (
          <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {notice}
            <button type="button" onClick={() => setNotice('')} className="ml-3 font-semibold underline">Dismiss</button>
          </div>
        )}
        {operationError && (
          <div role="alert" className="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {operationError}
            <button type="button" onClick={() => setOperationError('')} className="ml-3 font-semibold underline">Dismiss</button>
          </div>
        )}
        <section className="grid gap-6 lg:grid-cols-[1.4fr_0.6fr]">
          <div className="rounded-3xl bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
              <div>
                <p className="text-sm font-medium uppercase tracking-[0.24em] text-slate-500">Trainer roster</p>
                <h2 className="mt-3 text-3xl font-semibold text-slate-900">Manage your trainers</h2>
              </div>
              <div className="flex w-full max-w-xl flex-col gap-3 sm:flex-row">
              <div className="relative min-w-0 flex-1">
                <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} placeholder="Search trainers by name, code, email, or phone..." className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-10 pr-4 text-sm text-slate-900 shadow-sm" />
              </div>
              <select value={statusFilter} onChange={(e) => handleStatusFilterChange(e.target.value)} className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm">
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
              </div>
            </div>

            <div className="mt-8 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
              <div className="grid min-w-[980px] grid-cols-[280px_260px_140px_240px] gap-4 bg-slate-50 px-6 py-4 text-xs uppercase tracking-[0.3em] text-slate-500">
                <span>Trainer</span>
                <span>Contact</span>
                <span>Status</span>
                <span className="text-right">Actions</span>
              </div>
              <div className="divide-y divide-slate-200">
                {loading ? (
                  <div className="px-6 py-12 text-center text-sm text-slate-500">Loading trainers...</div>
                ) : trainers.length > 0 ? (
                  trainers.map((t) => (
                    <div key={t.trainer_id} className="grid min-w-[980px] grid-cols-[280px_260px_140px_240px] items-center gap-4 px-6 py-5 text-sm text-slate-700">
                      <div className="flex min-w-0 items-center gap-3">
                        <div className="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border-4 border-violet-100 bg-violet-50 text-violet-700 shadow-sm">
                          <FaUser className="text-lg" />
                          {t.profile_picture && (
                            <img
                              src={profilePictureUrl(t.profile_picture)}
                              alt={`${formatFullName(t) || t.full_name || 'Trainer'} profile`}
                              className="absolute inset-0 h-full w-full object-cover"
                              onError={(event) => { event.currentTarget.style.display = 'none' }}
                            />
                          )}
                        </div>
                        <div className="min-w-0">
                          <p className="break-words font-semibold text-slate-900">{formatFullName(t) || t.full_name}</p>
                          <p className="mt-1 text-xs text-slate-500">{t.trainer_code}</p>
                        </div>
                      </div>
                      <div className="min-w-0">
                        <p className="truncate font-medium text-slate-900" title={t.email}>{t.email}</p>
                        <p className="mt-1 text-xs text-slate-500">{t.phone || '—'}</p>
                      </div>
                      <div className="min-w-0">
                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${String(t.status || '').toLowerCase() === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>{String(t.status || '').toLowerCase() === 'active' ? 'Active' : 'Inactive'}</span>
                      </div>
                      <div className="flex min-w-0 items-center justify-end gap-2 whitespace-nowrap">
                        <button onClick={() => navigate(`/trainer/schedule?trainer_id=${t.trainer_id}`)} className="rounded-2xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-700">Assign Schedule</button>
                        <button onClick={() => openView(t)} className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">View</button>
                        <button onClick={() => openEdit(t)} className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">Edit</button>
                        <ActionMenu
                          actions={[
                            {
                              label: String(t.status || '').toLowerCase() === 'active' ? 'Deactivate' : 'Activate',
                              icon: <FaExchangeAlt />,
                              onClick: () => toggleStatus(t),
                              disabled: statusUpdatingTrainerId !== null,
                            },
                            {
                              label: 'Reset Password',
                              icon: <FaKey />,
                              onClick: () => openResetPassword(t),
                            },
                          ]}
                        />
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="px-6 py-12 text-center text-sm text-slate-500">No trainers found.</div>
                )}
              </div>
            </div>

            {/* Pagination */}
            {meta && meta.total_pages > 1 && (
              <div className="mt-4 flex items-center justify-between">
                <div className="text-sm text-slate-600">Page {meta.page} of {meta.total_pages}</div>
                <div className="flex items-center gap-2">
                  <button disabled={meta.page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} className="rounded-2xl border border-slate-200 px-3 py-2">Previous</button>
                  <button disabled={meta.page >= meta.total_pages} onClick={() => setPage((p) => Math.min(meta.total_pages, p + 1))} className="rounded-2xl border border-slate-200 px-3 py-2">Next</button>
                </div>
              </div>
            )}
          </div>

          <aside className="space-y-6">
            <div className="rounded-3xl bg-white p-6 shadow-sm">
              <div className="flex items-center gap-3">
                <div className="rounded-2xl bg-slate-900 p-3 text-white"><FaUserTie /></div>
                <div>
                  <p className="text-sm font-medium uppercase tracking-[0.24em] text-slate-500">Roster summary</p>
                  <p className="mt-2 text-lg font-semibold text-slate-900">{summary ? summary.total_trainers : '—'} trainers</p>
                </div>
              </div>
              <div className="mt-6 space-y-3 text-sm text-slate-600">
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <p className="text-sm text-slate-500">Active Trainers</p>
                  <p className="mt-2 text-2xl font-semibold text-slate-900">{summary ? summary.active : '—'}</p>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <p className="text-sm text-slate-500">Inactive Trainers</p>
                  <p className="mt-2 text-2xl font-semibold text-slate-900">{summary ? summary.inactive : '—'}</p>
                </div>
              </div>
            </div>
          </aside>
        </section>
      </main>

      <TrainerFormModal open={showCreate} mode="create" formData={formData} onChange={handleChange} onClose={() => { if (!savingTrainer) setShowCreate(false) }} onSave={createTrainer} errors={errors} saving={savingTrainer} />
      <TrainerFormModal open={showEdit} mode="edit" formData={formData} onChange={handleChange} onClose={() => { if (!savingTrainer) setShowEdit(false) }} onSave={saveEdit} errors={errors} saving={savingTrainer} />
      <TrainerDetailsModal open={showView} trainer={activeTrainer} onClose={() => setShowView(false)} onPictureUpdated={handleTrainerPictureUpdated} />
      <ResetPasswordModal
        key={resetTrainer?.trainer_id ?? 'closed'}
        open={Boolean(resetTrainer)}
        onClose={() => {
          if (!resetPasswordLoading) {
            setPendingResetPassword('')
            setResetTrainer(null)
          }
        }}
        onConfirm={setPendingResetPassword}
        member={resetTrainer ? { fullName: formatFullName(resetTrainer) || resetTrainer.full_name, email: resetTrainer.email } : null}
        accountType="trainer"
        loading={resetPasswordLoading}
        externalError={resetPasswordError}
      />
      <ConfirmationModal
        open={Boolean(pendingResetPassword)}
        title="Confirm Password Reset"
        message={`Are you sure you want to reset the password for ${formatFullName(resetTrainer || {}) || resetTrainer?.full_name || 'this trainer'}?`}
        confirmText="Yes, Reset Password"
        cancelText="No"
        loading={resetPasswordLoading}
        loadingText="Resetting..."
        onConfirm={() => resetTrainerPassword(pendingResetPassword)}
        onCancel={() => setPendingResetPassword('')}
      />
    </div>
  )
}

export default TrainerManagement
