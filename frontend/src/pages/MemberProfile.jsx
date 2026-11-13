import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FaArrowLeft, FaUser, FaEdit, FaTimes, FaSpinner, FaCheckCircle, FaExclamationCircle, FaQuestionCircle } from 'react-icons/fa'
import api from '../services/api'
import ProfilePictureEditor from '../components/ProfilePictureEditor.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import LogoutConfirmationModal from '../components/ui/LogoutConfirmationModal.jsx'
import EmailChangeModal from '../components/EmailChangeModal.jsx'
import { logout as logoutAccount } from '../utils/auth'
import { canAccessMemberProfile } from '../utils/memberProfileAccess.js'
import { getEmailAddressPresentation } from '../utils/emailAddressPresentation.js'
import { MEMBER_GENDER_OPTIONS } from '../utils/memberGender.js'

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('user') || sessionStorage.getItem('user') || 'null')
  } catch {
    return null
  }
}

function updateStoredUser(updatedFields) {
  const storages = [localStorage, sessionStorage]
  storages.forEach((store) => {
    try {
      const raw = store.getItem('user')
      if (raw) {
        const parsed = JSON.parse(raw)
        const updated = { ...parsed, ...updatedFields }
        store.setItem('user', JSON.stringify(updated))
      }
    } catch {
      // ignore
    }
  })
}

