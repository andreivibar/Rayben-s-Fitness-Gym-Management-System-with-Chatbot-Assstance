import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FaPlus, FaSearch, FaUsers, FaUserCheck, FaCalendarAlt, FaDoorOpen, FaArrowLeft, FaChevronLeft, FaChevronRight, FaTimes } from 'react-icons/fa'
import api from '../services/api.js'
import MembersTable from '../components/MembersTable.jsx'
import MemberFormModal from '../components/MemberFormModal.jsx'
import MemberDetailsModal from '../components/MemberDetailsModal.jsx'
import ResetPasswordModal from '../components/ResetPasswordModal.jsx'
import ConfirmationModal from '../components/ui/ConfirmationModal.jsx'
import WalkInRegistrationModal from '../components/WalkInRegistrationModal.jsx'
import DuplicateNameExceptionReviews from '../components/DuplicateNameExceptionReviews.jsx'
import DailyPasses from './DailyPasses.jsx'
import BookingPaymentsTab from '../components/BookingPaymentsTab.jsx'
import { useFeedback } from '../components/ui/FeedbackContext.js'

const APPLICATIONS_PER_PAGE = 10

const defaultFormState = {
  firstName: '',
  middleName: '',
  lastName: '',
  email: '',
  phone: '',
  address: '',
  dob: '',
  membershipType: 'monthly',
  membershipStart: '',
  membershipEnd: '',
  memberCategory: 'regular',
  gymAccessControlStatus: 'allowed',
  accessChangeReason: '',
  status: 'Active',
  password: '',
}

function isInitialMonthlyRegistration(request) {
  return request.membership_type === 'monthly'
    && !request.member_id
    && ['online', 'walk_in'].includes(request.registration_source)
}

const membershipTypeOptions = [
  { label: 'All', value: 'all' },
  { label: 'Monthly Subscription', value: 'monthly' },
  { label: '1 Day Pass accounts', value: 'daily_pass' },
  { label: 'Legacy 1 Day Pass accounts', value: 'walk-in' },
]

const accountStatusOptions = [
  { label: 'All Accounts', value: 'all' },
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'deactivated' },
]

function normalizeKey(value) {
  return (value || '').toString().trim().toLowerCase().replace(/[^a-z0-9]+/g, '')
}

function formatMembershipType(value) {
  const typeKey = normalizeKey(value)
  if (typeKey === 'monthlysubscription' || typeKey === 'monthly') {
    return 'Monthly Subscription'
  }
  if (typeKey === 'walkin' || typeKey === 'walkinmembership') {
    return '1 Day Pass (legacy)'
  }
  if (typeKey === 'dailypass' || typeKey === '1daypass') {
    return '1 Day Pass'
  }
  return value?.toString().trim() || 'Unknown'
}

function normalizeMembershipType(value) {
  const typeKey = normalizeKey(value)
  if (typeKey === 'monthlysubscription' || typeKey === 'monthly') {
    return 'monthly'
  }
  if (typeKey === 'walkin' || typeKey === 'walkinmembership') {
    return 'walk-in'
  }
  if (typeKey === 'dailypass' || typeKey === '1daypass') {
    return 'daily_pass'
  }
  return value?.toString().trim() || ''
}

function classifyApplication(request) {
  if (Number(request.is_initial_daily_pass_approval) === 1) return 'registrations'
  if (!request.member_id) return 'registrations'
  return normalizeMembershipType(request.current_membership_type) === 'monthly' ? 'renewals' : 'upgrades'
}

function applicationTypeLabel(request) {
  if (Number(request.is_initial_daily_pass_approval) === 1) return 'New Daily Pass registration'
  const category = classifyApplication(request)
  if (category === 'registrations') return 'New registration'
  if (category === 'renewals') return 'Renewal'
  return 'Upgrade'
}

function formatApplicationDate(value, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
  if (!value) return '—'
  const raw = String(value)
  const date = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}+08:00`)
  if (Number.isNaN(date.getTime())) return raw
  return new Intl.DateTimeFormat('en-PH', { ...options, timeZone: 'Asia/Manila' }).format(date)
}

function applicationPaymentLabel(status) {
  const normalized = String(status || '').toLowerCase()
  if (normalized === 'verified') return 'Verified'
  if (normalized === 'submitted') return 'Pending verification'
  if (normalized === 'pending') return 'Pending'
  if (normalized === 'unpaid') return 'Unpaid'
  if (normalized === 'paid') return 'Paid'
  if (normalized === 'not_required') return 'Not required'
  if (normalized === 'rejected') return 'Rejected'
  if (normalized === 'needs_correction') return 'Correction requested'
  return status || 'Unavailable'
}

function statusBadgeClass(status) {
  const normalized = String(status || '').toLowerCase()
  if (['approved', 'verified', 'not_required'].includes(normalized)) return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (['pending', 'submitted', 'needs_correction'].includes(normalized)) return 'border-amber-200 bg-amber-50 text-amber-800'
  if (['rejected'].includes(normalized)) return 'border-rose-200 bg-rose-50 text-rose-700'
  return 'border-slate-200 bg-slate-100 text-slate-600'
}

function ApplicationStatusBadge({ status, label }) {
  return <span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${statusBadgeClass(status)}`}>{label || status || 'Unknown'}</span>
}

function ApplicationProofPreview({ fileId, fileName, onDownload, endpoint = 'registration_request_file.php' }) {
  const [previewUrl, setPreviewUrl] = useState('')
  const [mimeType, setMimeType] = useState('')
  const [previewError, setPreviewError] = useState('')
  const [loadingPreview, setLoadingPreview] = useState(true)

  useEffect(() => {
    let active = true
    let url = ''
    const controller = new AbortController()
    api.get(`${endpoint}?id=${encodeURIComponent(fileId)}`, {
      responseType: 'blob',
      signal: controller.signal,
    }).then((response) => {
      if (!active) return
      url = URL.createObjectURL(response.data)
      setPreviewUrl(url)
      setMimeType(response.data.type || '')
    }).catch((requestError) => {
      if (active) setPreviewError(requestError?.response?.data?.message || 'Unable to load document preview.')
    }).finally(() => {
      if (active) setLoadingPreview(false)
    })
    return () => {
      active = false
      controller.abort()
      if (url) URL.revokeObjectURL(url)
    }
  }, [endpoint, fileId])

  return (
    <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
      <div className="flex items-center justify-between gap-3">
        <p className="truncate text-xs font-medium text-slate-700">{fileName || 'Submitted document'}</p>
        <button type="button" onClick={() => onDownload(fileId, endpoint)} className="shrink-0 text-xs font-semibold text-sky-700 underline">Download</button>
      </div>
      {loadingPreview ? <p className="py-6 text-center text-xs text-slate-500">Loading preview…</p>
        : previewError ? <p role="alert" className="py-3 text-xs text-rose-700">{previewError}</p>
          : mimeType === 'application/pdf' && previewUrl ? <iframe title={fileName || 'Application document'} src={previewUrl} className="mt-3 h-72 w-full rounded-lg border border-slate-200 bg-white" />
            : previewUrl ? <img src={previewUrl} alt={fileName || 'Application document'} className="mt-3 max-h-72 w-full rounded-lg border border-slate-200 bg-white object-contain" />
              : <p className="py-3 text-xs text-slate-500">Preview unavailable. Use Download to inspect this file.</p>}
    </div>
  )
}

function formatStatus(value) {
  const statusKey = normalizeKey(value)
  if (statusKey === 'active') return 'Active'
  if (statusKey === 'expired') return 'Expired'
  return value?.toString().trim() || 'Unknown'
}

function formatMemberRow(row) {
  const rawType = row.membership_type?.trim() || ''
  const membershipStart = row.membership_start || ''
  const membershipEnd = row.membership_end || ''
  const hasSubscriptionDates = [membershipStart, membershipEnd].some(
    (date) => date && date !== '0000-00-00'
  )

  const inferredType = rawType || (hasSubscriptionDates ? 'monthly' : 'walk-in')
  const membershipType = formatMembershipType(inferredType)
  const statusLabel = formatStatus(row.membership_status || row.status)
  const accountStatusKey = normalizeKey(row.account_status || 'active')

  return {
    id: Number(row.id),
    accountUserId: row.account_user_id ? Number(row.account_user_id) : null,
    accountRole: String(row.account_role || '').toLowerCase().trim(),
    memberCode: row.member_code,
    firstName: row.first_name || '',
    middleName: row.middle_name || '',
    lastName: row.last_name || '',
    fullName: row.full_name,
    email: row.email,
    phone: row.phone,
    address: row.address,
    dob: row.date_of_birth,
    gender: row.gender || 'Not specified',
    membershipType,
    membershipTypeKey: normalizeMembershipType(inferredType),
    membershipStart: membershipStart === '0000-00-00' ? '' : membershipStart,
    membershipEnd: membershipEnd === '0000-00-00' ? '' : membershipEnd,
    status: statusLabel,
    statusKey: normalizeKey(statusLabel),
    gymAccessStatus: row.gym_entry_status || 'No Valid Gym Access',
    gymAccessStatusKey: normalizeKey(row.gym_entry_status || 'No Valid Gym Access'),
    gymAccessControlStatus: row.gym_access_status === 'restricted' ? 'restricted' : 'allowed',
    memberCategory: row.member_category || 'regular',
    accountStatus: accountStatusKey === 'deactivated' ? 'Inactive' : 'Active',
    accountStatusKey,
    profilePicture: row.profile_picture || null,
    dateRegistered: row.created_at,
  }
}

async function fetchMemberRows() {
  const response = await api.get('members.php')
  if (!response.data?.success) {
    throw new Error(response.data?.message || 'Could not load members.')
  }
  return response.data.members.map(formatMemberRow)
}

async function fetchRegistrationRequests() {
  const response = await api.get('registration_requests.php')
  if (!response.data?.success) {
    throw new Error(response.data?.message || 'Could not load registration requests.')
  }
  return response.data.registration_requests || []
}

function Members() {
  const navigate = useNavigate()
  const { showSuccess, showRequestError } = useFeedback()
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedTab = searchParams.get('tab')
  const activeTab = ['members', 'applications', 'daily-passes', 'booking-payments'].includes(requestedTab) ? requestedTab : 'members'
  const [bookingPaymentsPendingCount, setBookingPaymentsPendingCount] = useState(0)
  const canReviewDuplicateNameExceptions = useMemo(() => {
    try {
      const user = JSON.parse(localStorage.getItem('user') || sessionStorage.getItem('user') || 'null')
      return String(user?.role || '').toLowerCase().trim() === 'admin'
    } catch {
      return false
    }
  }, [])
  const requestedCategory = searchParams.get('category')
  const applicationCategory = ['registrations', 'renewals', 'upgrades', 'payment-reviews'].includes(requestedCategory) ? requestedCategory : 'all'
  const [members, setMembers] = useState([])
  const [registrationRequests, setRegistrationRequests] = useState([])
  const [paymentReviewRequests, setPaymentReviewRequests] = useState([])
  const [applicationHistory, setApplicationHistory] = useState([])
  const [applicationListMode, setApplicationListMode] = useState('pending')
  const [historyLoading, setHistoryLoading] = useState(false)
  const [historyError, setHistoryError] = useState('')
  const [searchQuery, setSearchQuery] = useState('')
  const [applicationSearch, setApplicationSearch] = useState('')
  const [applicationPage, setApplicationPage] = useState(1)
  const [selectedApplicationId, setSelectedApplicationId] = useState(null)
  const [applicationConfirmation, setApplicationConfirmation] = useState(null)
  const [statusFilter, setStatusFilter] = useState('all')
  const [accountStatusFilter, setAccountStatusFilter] = useState('all')
  const [typeFilter, setTypeFilter] = useState('all')
  const [isAddOpen, setIsAddOpen] = useState(false)
  const [isWalkInRegistrationOpen, setIsWalkInRegistrationOpen] = useState(false)
  const [selectedMember, setSelectedMember] = useState(null)
  const [viewMember, setViewMember] = useState(null)
  const [membershipAccessHistory, setMembershipAccessHistory] = useState([])
  const [membershipAccessHistoryLoading, setMembershipAccessHistoryLoading] = useState(false)
  const [membershipAccessHistoryError, setMembershipAccessHistoryError] = useState('')
  const [resetMember, setResetMember] = useState(null)
  const [resetPasswordLoading, setResetPasswordLoading] = useState(false)
  const [statusConfirmMember, setStatusConfirmMember] = useState(null)
  const [promotionMember, setPromotionMember] = useState(null)
  const [promotionLoading, setPromotionLoading] = useState(false)
  const [newStatus, setNewStatus] = useState('')
  const [formData, setFormData] = useState(defaultFormState)
  const [editMode, setEditMode] = useState(false)
  const [loading, setLoading] = useState(true)
  const [requestsLoading, setRequestsLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [requestsError, setRequestsError] = useState('')
  const [requestActionLoadingId, setRequestActionLoadingId] = useState(null)
  const [proofReviewLoadingKey, setProofReviewLoadingKey] = useState('')
  const [applicationRejectReason, setApplicationRejectReason] = useState('')
  const [proofReviewNotes, setProofReviewNotes] = useState({})

  const refreshMembers = async () => {
    setLoading(true)
    setError('')
    try {
      setMembers(await fetchMemberRows())
    } catch (requestError) {
      setError(requestError?.message || 'Unable to connect to the member database.')
    } finally {
      setLoading(false)
    }
  }

  const promoteMemberToTrainer = async () => {
    if (!promotionMember || promotionLoading) return
    setPromotionLoading(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.post('member_to_trainer.php?action=promote', {
        member_id: promotionMember.id,
      })
      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Unable to promote this member.')
      }
      setSuccess(response.data.message || `${promotionMember.fullName} was promoted to Trainer.`)
      setPromotionMember(null)
      await refreshMembers()
    } catch (requestError) {
      setError(
        requestError?.response?.data?.message
        || requestError?.message
        || 'Unable to promote this member. Please try again.'
      )
    } finally {
      setPromotionLoading(false)
    }
  }

  const refreshRegistrationRequests = async () => {
    setRequestsLoading(true)
    setRequestsError('')
    try {
      const [requests, paymentReviewsResponse] = await Promise.all([
        fetchRegistrationRequests(),
        api.get('registration_requests.php', { params: { action: 'payment_reviews' } }),
      ])
      if (!paymentReviewsResponse.data?.success) {
        throw new Error(paymentReviewsResponse.data?.message || 'Could not load payment review requests.')
      }
      setRegistrationRequests(requests)
      setPaymentReviewRequests(paymentReviewsResponse.data.payment_reviews || [])
      return requests
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'Unable to connect to registration requests.'
      console.error('Registration requests error', err)
      setRequestsError(message)
      return null
    } finally {
      setRequestsLoading(false)
    }

  }

  const refreshApplicationHistory = async () => {
    setHistoryLoading(true)
    setHistoryError('')
    try {
      const response = await api.get('registration_requests.php', { params: { action: 'application_history' } })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load application history.')
      setApplicationHistory(response.data.application_history || [])
    } catch (requestError) {
      setHistoryError(requestError?.response?.data?.message || requestError?.message || 'Unable to load application history.')
    } finally {
      setHistoryLoading(false)
    }
  }

  const handleRequestAction = async (requestId, action, reason = '', confirmPayment = false) => {
    if (requestActionLoadingId !== null) return false
    setRequestActionLoadingId(requestId)
    setRequestsError('')
    setSuccess('')
    try {
      const response = await api.post(`registration_requests.php?action=${action}`, {
        id: requestId,
        ...(action === 'reject' ? { reason: reason.trim() } : {}),
        ...(action === 'approve' && confirmPayment ? { confirm_payment: true } : {}),
      })
      if (response.data?.success) {
        setSuccess(response.data.message || 'Application updated successfully.')
        const updatedRequests = await refreshRegistrationRequests()
        if (action === 'approve') {
          await refreshMembers()
        }
        if (action === 'reject') setApplicationRejectReason('')
        if (updatedRequests && !updatedRequests.some((request) => Number(request.id) === Number(requestId))) {
          setSelectedApplicationId(null)
        }
        return true
      } else {
        setRequestsError(response.data?.message || 'Unable to update registration request.')
      }
    } catch (requestError) {
      setRequestsError(requestError?.response?.data?.message || 'Unable to update registration request. Please try again.')
    } finally {
      setRequestActionLoadingId(null)
    }
    return false
  }

  const reviewRegistrationProof = async (requestId, documentType, decision, note = '') => {
    const loadingKey = `${requestId}-${documentType}`
    if (proofReviewLoadingKey) return
    if (['rejected', 'needs_correction'].includes(decision) && !note.trim()) {
      setRequestsError('Enter a review note before rejecting a document or requesting a correction.')
      return
    }

    setProofReviewLoadingKey(loadingKey)
    setRequestsError('')
    setSuccess('')
    try {
      const response = await api.post('registration_requests.php?action=review_proof', {
        id: requestId,
        document_type: documentType,
        decision,
        note: note.trim(),
      })
      if (!response.data?.success) {
        setRequestsError(response.data?.message || 'Unable to save the document review.')
        return
      }
      setSuccess(response.data.message || 'Document review saved.')
      setProofReviewNotes((current) => ({ ...current, [loadingKey]: '' }))
      await refreshRegistrationRequests()
    } catch (requestError) {
      setRequestsError(
        requestError?.response?.data?.message ||
        'Unable to save the document review. Please try again.'
      )
    } finally {
      setProofReviewLoadingKey('')
    }
  }

  const reviewRegistrationPaymentProof = async (requestId, decision, note = '') => {
    const loadingKey = `${requestId}-payment_proof`
    if (proofReviewLoadingKey) return
    if (['rejected', 'needs_correction'].includes(decision) && !note.trim()) {
      setRequestsError('Enter a review note before rejecting payment proof or requesting a correction.')
      return
    }
    setProofReviewLoadingKey(loadingKey)
    setRequestsError('')
    setSuccess('')
    try {
      const response = await api.post('registration_requests.php?action=review_payment_proof', {
        id: requestId,
        decision,
        note: note.trim(),
      })
      if (!response.data?.success) {
        setRequestsError(response.data?.message || 'Unable to save the payment review.')
        return
      }
      setSuccess(response.data.message || 'Payment review saved.')
      setProofReviewNotes((current) => ({ ...current, [loadingKey]: '' }))
      await refreshRegistrationRequests()
      if (decision === 'verified') await refreshMembers()
    } catch (requestError) {
      setRequestsError(requestError?.response?.data?.message || 'Unable to save the payment review. Please try again.')
    } finally {
      setProofReviewLoadingKey('')
    }
  }

  const confirmRegistrationCashPayment = async (requestId) => {
    if (requestActionLoadingId !== null) return
    setRequestActionLoadingId(requestId)
    setRequestsError('')
    setSuccess('')
    try {
      const response = await api.post('registration_requests.php?action=confirm_cash_payment', { id: requestId })
      if (!response.data?.success) {
        setRequestsError(response.data?.message || 'Unable to confirm cash receipt.')
        return
      }
      setSuccess(response.data.message || 'Cash receipt confirmed.')
      await refreshRegistrationRequests()
      await refreshMembers()
    } catch (requestError) {
      setRequestsError(requestError?.response?.data?.message || 'Unable to confirm cash receipt. Please try again.')
    } finally {
      setRequestActionLoadingId(null)
    }
  }

  const downloadRegistrationProof = async (fileId, endpoint = 'registration_request_file.php') => {
    try {
      const response = await api.get(`${endpoint}?id=${encodeURIComponent(fileId)}`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = endpoint === 'daily_pass_proof.php'
        ? `daily-pass-proof-${fileId}`
        : `registration-proof-${fileId}`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch (requestError) {
      setRequestsError(
        requestError?.response?.data?.message ||
        'Unable to download the proof document. Please try again.'
      )
    }
  }

  useEffect(() => {
    let active = true
    api.get('/payments.php', { params: { payment_type: 'training_session', status: 'pending' } })
      .then((res) => {
        if (!active) return
        if (res.data?.success) {
          const list = (res.data.payments || []).filter(
            (p) => p.status === 'pending' && p.verification_status === 'pending'
          )
          setBookingPaymentsPendingCount(list.length)
        }
      })
      .catch(() => {})

    fetchMemberRows()
      .then((rows) => {
        if (active) setMembers(rows)
      })
      .catch((requestError) => {
        if (active) setError(requestError?.message || 'Unable to connect to the member database.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    Promise.all([
      fetchRegistrationRequests(),
      api.get('registration_requests.php', { params: { action: 'payment_reviews' } }),
    ])
      .then(([requests, paymentReviewsResponse]) => {
        if (!paymentReviewsResponse.data?.success) throw new Error(paymentReviewsResponse.data?.message || 'Could not load payment review requests.')
        if (active) {
          setRegistrationRequests(requests)
          setPaymentReviewRequests(paymentReviewsResponse.data.payment_reviews || [])
        }
      })
      .catch((requestError) => {
        console.error('Registration requests error', requestError)
        if (active) setRequestsError(requestError?.message || 'Unable to connect to registration requests.')
      })
      .finally(() => {
        if (active) setRequestsLoading(false)
      })
    return () => {
      active = false
    }
  }, [])

  const filteredMembers = useMemo(() => {
    return members.filter((member) => {
      const searchValue = searchQuery.toLowerCase().trim()
      const matchesSearch = !searchValue ||
        (member.firstName && member.firstName.toLowerCase().includes(searchValue)) ||
        (member.middleName && member.middleName.toLowerCase().includes(searchValue)) ||
        (member.lastName && member.lastName.toLowerCase().includes(searchValue)) ||
        (member.fullName && member.fullName.toLowerCase().includes(searchValue)) ||
        (member.memberCode && member.memberCode.toLowerCase().includes(searchValue)) ||
        (member.email && member.email.toLowerCase().includes(searchValue)) ||
        (member.phone && member.phone.toLowerCase().includes(searchValue))

      const matchesStatus = statusFilter === 'all'
        || (statusFilter === 'restricted'
          ? member.gymAccessControlStatus === 'restricted'
          : member.gymAccessStatusKey === statusFilter)
      const matchesAccountStatus =
        accountStatusFilter === 'all' ? true : member.accountStatusKey === accountStatusFilter
      const matchesType =
        typeFilter === 'all' ? true : member.membershipTypeKey === typeFilter

      return matchesSearch && matchesStatus && matchesAccountStatus && matchesType
    })
  }, [members, searchQuery, statusFilter, accountStatusFilter, typeFilter])

  const stats = useMemo(() => {
    const total = members.length
    const active = members.filter((member) => member.accountStatusKey === 'active').length
    const monthly = members.filter((member) => member.membershipTypeKey === 'monthly').length
    const dayPassAccounts = members.filter((member) =>
      ['daily_pass', 'walk-in'].includes(member.membershipTypeKey)
    ).length

    return { total, active, monthly, dayPassAccounts }
  }, [members])

  const filteredRegistrationRequests = useMemo(() => {
    const source = applicationCategory === 'payment-reviews' ? paymentReviewRequests : registrationRequests
    return source.filter((request) => {
    const categoryMatches = applicationCategory === 'all'
      || applicationCategory === 'payment-reviews'
      || classifyApplication(request) === applicationCategory
    const query = applicationSearch.trim().toLowerCase()
    const searchMatches = !query
      || String(request.full_name || '').toLowerCase().includes(query)
      || String(request.first_name || '').toLowerCase().includes(query)
      || String(request.last_name || '').toLowerCase().includes(query)
      || String(request.member_id || '').includes(query.replace(/^m(?:ember)?\s*#?/i, ''))
      || String(request.id || '').includes(query.replace(/^app(?:lication)?\s*#?/i, ''))
      || `app-${request.id}`.toLowerCase().includes(query)
    return categoryMatches && searchMatches
    })
  }, [registrationRequests, paymentReviewRequests, applicationCategory, applicationSearch])

  const applicationCounts = useMemo(() => registrationRequests.reduce((counts, request) => {
    counts[classifyApplication(request)] += 1
    return counts
  }, { registrations: 0, renewals: 0, upgrades: 0 }), [registrationRequests])

  const applicationPageCount = Math.max(1, Math.ceil(filteredRegistrationRequests.length / APPLICATIONS_PER_PAGE))
  const currentApplicationPage = Math.min(applicationPage, applicationPageCount)
  const visibleApplications = filteredRegistrationRequests.slice(
    (currentApplicationPage - 1) * APPLICATIONS_PER_PAGE,
    currentApplicationPage * APPLICATIONS_PER_PAGE
  )
  const selectedApplication = [...registrationRequests, ...paymentReviewRequests]
    .find((request) => Number(request.id) === Number(selectedApplicationId)) || null
  const selectedIsInitialDailyPass = Number(selectedApplication?.is_initial_daily_pass_approval) === 1
  const selectedIsInitialMonthly = selectedApplication ? isInitialMonthlyRegistration(selectedApplication) : false
  const selectedApprovalPaymentStatus = String(
    selectedIsInitialDailyPass
      ? selectedApplication?.initial_pass_payment_status
      : selectedApplication?.payment_status
  || '').toLowerCase()
  const selectedApprovalPaymentMethod = String(
    selectedIsInitialDailyPass
      ? selectedApplication?.initial_pass_payment_method
      : selectedApplication?.payment_method
  || '').toLowerCase()
  const selectedApprovalProofAvailable = selectedIsInitialDailyPass
    ? Boolean(selectedApplication?.initial_pass_proof_name)
    : Boolean(selectedApplication?.payment_file_id)
  const selectedNeedsCombinedPayment = (selectedIsInitialDailyPass || selectedIsInitialMonthly)
    && !['paid', 'verified'].includes(selectedApprovalPaymentStatus)
  const selectedCanApprove = !selectedNeedsCombinedPayment
    || (selectedApprovalPaymentMethod === 'cash' && ['pending', 'unpaid'].includes(selectedApprovalPaymentStatus))
    || (selectedApprovalPaymentMethod === 'gcash'
      && ['pending', 'submitted'].includes(selectedApprovalPaymentStatus)
      && selectedApprovalProofAvailable)

  const confirmApplicationAction = async () => {
    if (!applicationConfirmation) return
    const { requestId, action, documentType, decision } = applicationConfirmation
    if (documentType === 'discount_proof') {
      const note = proofReviewNotes[`${requestId}-${documentType}`] || ''
      await reviewRegistrationProof(requestId, documentType, decision, note)
    } else if (documentType === 'payment_proof') {
      const note = proofReviewNotes[`${requestId}-payment_proof`] || ''
      await reviewRegistrationPaymentProof(requestId, decision, note)
    } else if (action === 'confirm_cash_payment') {
      await confirmRegistrationCashPayment(requestId)
    } else {
      const selectedRequest = registrationRequests.find((request) => Number(request.id) === Number(requestId))
      const isInitialDailyPass = Number(selectedRequest?.is_initial_daily_pass_approval) === 1
      const isInitialMonthly = isInitialMonthlyRegistration(selectedRequest || {})
      const paymentStatus = isInitialDailyPass
        ? String(selectedRequest?.initial_pass_payment_status || '').toLowerCase()
        : String(selectedRequest?.payment_status || '').toLowerCase()
      const confirmPayment = action === 'approve'
        && (isInitialDailyPass || isInitialMonthly)
        && !['paid', 'verified'].includes(paymentStatus)
      await handleRequestAction(requestId, action, action === 'reject' ? applicationRejectReason : '', confirmPayment)
    }
    setApplicationConfirmation(null)
  }

  const changeTab = (tab) => {
    const next = new URLSearchParams(searchParams)
    next.set('tab', tab)
    if (tab !== 'applications') next.delete('category')
    setSearchParams(next)
  }

  const changeApplicationCategory = (category) => {
    const next = new URLSearchParams(searchParams)
    next.set('tab', 'applications')
    if (category === 'all') next.delete('category')
    else next.set('category', category)
    setSearchParams(next)
  }

  const openAddMember = () => {
    setIsWalkInRegistrationOpen(true)
  }

  const openEditMember = (member) => {
    setSelectedMember(member)
    setMembershipAccessHistory([])
    setMembershipAccessHistoryError('')
    setMembershipAccessHistoryLoading(true)
    api.get(`members.php?action=membership-access-history&id=${member.id}`)
      .then((response) => {
        if (!response.data?.success) {
          throw new Error(response.data?.message || 'Unable to load membership history.')
        }
        setMembershipAccessHistory(response.data.history || [])
      })
      .catch((requestError) => {
        setMembershipAccessHistoryError(requestError?.response?.data?.message || requestError?.message || 'Unable to load membership history.')
      })
      .finally(() => setMembershipAccessHistoryLoading(false))
    setFormData({
      firstName: member.firstName || '',
      middleName: member.middleName || '',
      lastName: member.lastName || '',
      email: member.email || '',
      phone: member.phone || '',
      address: member.address || '',
      dob: member.dob || '',
      membershipType: member.membershipTypeKey,
      membershipStart: member.membershipStart || '',
      membershipEnd: member.membershipEnd || '',
      memberCategory: member.memberCategory || 'regular',
      gymAccessControlStatus: member.gymAccessControlStatus || 'allowed',
      accessChangeReason: '',
      status: member.gymAccessStatus || 'No Valid Gym Access',
      accountStatus: member.accountStatus || 'Active',
      password: '',
    })
    setEditMode(true)
    setIsAddOpen(true)
  }

  const openViewMember = (member) => {
    setViewMember(member)
  }

  const handleSaveMembershipAccess = async (member, changes) => {
    const response = await api.post('members.php?action=membership-access', {
      id: member.id,
      ...changes,
    })
    if (!response.data?.success) {
      throw new Error(response.data?.message || 'Unable to update membership and gym access.')
    }

    const refreshedMembers = await fetchMemberRows()
    setMembers(refreshedMembers)
    const refreshedMember = refreshedMembers.find((row) => row.id === member.id) || member
    setSelectedMember(refreshedMember)
    setFormData((current) => ({
      ...current,
      memberCategory: refreshedMember.memberCategory,
      gymAccessControlStatus: refreshedMember.gymAccessControlStatus,
      membershipStart: refreshedMember.membershipStart,
      membershipEnd: refreshedMember.membershipEnd,
    }))
    try {
      const historyResponse = await api.get(`members.php?action=membership-access-history&id=${member.id}`)
      if (!historyResponse.data?.success) {
        throw new Error(historyResponse.data?.message || 'Unable to refresh membership history.')
      }
      setMembershipAccessHistory(historyResponse.data.history || [])
      setMembershipAccessHistoryError('')
    } catch (requestError) {
      setMembershipAccessHistoryError(requestError?.response?.data?.message || requestError?.message || 'Unable to refresh membership history.')
    }
  }

  const handleMemberPictureUpdated = (profilePicture) => {
    setMembers((current) => current.map((member) => (
      member.id === viewMember?.id ? { ...member, profilePicture } : member
    )))
    setViewMember((current) => current ? { ...current, profilePicture } : current)
  }

  const closeModal = () => {
    setIsAddOpen(false)
    setSelectedMember(null)
    setViewMember(null)
    setMembershipAccessHistory([])
    setMembershipAccessHistoryError('')
    setError('')
  }

  const handleFormChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
  }

  const handleSaveMember = async () => {
    setError('')
    setSuccess('')

    const normalizedMembershipType = normalizeMembershipType(formData.membershipType)
    const payload = {
      first_name: formData.firstName.trim(),
      middle_name: formData.middleName ? formData.middleName.trim() : '',
      last_name: formData.lastName.trim(),
      email: formData.email,
      phone: formData.phone,
      address: formData.address,
      date_of_birth: formData.dob,
      membership_type: normalizedMembershipType,
      membership_start: normalizedMembershipType === 'walk-in' ? '' : formData.membershipStart,
      membership_end: normalizedMembershipType === 'walk-in' ? '' : formData.membershipEnd,
    }

    if (!editMode) {
      payload.password = formData.password
    }

    try {
      if (editMode && selectedMember) {
        const res = await api.post('members.php?action=update', {
          id: selectedMember.id,
          ...payload,
        })
        
        if (res?.data?.success) {
          await refreshMembers()
          setIsAddOpen(false)
          setSelectedMember(null)
          setSuccess('Member details updated successfully.')
        } else {
          setError(res?.data?.message || 'Unable to update member. Please try again.')
        }
      } else {
        const res = await api.post('members.php?action=create', payload)
        
        if (res?.data?.success) {
          await refreshMembers()
          setIsAddOpen(false)
          setSelectedMember(null)
          setSuccess('Member added successfully.')
        } else {
          setError(res?.data?.message || 'Unable to add member. Please try again.')
        }
      }
    } catch (err) {
      const msg = err?.response?.data?.message || err?.message || 'Unable to save member. Please try again.'
      setError(msg)
    }
  }

  const handleChangeMemberStatus = async (member, status) => {
    setStatusConfirmMember(member)
    setNewStatus(status)
  }

  const handleConfirmStatusChange = async () => {
    if (!statusConfirmMember) return
    setError('')

    try {
      const response = await api.post('members.php?action=account-status', {
        id: statusConfirmMember.id,
        account_status: newStatus,
      })
      if (!response?.data?.success) throw new Error(response?.data?.message || 'Unable to update member account status.')
      await refreshMembers()
      setSuccess(response.data.message || `Member account ${newStatus === 'active' ? 'reactivated' : 'deactivated'} successfully.`)
      setStatusConfirmMember(null)
      setNewStatus('')
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Unable to update member account status. Please try again.')
    }
  }

  const handleResetPassword = (member) => {
    setResetMember(member)
  }

  const handleConfirmReset = async (newPwd) => {
    if (!resetMember || resetPasswordLoading) return
    setResetPasswordLoading(true)
    try {
      const response = await api.post('admin_reset_password.php', { email: resetMember.email, password: newPwd })
      if (!response?.data?.success) throw new Error('Password reset was not confirmed.')
      showSuccess(response.data.message || 'Password updated successfully.')
      setResetMember(null)
    } catch (requestError) {
      showRequestError(requestError, 'Unable to reset the password. Please try again.', 'Password reset failed')
    } finally {
      setResetPasswordLoading(false)
    }
  }

  const handleCloseReset = () => setResetMember(null)

  return (
    <div className="min-h-screen min-w-0 bg-slate-100 text-slate-900">
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-screen-2xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-6">
          <div className="flex min-w-0 items-center gap-3 sm:gap-4">
            <button
              onClick={() => navigate('/dashboard')}
              className="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-slate-700 transition hover:bg-slate-50 hover:text-slate-900"
              title="Back to Dashboard"
            >
              <FaArrowLeft />
            </button>
            <div className="min-w-0">
              <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Admin Dashboard</p>
              <h1 className="mt-1 break-words text-2xl font-semibold text-slate-900 sm:text-3xl">Member Management</h1>
            </div>
          </div>
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
            <button
              onClick={openAddMember}
              className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-violet-300/40 transition hover:bg-violet-700 sm:w-auto"
            >
              <FaPlus /> New Member Registration
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto min-w-0 max-w-screen-2xl px-4 py-6 sm:px-6 sm:py-8 lg:px-6">
        {/* Statistics Cards */}
        <section className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                <FaUsers />
              </span>
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Total Members</p>
                <p className="mt-2 text-2xl font-semibold text-slate-900">{stats.total}</p>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                <FaUserCheck />
              </span>
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Active Accounts</p>
                <p className="mt-2 text-2xl font-semibold text-slate-900">{stats.active}</p>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                <FaCalendarAlt />
              </span>
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Monthly Subscribers</p>
                <p className="mt-2 text-2xl font-semibold text-slate-900">{stats.monthly}</p>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                <FaDoorOpen />
              </span>
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">1 Day Pass Accounts</p>
                  <p className="mt-2 text-2xl font-semibold text-slate-900">{stats.dayPassAccounts}</p>
              </div>
            </div>
          </div>
        </section>

        <div role="tablist" aria-label="Member management sections" className="mb-6 flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
          {[
            { id: 'members', label: 'Members', badge: null },
            { id: 'applications', label: 'Applications', badge: registrationRequests.length },
            { id: 'daily-passes', label: 'Daily Passes', badge: null },
            { id: 'booking-payments', label: 'Booking Payments', badge: bookingPaymentsPendingCount },
          ].map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={activeTab === tab.id}
              onClick={() => changeTab(tab.id)}
              className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition inline-flex items-center gap-2 ${activeTab === tab.id ? 'bg-violet-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}
            >
              <span>{tab.label}</span>
              {tab.badge !== null && tab.badge > 0 && (
                <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                  activeTab === tab.id ? 'bg-white/20 text-white' : 'bg-violet-100 text-violet-700'
                }`}>
                  {tab.badge}
                </span>
              )}
            </button>
          ))}
        </div>

        {activeTab === 'members' && (
          <>
        {/* Search and Filters */}
        <section className="mb-5 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,auto))]">
            <label className="relative min-w-0">
              <span className="sr-only">Search members</span>
              <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm" />
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search by name, code, email, or phone"
                className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
              />
            </label>
            <label className="min-w-0 space-y-1">
              <span className="sr-only">Filter by gym access status</span>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="h-[42px] w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
              >
                <option value="all">All Gym Access</option>
                <option value="restricted">Restricted</option>
                <option value="activemonthlysubscription">Active Monthly Subscription</option>
                <option value="valid1daypass">Valid 1 Day Pass</option>
                <option value="expired1daypass">Expired 1 Day Pass</option>
                <option value="novalidgymaccess">No Valid Gym Access</option>
                <option value="pendingpaymentorapproval">Pending Payment or Approval</option>
              </select>
            </label>
            <label className="min-w-0 space-y-1">
              <span className="sr-only">Filter by account status</span>
              <select
                value={accountStatusFilter}
                onChange={(e) => setAccountStatusFilter(e.target.value)}
                className="h-[42px] w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
              >
                {accountStatusOptions.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </label>
            <label className="min-w-0 space-y-1">
              <span className="sr-only">Filter by membership type</span>
              <select
                value={typeFilter}
                onChange={(e) => setTypeFilter(e.target.value)}
                className="h-[42px] w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-900 outline-none transition focus:border-violet-400 focus:ring-1 focus:ring-violet-400"
              >
                {membershipTypeOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
          </div>
        </section>

        {/* Error Message */}
        {error && (
          <div className="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            {error}
          </div>
        )}
        {success && (
          <div role="status" className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            {success}
          </div>
        )}

        {/* Members Table */}
        <section className="mb-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-4 py-3.5 sm:px-5">
            <h2 className="text-sm font-semibold text-slate-900">
              {filteredMembers.length} {filteredMembers.length === 1 ? 'Member' : 'Members'} Found
            </h2>
          </div>
          {loading ? (
            <div className="px-5 py-12 text-center text-sm text-slate-500">
              Loading members...
            </div>
          ) : (
            <MembersTable
              members={filteredMembers}
              onView={openViewMember}
              onEdit={openEditMember}
              onPromote={setPromotionMember}
              onStatusChange={handleChangeMemberStatus}
              onResetPassword={handleResetPassword}
            />
          )}
        </section>

        <section className="flex flex-col gap-3 rounded-2xl border border-cyan-100 bg-cyan-50/60 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">GCash collection details</h2>
            <p className="mt-1 text-sm text-slate-600">Manage the payment details shown to members in Admin Settings.</p>
          </div>
          <Link to="/admin/settings?section=payments" className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900">
            Open Payment Methods
          </Link>
        </section>
          </>
        )}

        {activeTab === 'applications' && (
          <div className="space-y-5">
            <section className="grid gap-3 sm:grid-cols-3">
              <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">New Registrations</p>
                <p className="mt-2 text-2xl font-bold text-slate-900">{requestsLoading ? '…' : applicationCounts.registrations}</p>
                <p className="mt-1 text-xs text-slate-500">Awaiting account review</p>
              </article>
              <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending Renewals</p>
                <p className="mt-2 text-2xl font-bold text-slate-900">{requestsLoading ? '…' : applicationCounts.renewals}</p>
                <p className="mt-1 text-xs text-slate-500">Existing monthly members</p>
              </article>
              <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending Upgrades</p>
                <p className="mt-2 text-2xl font-bold text-slate-900">{requestsLoading ? '…' : applicationCounts.upgrades}</p>
                <p className="mt-1 text-xs text-slate-500">Existing non-monthly members</p>
              </article>
            </section>

            <div className="flex flex-wrap gap-2" aria-label="Application status views">
              <button type="button" aria-pressed={applicationListMode === 'pending' && applicationCategory !== 'payment-reviews'} onClick={() => { setApplicationListMode('pending'); changeApplicationCategory('all') }} className={`rounded-full px-4 py-2 text-xs font-semibold ${applicationListMode === 'pending' && applicationCategory !== 'payment-reviews' ? 'bg-sky-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}>Pending applications ({registrationRequests.length})</button>
              <button type="button" aria-pressed={applicationListMode === 'pending' && applicationCategory === 'payment-reviews'} onClick={() => { setApplicationListMode('pending'); changeApplicationCategory('payment-reviews') }} className={`rounded-full px-4 py-2 text-xs font-semibold ${applicationCategory === 'payment-reviews' ? 'bg-sky-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}>Payment reviews ({paymentReviewRequests.length})</button>
              {canReviewDuplicateNameExceptions && <button type="button" aria-pressed={applicationListMode === 'duplicate-name-exceptions'} onClick={() => setApplicationListMode('duplicate-name-exceptions')} className={`rounded-full px-4 py-2 text-xs font-semibold ${applicationListMode === 'duplicate-name-exceptions' ? 'bg-sky-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}>Duplicate-name exceptions</button>}
              <button type="button" aria-pressed={applicationListMode === 'history'} onClick={() => { setApplicationListMode('history'); refreshApplicationHistory() }} className={`rounded-full px-4 py-2 text-xs font-semibold ${applicationListMode === 'history' ? 'bg-sky-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}>Approved / rejected history</button>
            </div>

            {applicationListMode === 'pending' && (
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
              <header className="space-y-4 border-b border-slate-200 p-4 sm:p-5">
                <div>
                  <h2 className="text-base font-semibold text-slate-900">{applicationCategory === 'payment-reviews' ? 'Existing Member Payment Reviews' : 'Membership Applications'}</h2>
                  <p className="mt-1 text-sm text-slate-500">{applicationCategory === 'payment-reviews' ? 'Review payments for existing members here. New account approval and its initial payment confirmation are handled together in New Registrations.' : 'Find an application, then review and process it in one panel.'}</p>
                </div>
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                  <div className="flex flex-wrap gap-2" aria-label="Filter applications">
                    {[
                      { id: 'all', label: 'All Requests', count: registrationRequests.length },
                      { id: 'registrations', label: 'New Registrations', count: applicationCounts.registrations },
                      { id: 'renewals', label: 'Renewals', count: applicationCounts.renewals },
                      { id: 'upgrades', label: 'Upgrades', count: applicationCounts.upgrades },
                      { id: 'payment-reviews', label: 'Payment Reviews', count: paymentReviewRequests.length },
                    ].map((category) => (
                      <button key={category.id} type="button" onClick={() => changeApplicationCategory(category.id)} className={`rounded-full px-3 py-2 text-xs font-semibold transition ${applicationCategory === category.id ? 'bg-sky-100 text-sky-800 ring-1 ring-sky-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                        {category.label} <span className="ml-1 opacity-70">{category.count}</span>
                      </button>
                    ))}
                  </div>
                  <label className="relative w-full lg:max-w-sm">
                    <span className="sr-only">Search applications</span>
                    <FaSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" />
                    <input type="search" value={applicationSearch} onChange={(event) => { setApplicationSearch(event.target.value); setApplicationPage(1) }} placeholder="Search name, Member ID, or Application ID" className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-sky-400 focus:ring-1 focus:ring-sky-400" />
                  </label>
                </div>
              </header>

              {requestsError && <div className="m-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"><p role="alert">{requestsError}</p><button type="button" onClick={refreshRegistrationRequests} className="mt-2 font-semibold underline">Retry</button></div>}
              {requestsLoading ? (
                <div className="px-5 py-12 text-center text-sm text-slate-500">Loading applications…</div>
              ) : filteredRegistrationRequests.length === 0 ? (
                <div className="px-5 py-12 text-center text-sm text-slate-500">{registrationRequests.length ? 'No applications match this search and category.' : 'No pending applications at the moment.'}</div>
              ) : (
                <>
                  <div className="hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[850px] text-left text-sm">
                      <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                        <tr>
                          <th className="px-4 py-3">Member</th><th className="px-4 py-3">Application</th><th className="px-4 py-3">Membership</th>
                          <th className="px-4 py-3">Amount</th><th className="px-4 py-3">Payment</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Submitted</th><th className="px-4 py-3 text-right">Action</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {visibleApplications.map((request) => (
                          <tr key={request.id} className="align-middle hover:bg-slate-50/70">
                            <td className="max-w-[200px] px-4 py-3"><p className="truncate font-semibold text-slate-900">{request.full_name || `${request.first_name || ''} ${request.last_name || ''}`.trim()}</p><p className="mt-1 text-[11px] text-slate-500">{request.member_id ? `Member #${request.member_id}` : request.email}</p></td>
                            <td className="px-4 py-3"><ApplicationStatusBadge status="pending" label={applicationTypeLabel(request)} /><p className="mt-1 text-[11px] text-slate-500">App #{request.id}</p></td>
                            <td className="max-w-[180px] px-4 py-3"><p className="truncate text-xs font-medium text-slate-800">{request.member_id && classifyApplication(request) === 'upgrades' ? `${String(request.current_member_category || 'regular').toUpperCase()} → ` : ''}Monthly · {String(request.member_category || 'regular').toUpperCase()}</p><p className="mt-1 text-[11px] text-slate-500">{request.requested_duration_months ? `${request.requested_duration_months} month${Number(request.requested_duration_months) === 1 ? '' : 's'}` : request.plan_name || 'Duration unavailable'}</p></td>
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-700">{request.payment_amount ? `₱${Number(request.payment_amount).toFixed(2)}` : '—'}</td>
                            <td className="px-4 py-3"><ApplicationStatusBadge status={request.payment_status} label={applicationPaymentLabel(request.payment_status)} /></td>
                            <td className="px-4 py-3"><ApplicationStatusBadge status={request.status} /></td>
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{formatApplicationDate(request.created_at)}</td>
                            <td className="px-4 py-3 text-right"><button type="button" onClick={() => setSelectedApplicationId(Number(request.id))} className="whitespace-nowrap rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800">Review Application</button></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="divide-y divide-slate-100 md:hidden">
                    {visibleApplications.map((request) => (
                      <article key={request.id} className="space-y-3 p-4">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0"><p className="truncate font-semibold text-slate-900">{request.full_name || `${request.first_name || ''} ${request.last_name || ''}`.trim()}</p><p className="mt-1 text-xs text-slate-500">{request.member_id ? `Member #${request.member_id}` : request.email}</p></div>
                          <ApplicationStatusBadge status={request.status} />
                        </div>
                        <div className="flex flex-wrap items-center gap-2"><ApplicationStatusBadge status="pending" label={applicationTypeLabel(request)} /><span className="text-xs text-slate-500">App #{request.id}</span></div>
                        <div className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-xs">
                          <div><p className="text-slate-500">Requested membership</p><p className="mt-1 font-medium text-slate-800">Monthly · {String(request.member_category || 'regular').toUpperCase()} · {request.requested_duration_months ? `${request.requested_duration_months} mo.` : 'duration unavailable'}</p></div>
                          <div><p className="text-slate-500">Payment</p><div className="mt-1"><ApplicationStatusBadge status={request.payment_status} label={applicationPaymentLabel(request.payment_status)} /></div></div>
                        </div>
                        <div className="flex items-center justify-between gap-3"><span className="text-xs text-slate-500">{formatApplicationDate(request.created_at)} · {request.payment_amount ? `₱${Number(request.payment_amount).toFixed(2)}` : 'Amount unavailable'}</span><button type="button" onClick={() => setSelectedApplicationId(Number(request.id))} className="shrink-0 rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800">Review</button></div>
                      </article>
                    ))}
                  </div>
                  <footer className="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <p className="text-xs text-slate-500">Showing {(currentApplicationPage - 1) * APPLICATIONS_PER_PAGE + 1}–{Math.min(currentApplicationPage * APPLICATIONS_PER_PAGE, filteredRegistrationRequests.length)} of {filteredRegistrationRequests.length}</p>
                    <div className="flex items-center justify-end gap-2">
                      <button type="button" disabled={currentApplicationPage <= 1} onClick={() => setApplicationPage((current) => current - 1)} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40"><FaChevronLeft /> Previous</button>
                      <span className="px-2 text-xs text-slate-600">Page {currentApplicationPage} of {applicationPageCount}</span>
                      <button type="button" disabled={currentApplicationPage >= applicationPageCount} onClick={() => setApplicationPage((current) => current + 1)} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-40">Next <FaChevronRight /></button>
                    </div>
                  </footer>
                </>
              )}
            </section>
            )}

            {applicationListMode === 'duplicate-name-exceptions' && canReviewDuplicateNameExceptions && (
              <DuplicateNameExceptionReviews />
            )}

            {applicationListMode === 'history' && (
              <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header className="border-b border-slate-200 p-4 sm:p-5">
                  <h2 className="text-base font-semibold text-slate-900">Processed Application History</h2>
                  <p className="mt-1 text-sm text-slate-500">Approved and rejected applications remain available here for reference.</p>
                </header>
                {historyError && <div role="alert" className="m-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"><p>{historyError}</p><button type="button" onClick={refreshApplicationHistory} className="mt-2 font-semibold underline">Retry</button></div>}
                {historyLoading ? (
                  <p className="px-5 py-12 text-center text-sm text-slate-500">Loading application history…</p>
                ) : applicationHistory.length === 0 ? (
                  <p className="px-5 py-12 text-center text-sm text-slate-500">No approved or rejected applications yet.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[760px] text-left text-sm">
                      <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                        <tr><th className="px-4 py-3">Member / Application</th><th className="px-4 py-3">Membership</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Submitted</th><th className="px-4 py-3">Reviewed</th><th className="px-4 py-3">Decision note</th></tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {applicationHistory.map((request) => (
                          <tr key={request.id} className="align-top text-slate-700">
                            <td className="px-4 py-3"><p className="font-semibold">{request.full_name || `${request.first_name || ''} ${request.last_name || ''}`.trim()}</p><p className="mt-1 text-xs text-slate-500">App #{request.id}{request.member_id ? ` · Member #${request.member_id}` : ''}</p></td>
                            <td className="px-4 py-3">{Number(request.is_initial_daily_pass_approval) === 1 ? '1 Day Pass' : formatMembershipType(request.membership_type)}{request.member_category ? ` · ${request.member_category}` : ''}</td>
                            <td className="px-4 py-3"><ApplicationStatusBadge status={request.status} /></td>
                            <td className="px-4 py-3 text-xs text-slate-500">{formatApplicationDate(request.created_at)}</td>
                            <td className="px-4 py-3 text-xs text-slate-500">{formatApplicationDate(request.reviewed_at)}</td>
                            <td className="max-w-sm whitespace-pre-wrap px-4 py-3 text-xs text-slate-600">{request.review_note || '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </section>
            )}
          </div>
        )}

        {activeTab === 'daily-passes' && <DailyPasses embedded />}
        {activeTab === 'booking-payments' && (
          <BookingPaymentsTab
            onCountChange={(count) => setBookingPaymentsPendingCount(count)}
          />
        )}
      </main>

      {selectedApplication && (
        <div role="presentation" className="fixed inset-0 z-[60] bg-slate-950/45" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !requestActionLoadingId && !proofReviewLoadingKey) {
            setSelectedApplicationId(null)
            setRequestsError('')
          }
        }}>
          <aside role="dialog" aria-modal="true" aria-labelledby="application-review-title" className="ml-auto flex h-full w-full max-w-3xl flex-col bg-slate-50 shadow-2xl">
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-7">
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-wider text-sky-700">{applicationTypeLabel(selectedApplication)} · App #{selectedApplication.id}</p>
                <h2 id="application-review-title" className="mt-1 truncate text-xl font-bold text-slate-900">{selectedApplication.full_name}</h2>
                <p className="mt-1 text-xs text-slate-500">Submitted {formatApplicationDate(selectedApplication.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</p>
              </div>
              <button type="button" disabled={Boolean(requestActionLoadingId || proofReviewLoadingKey)} onClick={() => { setSelectedApplicationId(null); setRequestsError('') }} aria-label="Close application review" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-50"><FaTimes /></button>
            </header>

            {requestsError && <p role="alert" className="mx-5 mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 sm:mx-7">{requestsError}</p>}
            {success && <p role="status" className="mx-5 mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 sm:mx-7">{success}</p>}

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 sm:p-7">
              <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 className="text-sm font-semibold text-slate-900">Application review summary</h3>
                <div className="mt-3 grid gap-3 sm:grid-cols-3">
                  <div><p className="mb-1.5 text-[11px] font-medium text-slate-500">Application status</p><ApplicationStatusBadge status={selectedApplication.status} /></div>
                  <div><p className="mb-1.5 text-[11px] font-medium text-slate-500">{Number(selectedApplication.is_initial_daily_pass_approval) === 1 ? 'Daily Pass payment status' : 'Payment status'}</p><ApplicationStatusBadge status={Number(selectedApplication.is_initial_daily_pass_approval) === 1 ? selectedApplication.initial_pass_payment_status : selectedApplication.payment_status} label={applicationPaymentLabel(Number(selectedApplication.is_initial_daily_pass_approval) === 1 ? selectedApplication.initial_pass_payment_status : selectedApplication.payment_status)} /></div>
                  <div><p className="mb-1.5 text-[11px] font-medium text-slate-500">Promo document status</p><ApplicationStatusBadge status={selectedApplication.member_category === 'regular' ? 'not_required' : selectedApplication.discount_status} label={selectedApplication.member_category === 'regular' ? 'Not required' : applicationPaymentLabel(selectedApplication.discount_status)} /></div>
                </div>
                {selectedApplication.review_note && <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{selectedApplication.review_note}</p>}
              </section>

              <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 className="text-sm font-semibold text-slate-900">Applicant information</h3>
                <dl className="mt-2 grid gap-x-5 sm:grid-cols-2">
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Full name</dt><dd className="mt-1 text-sm font-medium text-slate-800">{selectedApplication.full_name || `${selectedApplication.first_name || ''} ${selectedApplication.middle_name || ''} ${selectedApplication.last_name || ''}`.trim()}</dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Member ID</dt><dd className="mt-1 text-sm font-medium text-slate-800">{selectedApplication.member_id ? `#${selectedApplication.member_id}` : 'New applicant'}</dd></div>
                  <div className="break-all py-2"><dt className="text-[11px] text-slate-500">Email</dt><dd className="mt-1 text-sm text-slate-800">{selectedApplication.email || '—'}</dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Phone</dt><dd className="mt-1 text-sm text-slate-800">{selectedApplication.phone || '—'}</dd></div>
                  {selectedApplication.member_id && <div className="py-2 sm:col-span-2"><dt className="text-[11px] text-slate-500">Account registration date</dt><dd className="mt-1 text-sm text-slate-800">{formatApplicationDate(selectedApplication.member_created_at)}</dd></div>}
                </dl>
              </section>

              <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 className="text-sm font-semibold text-slate-900">Membership information</h3>
                <dl className="mt-2 grid gap-x-5 sm:grid-cols-2">
                  {selectedApplication.member_id && <div className="py-2"><dt className="text-[11px] text-slate-500">Current membership</dt><dd className="mt-1 text-sm font-medium text-slate-800">{formatMembershipType(selectedApplication.current_membership_type)} · {selectedApplication.current_member_category || 'regular'}</dd></div>}
                  {Number(selectedApplication.is_initial_daily_pass_approval) === 1 ? (
                    <div className="py-2 sm:col-span-2"><dt className="text-[11px] text-slate-500">Requested account action</dt><dd className="mt-1 text-sm font-medium text-slate-800">Approve initial Daily Pass member account</dd><p className="mt-1 text-[11px] text-slate-500">This approval does not verify payment or grant gym entry; the existing Daily Pass payment and eligibility checks still apply.</p></div>
                  ) : (
                    <>
                      <div className="py-2"><dt className="text-[11px] text-slate-500">{classifyApplication(selectedApplication) === 'renewals' ? 'Renewal membership' : 'Requested membership'}</dt><dd className="mt-1 text-sm font-medium text-slate-800">Monthly Subscription · {selectedApplication.member_category || 'regular'}</dd></div>
                      <div className="py-2"><dt className="text-[11px] text-slate-500">Requested duration</dt><dd className="mt-1 text-sm text-slate-800">{selectedApplication.requested_duration_months ? `${selectedApplication.requested_duration_months} month${Number(selectedApplication.requested_duration_months) === 1 ? '' : 's'}` : selectedApplication.plan_name || 'Unavailable'}</dd></div>
                      <div className="py-2"><dt className="text-[11px] text-slate-500">{classifyApplication(selectedApplication) === 'upgrades' ? 'Upgrade amount' : 'Membership price'}</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{selectedApplication.payment_amount ? `₱${Number(selectedApplication.payment_amount).toFixed(2)}` : 'Unavailable'}</dd></div>
                      {selectedApplication.member_id && <div className="py-2 sm:col-span-2"><dt className="text-[11px] text-slate-500">Existing subscription</dt><dd className="mt-1 text-sm text-slate-800">{formatApplicationDate(selectedApplication.current_membership_start)} – {formatApplicationDate(selectedApplication.current_membership_end)}</dd></div>}
                      <div className="py-2 sm:col-span-2"><dt className="text-[11px] text-slate-500">Requested subscription period</dt><dd className="mt-1 text-sm text-slate-800">{selectedApplication.requested_subscription_period?.starts_on && selectedApplication.requested_subscription_period?.ends_on ? `${formatApplicationDate(selectedApplication.requested_subscription_period.starts_on)} – ${formatApplicationDate(selectedApplication.requested_subscription_period.ends_on)}` : 'Assigned after payment verification and approval.'}</dd><p className="mt-1 text-[11px] text-slate-500">This is a preview only; the membership remains pending until all requirements are met.</p></div>
                    </>
                  )}
                  <div className="py-2 sm:col-span-2"><dt className="text-[11px] text-slate-500">Application date</dt><dd className="mt-1 text-sm text-slate-800">{formatApplicationDate(selectedApplication.created_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</dd></div>
                </dl>
              </section>

              <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                {(() => {
                  const isInitialDailyPass = Number(selectedApplication.is_initial_daily_pass_approval) === 1
                  const paymentStatus = isInitialDailyPass
                    ? selectedApplication.initial_pass_payment_status
                    : selectedApplication.payment_status
                  const paymentMethod = isInitialDailyPass
                    ? selectedApplication.initial_pass_payment_method
                    : selectedApplication.payment_method
                  const paymentAmount = isInitialDailyPass
                    ? selectedApplication.initial_pass_payment_amount
                    : selectedApplication.payment_amount
                  return (
                    <>
                <h3 className="text-sm font-semibold text-slate-900">Payment information</h3>
                <dl className="mt-2 grid gap-x-5 sm:grid-cols-2">
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Payment method</dt><dd className="mt-1 text-sm font-medium capitalize text-slate-800">{paymentMethod || '—'}</dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Amount</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{paymentAmount ? `₱${Number(paymentAmount).toFixed(2)}` : '—'}</dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Payment status</dt><dd className="mt-1"><ApplicationStatusBadge status={paymentStatus} label={applicationPaymentLabel(paymentStatus)} /></dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Verification date</dt><dd className="mt-1 text-sm text-slate-800">{formatApplicationDate(isInitialDailyPass ? selectedApplication.initial_pass_verified_at : selectedApplication.payment_verified_at, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</dd></div>
                  <div className="py-2"><dt className="text-[11px] text-slate-500">Verified by</dt><dd className="mt-1 text-sm text-slate-800">{isInitialDailyPass ? selectedApplication.initial_pass_verified_by_name || '—' : selectedApplication.payment_reviewer_name || '—'}</dd></div>
                  {paymentMethod === 'cash' && <div className="py-2 sm:col-span-2"><p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{paymentStatus === 'paid' || paymentStatus === 'verified' ? `Cash receipt confirmed${selectedApplication.payment_date ? ` on ${formatApplicationDate(selectedApplication.payment_date)}` : ''}. Gym-entry eligibility still follows the existing payment and membership rules.` : isInitialDailyPass || isInitialMonthlyRegistration(selectedApplication) ? 'Confirm cash receipt in the same action that approves this new account.' : 'Cash payment is pending staff confirmation. It is not treated as paid or active.'}</p></div>}
                </dl>
                {paymentMethod === 'gcash' && selectedApplication.payment_file_id && (
                  <div className="mt-3 border-t border-slate-100 pt-3">
                    <div className="flex items-center justify-between gap-3"><div><p className="text-xs font-semibold text-slate-700">GCash payment proof</p><p className="mt-1 text-xs text-slate-500">{selectedApplication.payment_file_status || 'pending'}{selectedApplication.payment_file_reviewed_at ? ` · reviewed ${formatApplicationDate(selectedApplication.payment_file_reviewed_at)}` : ''}</p></div>{selectedApplication.payment_file_status && <ApplicationStatusBadge status={selectedApplication.payment_file_status} />}</div>
                    {selectedApplication.payment_file_note && <p className="mt-2 text-xs text-rose-700">Review note: {selectedApplication.payment_file_note}</p>}
                    <ApplicationProofPreview key={`payment-${selectedApplication.id}-${selectedApplication.payment_file_id}`} fileId={selectedApplication.payment_file_id} fileName={selectedApplication.payment_file_name} onDownload={downloadRegistrationProof} />
                    {selectedApplication.member_id && ['pending', 'submitted', 'needs_correction'].includes(String(paymentStatus || '').toLowerCase()) && (
                      <div className="mt-3 space-y-3">
                        <label className="block text-xs font-medium text-slate-600">Payment review note <span className="font-normal text-slate-400">(required for correction or rejection)</span>
                          <textarea maxLength={500} rows={2} value={proofReviewNotes[`${selectedApplication.id}-payment_proof`] || ''} onChange={(event) => setProofReviewNotes((current) => ({ ...current, [`${selectedApplication.id}-payment_proof`]: event.target.value }))} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
                        </label>
                        <div className="flex flex-wrap gap-2">
                          <button type="button" disabled={Boolean(proofReviewLoadingKey) || isInitialMonthlyRegistration(selectedApplication)} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'payment_proof', decision: 'verified' })} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{isInitialMonthlyRegistration(selectedApplication) ? 'Verify with account approval' : 'Verify GCash payment'}</button>
                          <button type="button" disabled={Boolean(proofReviewLoadingKey) || !proofReviewNotes[`${selectedApplication.id}-payment_proof`]?.trim()} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'payment_proof', decision: 'needs_correction' })} className="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-800 disabled:opacity-50">Request correction</button>
                          <button type="button" disabled={Boolean(proofReviewLoadingKey) || !proofReviewNotes[`${selectedApplication.id}-payment_proof`]?.trim()} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'payment_proof', decision: 'rejected' })} className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 disabled:opacity-50">Reject payment proof</button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
                {isInitialDailyPass && selectedApplication.initial_pass_id && selectedApplication.initial_pass_proof_name && (
                  <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="text-xs font-semibold text-slate-700">Daily Pass GCash proof</p>
                    <ApplicationProofPreview key={`daily-pass-${selectedApplication.id}-${selectedApplication.initial_pass_id}`} fileId={selectedApplication.initial_pass_id} fileName={selectedApplication.initial_pass_proof_name} endpoint="daily_pass_proof.php" onDownload={downloadRegistrationProof} />
                  </div>
                )}
                {selectedApplication.member_id && paymentMethod === 'cash' && paymentStatus === 'pending' && (
                  <button type="button" disabled={Boolean(requestActionLoadingId || proofReviewLoadingKey)} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, action: 'confirm_cash_payment' })} className="mt-3 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Confirm Cash Received</button>
                )}
                    </>
                  )
                })()}
              </section>

              {selectedApplication.member_category !== 'regular' && (
                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="flex items-start justify-between gap-3">
                    <div><h3 className="text-sm font-semibold text-slate-900">Promo eligibility document</h3><p className="mt-1 text-xs text-slate-500">Fresh supporting documents are required for this request.</p></div>
                    <ApplicationStatusBadge status={selectedApplication.discount_status} label={applicationPaymentLabel(selectedApplication.discount_status)} />
                  </div>
                  {selectedApplication.discount_file_id ? (
                    <>
                      {selectedApplication.discount_file_note && <p className="mt-3 text-xs text-rose-700">Review note: {selectedApplication.discount_file_note}</p>}
                      {(selectedApplication.discount_file_reviewed_at || selectedApplication.discount_reviewer_name) && <p className="mt-1 text-[11px] text-slate-500">{selectedApplication.discount_file_reviewed_at ? `Reviewed ${formatApplicationDate(selectedApplication.discount_file_reviewed_at)}` : ''}{selectedApplication.discount_reviewer_name ? ` by ${selectedApplication.discount_reviewer_name}` : ''}</p>}
                      <ApplicationProofPreview key={`discount-${selectedApplication.id}-${selectedApplication.discount_file_id}`} fileId={selectedApplication.discount_file_id} fileName={selectedApplication.discount_file_name} onDownload={downloadRegistrationProof} />
                      {selectedApplication.discount_file_status !== 'verified' && (
                        <label className="mt-3 block text-xs font-medium text-slate-600">Review note <span className="font-normal text-slate-400">(required for correction or rejection)</span>
                          <textarea maxLength={500} rows={2} value={proofReviewNotes[`${selectedApplication.id}-discount_proof`] || ''} onChange={(event) => setProofReviewNotes((current) => ({ ...current, [`${selectedApplication.id}-discount_proof`]: event.target.value }))} className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-sky-400" />
                        </label>
                      )}
                    </>
                  ) : <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Required eligibility document has not been submitted.</p>}
                </section>
              )}

              {selectedApplication.review_note && <p className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700"><span className="font-semibold">Application review note:</span> {selectedApplication.review_note}</p>}

              <section className="rounded-2xl border border-rose-200 bg-white p-4 shadow-sm">
                <label className="block text-xs font-semibold text-slate-700">Application rejection reason <span className="font-normal text-slate-500">(required)</span>
                  <textarea maxLength={500} rows={2} value={applicationRejectReason} onChange={(event) => setApplicationRejectReason(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-rose-400" />
                </label>
              </section>
            </div>

            <footer className="flex flex-col gap-2 border-t border-slate-200 bg-white p-4 sm:p-5">
              {selectedApplication.member_category !== 'regular' && selectedApplication.discount_file_id && selectedApplication.discount_file_status !== 'verified' && (
                <div className="flex flex-wrap gap-2 border-b border-slate-100 pb-3">
                  <button type="button" disabled={Boolean(proofReviewLoadingKey)} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'discount_proof', decision: 'verified' })} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Verify Promo document</button>
                  <button type="button" disabled={Boolean(proofReviewLoadingKey) || !proofReviewNotes[`${selectedApplication.id}-discount_proof`]?.trim()} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'discount_proof', decision: 'needs_correction' })} className="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-800 disabled:opacity-50">Request correction</button>
                  <button type="button" disabled={Boolean(proofReviewLoadingKey) || !proofReviewNotes[`${selectedApplication.id}-discount_proof`]?.trim()} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, documentType: 'discount_proof', decision: 'rejected' })} className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 disabled:opacity-50">Reject Promo proof</button>
                </div>
              )}
              <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" disabled={Boolean(requestActionLoadingId || proofReviewLoadingKey)} onClick={() => { setSelectedApplicationId(null); setRequestsError('') }} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Back to applications</button>
                {selectedApplication.status === 'pending' && (
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <button type="button" disabled={Boolean(requestActionLoadingId || proofReviewLoadingKey) || !selectedCanApprove || (selectedApplication.membership_type === 'monthly' && selectedApplication.member_category !== 'regular' && selectedApplication.discount_status !== 'verified')} title={!selectedCanApprove ? 'Confirm payment and inspect the submitted proof before approving.' : selectedApplication.membership_type === 'monthly' && selectedApplication.member_category !== 'regular' && selectedApplication.discount_status !== 'verified' ? 'Verify the required Promo document before approval.' : undefined} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, action: 'approve' })} className="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">{requestActionLoadingId === selectedApplication.id ? 'Processing…' : selectedNeedsCombinedPayment ? `${selectedApprovalPaymentMethod === 'gcash' ? 'Verify GCash' : 'Confirm Payment'} & Approve` : selectedIsInitialDailyPass ? 'Approve Account' : 'Approve Application'}</button>
                    <button type="button" disabled={Boolean(requestActionLoadingId || proofReviewLoadingKey) || !applicationRejectReason.trim()} onClick={() => setApplicationConfirmation({ requestId: selectedApplication.id, action: 'reject' })} className="rounded-xl border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40">Reject Application</button>
                  </div>
                )}
              </div>
            </footer>
          </aside>
        </div>
      )}

      {/* Modals */}
      <MemberFormModal
        key={`${isAddOpen ? 'open' : 'closed'}-${editMode ? selectedMember?.id ?? 'edit' : 'create'}`}
        open={isAddOpen}
        mode={editMode ? 'edit' : 'create'}
        formData={formData}
        onChange={handleFormChange}
        onClose={closeModal}
        onSave={handleSaveMember}
        onSaveMembershipAccess={(changes) => handleSaveMembershipAccess(selectedMember, changes)}
        accessHistory={membershipAccessHistory}
        accessHistoryLoading={membershipAccessHistoryLoading}
        accessHistoryError={membershipAccessHistoryError}
        error={error}
      />

      <WalkInRegistrationModal
        open={isWalkInRegistrationOpen}
        onClose={() => setIsWalkInRegistrationOpen(false)}
        onCreated={(registration) => {
          void refreshMembers()
          const membershipLabel = registration.membership_type === 'daily_pass'
            ? 'Daily Pass'
            : `Monthly ${({ regular: 'Regular', student: 'Student Promo', senior: 'Senior Promo', pwd: 'PWD Promo' })[registration.member_category] || 'Regular'}`
          const nextStep = registration.payment_method === 'cash'
            ? registration.membership_type === 'daily_pass'
              ? 'Cash is unpaid until staff confirms receipt in Daily Passes.'
              : 'Cash is pending until staff confirms receipt in Payment Management.'
            : registration.membership_type === 'daily_pass'
              ? 'The GCash receipt is pending verification in Daily Passes.'
              : 'The GCash receipt is pending verification in Payment Management.'
          setSuccess(
            `${registration.member_name} was created directly as Member #${registration.member_id}. `
            + `${membershipLabel} · ₱${Number(registration.amount).toFixed(2)}. ${nextStep}`
          )
        }}
      />

      <MemberDetailsModal
        key={`${viewMember?.id ?? 'none'}-${viewMember ? 'open' : 'closed'}`}
        open={Boolean(viewMember)}
        member={viewMember}
        onClose={closeModal}
        onPictureUpdated={handleMemberPictureUpdated}
      />
      
      <ResetPasswordModal 
        open={Boolean(resetMember)} 
        member={resetMember} 
        onClose={handleCloseReset} 
        onConfirm={handleConfirmReset} 
        loading={resetPasswordLoading}
      />

      {/* Confirmation Modals */}
      <ConfirmationModal
        open={Boolean(statusConfirmMember)}
        title={`${newStatus === 'active' ? 'Reactivate' : 'Deactivate'} Member Account?`}
        message={`Are you sure you want to ${newStatus === 'active' ? 'reactivate' : 'deactivate'} ${statusConfirmMember?.fullName}'s account? This will not renew or change the membership dates.`}
        confirmText={newStatus === 'active' ? 'Reactivate' : 'Deactivate'}
        variant={newStatus === 'active' ? 'default' : 'danger'}
        onConfirm={handleConfirmStatusChange}
        onCancel={() => {
          setStatusConfirmMember(null)
          setNewStatus('')
        }}
      />
      <ConfirmationModal
        open={Boolean(promotionMember)}
        title="Promote Member to Trainer?"
        message={`Are you sure you want to promote ${promotionMember?.fullName || 'this member'} to Trainer? Their existing account and historical records will be preserved. Their system permissions will change to Trainer, and they must log in again.`}
        confirmText="Promote to Trainer"
        variant="warning"
        loading={promotionLoading}
        loadingText="Promoting…"
        onConfirm={promoteMemberToTrainer}
        onCancel={() => {
          if (!promotionLoading) setPromotionMember(null)
        }}
      />
      <ConfirmationModal
        open={Boolean(applicationConfirmation)}
        title={applicationConfirmation?.documentType
          ? `${applicationConfirmation.decision === 'verified' ? 'Verify' : applicationConfirmation.decision === 'needs_correction' ? 'Request correction for' : 'Reject'} ${applicationConfirmation.documentType === 'discount_proof' ? 'Promo document' : 'payment proof'}?`
          : applicationConfirmation?.action === 'approve' ? selectedNeedsCombinedPayment
            ? `${selectedApprovalPaymentMethod === 'gcash' ? 'Verify GCash payment and approve' : 'Confirm cash payment and approve'}?`
            : Number(selectedApplication?.is_initial_daily_pass_approval) === 1 ? 'Approve Daily Pass account registration?'
            : 'Approve membership application?'
            : applicationConfirmation?.action === 'reject' ? Number(selectedApplication?.is_initial_daily_pass_approval) === 1 ? 'Reject Daily Pass account registration?'
              : 'Reject membership application?'
              : 'Confirm cash payment received?'}
        message={applicationConfirmation?.action === 'approve'
          ? selectedNeedsCombinedPayment
            ? selectedApprovalPaymentMethod === 'gcash'
              ? 'Inspect the submitted GCash proof, then confirm. Payment verification and initial account approval will be saved together. This does not bypass the existing subscription or Daily Pass gym-entry eligibility rules.'
              : 'Confirm that the cash payment was received. Payment confirmation and initial account approval will be saved together. This does not bypass the existing subscription or Daily Pass gym-entry eligibility rules.'
            : selectedIsInitialDailyPass
              ? 'This approves the initial account registration. It does not change the already-recorded payment, and gym entry remains subject to the existing Daily Pass eligibility rules.'
              : selectedIsInitialMonthly
                ? 'This approves the initial member account. It does not change the already-verified payment or bypass subscription gym-entry rules.'
                : 'This approves the membership application only. Payment status remains independent, and unpaid or unverified membership does not grant gym entry.'
          : applicationConfirmation?.action === 'confirm_cash_payment'
            ? 'Confirm that the cash payment was received. This confirms payment only; the existing member account and any membership application retain their separate statuses.'
            : applicationConfirmation?.action === 'reject'
              ? `This application will be rejected with the reason provided: ${applicationRejectReason.trim()}`
              : 'Confirm this review action. The decision will be saved to the application record.'}
        confirmText={applicationConfirmation?.action === 'approve'
          ? selectedNeedsCombinedPayment
            ? `${selectedApprovalPaymentMethod === 'gcash' ? 'Verify GCash' : 'Confirm cash'} & approve`
            : selectedIsInitialDailyPass ? 'Approve account' : 'Approve application'
          : applicationConfirmation?.action === 'reject'
            ? selectedIsInitialDailyPass ? 'Reject account' : 'Reject application'
            : applicationConfirmation?.action === 'confirm_cash_payment' ? 'Confirm receipt' : 'Confirm review'}
        variant={applicationConfirmation?.action === 'reject' || applicationConfirmation?.decision === 'rejected' ? 'danger' : 'warning'}
        loading={Boolean(requestActionLoadingId || proofReviewLoadingKey)}
        loadingText="Saving…"
        onConfirm={confirmApplicationAction}
        onCancel={() => { if (!requestActionLoadingId && !proofReviewLoadingKey) setApplicationConfirmation(null) }}
      />
    </div>
  )
}

export default Members