export default function MemberProfile() {
  const navigate = useNavigate()
  const [member, setMember] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false)
  const [emailChangeOpen, setEmailChangeOpen] = useState(false)

  // Edit Modal State
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [showConfirmModal, setShowConfirmModal] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [editErrors, setEditErrors] = useState({})
  const [editFormData, setEditFormData] = useState({
    first_name: '',
    middle_name: '',
    last_name: '',
    gender: '',
    phone: '',
    address: '',
    date_of_birth: '',
  })

  useEffect(() => {
    const user = getStoredUser()
    if (!canAccessMemberProfile(user)) {
      navigate('/login', { replace: true })
      return
    }

    const loadProfile = async () => {
      try {
        const response = await api.post('/member_profile.php', {})
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load your profile.')
        setMember(response.data.member)
      } catch (err) {
        setError(err?.response?.data?.message || err?.message || 'Unable to load your profile.')
      } finally {
        setLoading(false)
      }
    }
    loadProfile()
  }, [navigate])

  const openEditModal = () => {
    if (!member) return
    setEditFormData({
      first_name: member.first_name || '',
      middle_name: member.middle_name || '',
      last_name: member.last_name || '',
      gender: member.gender || '',
      phone: member.phone || '',
      address: member.address || '',
      date_of_birth: member.date_of_birth || '',
    })
    setEditErrors({})
    setShowConfirmModal(false)
    setIsEditOpen(true)
  }

  const closeEditModal = () => {
    if (isSaving) return
    setIsEditOpen(false)
    setShowConfirmModal(false)
    setEditErrors({})
  }

  const validateEditForm = () => {
    const errors = {}
    const trimmedFirst = editFormData.first_name.trim()
    const trimmedMiddle = editFormData.middle_name.trim()
    const trimmedLast = editFormData.last_name.trim()

    if (!trimmedFirst) {
      errors.first_name = 'First name is required.'
    } else if (trimmedFirst.length > 50) {
      errors.first_name = 'First name must not exceed 50 characters.'
    }

    if (trimmedMiddle.length > 50) {
      errors.middle_name = 'Middle name must not exceed 50 characters.'
    }

    if (!trimmedLast) {
      errors.last_name = 'Last name is required.'
    } else if (trimmedLast.length > 50) {
      errors.last_name = 'Last name must not exceed 50 characters.'
    }

    const trimmedPhone = editFormData.phone.trim()
    if (!trimmedPhone) {
      errors.phone = 'Contact number is required.'
    } else if (!/^\d{11}$/.test(trimmedPhone)) {
      errors.phone = 'Contact number must contain exactly 11 digits.'
    }

    const trimmedGender = editFormData.gender?.trim() || ''
    if (trimmedGender && !MEMBER_GENDER_OPTIONS.includes(trimmedGender)) {
      errors.gender = 'Gender must be one of: Male, Female, or Prefer not to say.'
    }

    if (editFormData.address && editFormData.address.trim().length > 255) {
      errors.address = 'Address cannot exceed 255 characters.'
    }

    if (editFormData.date_of_birth) {
      const today = new Date().toISOString().split('T')[0]
      if (editFormData.date_of_birth > today) {
        errors.date_of_birth = 'Date of birth cannot be in the future.'
      }
    }

    setEditErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleFormSubmit = (e) => {
    e.preventDefault()
    if (validateEditForm()) {
      setShowConfirmModal(true)
    }
  }

  const handleConfirmSave = async () => {
    setIsSaving(true)
    setEditErrors({})
    setSuccessMessage('')

    try {
      const response = await api.post('/member_profile.php?action=update', {
        email: member.email,
        first_name: editFormData.first_name.trim(),
        middle_name: editFormData.middle_name.trim(),
        last_name: editFormData.last_name.trim(),
        gender: editFormData.gender?.trim() || '',
        phone: editFormData.phone.trim(),
        address: editFormData.address.trim(),
        date_of_birth: editFormData.date_of_birth,
      })

      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Failed to update profile.')
      }

      setMember(response.data.member)
      updateStoredUser({
        first_name: response.data.member.first_name,
        middle_name: response.data.member.middle_name,
        last_name: response.data.member.last_name,
        full_name: response.data.member.full_name
      })
      setShowConfirmModal(false)
      setIsEditOpen(false)
      setSuccessMessage('Profile updated successfully.')
      setTimeout(() => setSuccessMessage(''), 5000)
    } catch (err) {
      setShowConfirmModal(false)
      const msg = err?.response?.data?.message || err?.message || 'Failed to update profile. Please try again.'
      setEditErrors({ general: msg })
    } finally {
      setIsSaving(false)
    }
  }

  const logout = async () => {
    await logoutAccount(navigate)
  }

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100 p-8 text-center text-slate-500">
        <FaSpinner className="mr-2 animate-spin text-xl text-violet-700" />
        <span>Loading your profile...</span>
      </div>
    )
  }

  if (error || !member) {
    return (
      <div className="min-h-screen bg-slate-100 p-8 text-center">
        <p className="text-rose-600">{error || 'Profile not found.'}</p>
        <Link to="/member/dashboard" className="mt-4 inline-block text-violet-700 hover:underline">
          Back to dashboard
        </Link>
      </div>
    )
  }

  const maxBirthDate = new Date().toISOString().split('T')[0]
  const emailPresentation = getEmailAddressPresentation(member.email)

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      {/* Top Header */}
      <header className="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-30">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            <Link
              to="/member/dashboard"
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-50"
              title="Back to dashboard"
            >
              <FaArrowLeft />
            </Link>
            <div className="min-w-0">
              <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Member Profile</p>
              <h1 className="truncate text-xl font-semibold">My Profile</h1>
            </div>
          </div>
          <button
            onClick={() => setLogoutConfirmOpen(true)}
            className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-100"
          >
            Logout
          </button>
        </div>
      </header>

      {/* Main Content */}
      <main className="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8">
        {/* Success Alert Banner */}
        {successMessage && (
          <div className="mb-6 flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3.5 text-sm text-emerald-800 shadow-sm">
            <div className="flex items-center gap-2.5">
              <FaCheckCircle className="shrink-0 text-emerald-600" />
              <span className="font-medium">{successMessage}</span>
            </div>
            <button
              type="button"
              onClick={() => setSuccessMessage('')}
              className="text-emerald-700 hover:text-emerald-900 ml-4 font-bold"
              aria-label="Dismiss alert"
            >
              <FaTimes />
            </button>
          </div>
        )}

        {/* Profile Card */}
        <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
          {/* Card Header */}
          <div className="flex flex-col gap-3 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                <FaUser />
              </div>
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Account identity</p>
                <h2 className="mt-0.5 text-lg font-semibold text-slate-900">Your profile information</h2>
              </div>
            </div>
            <button
              type="button"
              onClick={openEditModal}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-violet-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2"
            >
              <FaEdit /> Edit Profile
            </button>
          </div>

          {/* Profile Identity & Details */}
          <div className="mt-6 grid gap-8 md:grid-cols-[220px_1fr] md:items-start">
            {/* Profile Picture Column */}
            <div className="flex flex-col items-center">
              <ProfilePictureEditor
                entity="member"
                profilePicture={member.profile_picture}
                name={member.full_name}
                code={member.member_code}
                onUpdated={(profilePicture) =>
                  setMember((current) => ({ ...current, profile_picture: profilePicture }))
                }
              />
              <div className="mt-3 text-center">
                <p className="font-mono text-xs font-semibold text-slate-500">{member.member_code}</p>
                <div className="mt-1 flex justify-center">
                  <StatusBadge status={member.status} />
                </div>
              </div>
            </div>

            {/* Information Grid */}
            <div className="grid gap-5 sm:grid-cols-2">
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">First Name</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.first_name || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Middle Name</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.middle_name || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Last Name</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.last_name || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Full Name</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.full_name || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Gender</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.gender || 'Not specified'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Email Address</p>
                {emailPresentation.hasEmail ? (
                  <p className="mt-1.5 break-all font-semibold text-slate-900">{emailPresentation.currentEmail}</p>
                ) : (
                  <p className="mt-1.5 font-medium text-slate-600">{emailPresentation.emptyMessage}</p>
                )}
                <button
                  type="button"
                  onClick={() => setEmailChangeOpen(true)}
                  className="mt-3 rounded-lg border border-violet-200 bg-white px-3 py-2 text-sm font-semibold text-violet-700 transition hover:bg-violet-50"
                >
                  {emailPresentation.actionLabel}
                </button>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Contact Number</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.phone || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Member Code</p>
                <p className="mt-1.5 font-mono font-semibold text-slate-900">{member.member_code}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Address</p>
                <p className="mt-1.5 font-medium text-slate-800">{member.address || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Date of Birth</p>
                <p className="mt-1.5 font-medium text-slate-800">{member.date_of_birth || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Membership Type</p>
                <p className="mt-1.5 font-semibold text-slate-900">{member.membership_type || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Membership Start</p>
                <p className="mt-1.5 font-medium text-slate-800">{member.membership_start || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Membership End</p>
                <p className="mt-1.5 font-medium text-slate-800">{member.membership_end || '—'}</p>
              </div>
              <div className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 sm:col-span-2">
                <p className="text-xs uppercase tracking-[0.18em] text-slate-500">Account Status</p>
                <div className="mt-1.5">
                  <StatusBadge status={member.status} />
                </div>
              </div>
            </div>
          </div>
        </section>
      </main>

      <LogoutConfirmationModal
        open={logoutConfirmOpen}
        onConfirm={logout}
        onCancel={() => setLogoutConfirmOpen(false)}
      />
      <EmailChangeModal
        open={emailChangeOpen}
        onClose={() => setEmailChangeOpen(false)}
        currentEmail={member?.email || ''}
      />

      {/* Edit Profile Modal */}
      {isEditOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
          role="presentation"
          onClick={closeEditModal}
        >
          <div
            className="relative flex w-full max-w-2xl max-h-[90vh] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="member-edit-profile-title"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-5">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-slate-500">Edit Profile</p>
                <h2 id="member-edit-profile-title" className="mt-1 text-xl font-semibold text-slate-900">
                  Update Personal Information
                </h2>
              </div>
              <button
                type="button"
                onClick={closeEditModal}
                disabled={isSaving}
                className="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50"
                aria-label="Close modal"
              >
                <FaTimes />
              </button>
            </div>

            {/* Modal Body / Form */}
            <form onSubmit={handleFormSubmit} className="flex-1 overflow-y-auto px-6 py-6 space-y-6">
              {/* General Error Banner */}
              {editErrors.general && (
                <div className="flex items-center gap-2.5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                  <FaExclamationCircle className="shrink-0 text-base" />
                  <span className="font-medium">{editErrors.general}</span>
                </div>
              )}

              {/* Editable Fields */}
              <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>
                      First Name <span className="text-rose-500">*</span>
                    </span>
                    <input
                      type="text"
                      value={editFormData.first_name}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, first_name: e.target.value })
                        if (editErrors.first_name) setEditErrors({ ...editErrors, first_name: undefined })
                      }}
                      placeholder="e.g. Juan"
                      disabled={isSaving}
                      maxLength={50}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                      required
                    />
                    {editErrors.first_name && (
                      <p className="text-xs text-rose-600">{editErrors.first_name}</p>
                    )}
                  </label>

                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>
                      Middle Name <span className="text-xs font-normal text-slate-400">(Optional)</span>
                    </span>
                    <input
                      type="text"
                      value={editFormData.middle_name}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, middle_name: e.target.value })
                        if (editErrors.middle_name) setEditErrors({ ...editErrors, middle_name: undefined })
                      }}
                      placeholder="e.g. Santos"
                      disabled={isSaving}
                      maxLength={50}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                    />
                    {editErrors.middle_name && (
                      <p className="text-xs text-rose-600">{editErrors.middle_name}</p>
                    )}
                  </label>
                </div>

                <div>
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>
                      Last Name <span className="text-rose-500">*</span>
                    </span>
                    <input
                      type="text"
                      value={editFormData.last_name}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, last_name: e.target.value })
                        if (editErrors.last_name) setEditErrors({ ...editErrors, last_name: undefined })
                      }}
                      placeholder="e.g. Dela Cruz"
                      disabled={isSaving}
                      maxLength={50}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                      required
                    />
                    {editErrors.last_name && (
                      <p className="text-xs text-rose-600">{editErrors.last_name}</p>
                    )}
                  </label>
                </div>

                <div>
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>
                      Gender <span className="text-xs font-normal text-slate-400">(Optional)</span>
                    </span>
                    <select
                      value={editFormData.gender}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, gender: e.target.value })
                        if (editErrors.gender) setEditErrors({ ...editErrors, gender: undefined })
                      }}
                      disabled={isSaving}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                    >
                      <option value="">Not specified</option>
                      {MEMBER_GENDER_OPTIONS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
                    </select>
                    {editErrors.gender && (
                      <p className="text-xs text-rose-600">{editErrors.gender}</p>
                    )}
                  </label>
                </div>

                <div>
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>
                      Contact Number <span className="text-rose-500">*</span>
                    </span>
                    <input
                      type="tel"
                      inputMode="numeric"
                      value={editFormData.phone}
                      onChange={(e) => {
                        const numericValue = e.target.value.replace(/\D/g, '').slice(0, 11)
                        setEditFormData({ ...editFormData, phone: numericValue })
                        if (editErrors.phone) setEditErrors({ ...editErrors, phone: undefined })
                      }}
                      placeholder="e.g. 09123456789 (11 digits)"
                      disabled={isSaving}
                      maxLength={11}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                      required
                    />
                    {editErrors.phone && (
                      <p className="text-xs text-rose-600">{editErrors.phone}</p>
                    )}
                  </label>
                </div>

                <div>
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>Address</span>
                    <input
                      type="text"
                      value={editFormData.address}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, address: e.target.value })
                        if (editErrors.address) setEditErrors({ ...editErrors, address: undefined })
                      }}
                      placeholder="e.g. 123 Main St, City"
                      disabled={isSaving}
                      maxLength={255}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                    />
                    {editErrors.address && (
                      <p className="text-xs text-rose-600">{editErrors.address}</p>
                    )}
                  </label>
                </div>

                <div>
                  <label className="space-y-1.5 text-sm font-medium text-slate-700">
                    <span>Date of Birth</span>
                    <input
                      type="date"
                      max={maxBirthDate}
                      value={editFormData.date_of_birth}
                      onChange={(e) => {
                        setEditFormData({ ...editFormData, date_of_birth: e.target.value })
                        if (editErrors.date_of_birth) setEditErrors({ ...editErrors, date_of_birth: undefined })
                      }}
                      disabled={isSaving}
                      className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-500/20 disabled:opacity-60"
                    />
                    {editErrors.date_of_birth && (
                      <p className="text-xs text-rose-600">{editErrors.date_of_birth}</p>
                    )}
                  </label>
                </div>
              </div>

              {/* Read-Only Information Section */}
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
                  Read-Only System Fields
                </p>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 text-xs text-slate-600">
                  <div>
                    <span className="text-slate-400">Email Address (Login):</span>
                    <p className="font-medium text-slate-800 break-all">{member.email}</p>
                  </div>
                  <div>
                    <span className="text-slate-400">Member Code:</span>
                    <p className="font-mono font-medium text-slate-800">{member.member_code}</p>
                  </div>
                  <div>
                    <span className="text-slate-400">Membership Type:</span>
                    <p className="font-medium text-slate-800">{member.membership_type || '—'}</p>
                  </div>
                  <div>
                    <span className="text-slate-400">Account Status:</span>
                    <div className="mt-0.5">
                      <StatusBadge status={member.status} />
                    </div>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
                <button
                  type="button"
                  onClick={closeEditModal}
                  disabled={isSaving}
                  className="rounded-2xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSaving}
                  className="inline-flex items-center justify-center gap-2 rounded-2xl bg-violet-700 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  Save Changes
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Confirmation Dialog (Yes or No before proceeding) */}
      {showConfirmModal && (
        <div
          className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-4 animate-fade-in"
          role="presentation"
          onClick={() => !isSaving && setShowConfirmModal(false)}
        >
          <div
            className="relative w-full max-w-md overflow-hidden rounded-3xl bg-white p-6 shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-violet-100 text-violet-700">
                <FaQuestionCircle className="text-2xl" />
              </div>
              <div className="min-w-0 flex-1">
                <h3 id="confirm-modal-title" className="text-lg font-semibold text-slate-900">
                  Save Profile Changes?
                </h3>
                <p className="mt-1.5 text-sm text-slate-600">
                  Are you sure you want to proceed with updating your profile information?
                </p>
              </div>
            </div>

            <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row sm:justify-end">
              <button
                type="button"
                onClick={() => setShowConfirmModal(false)}
                disabled={isSaving}
                className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
              >
                No, Cancel
              </button>
              <button
                type="button"
                onClick={handleConfirmSave}
                disabled={isSaving}
                className="inline-flex items-center justify-center gap-2 rounded-xl bg-violet-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {isSaving ? (
                  <>
                    <FaSpinner className="animate-spin" /> Saving...
                  </>
                ) : (
                  'Yes, Save Changes'
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
