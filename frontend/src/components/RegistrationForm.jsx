import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  FaEye,
  FaEyeSlash,
  FaUser,
  FaLock,
  FaCheckCircle,
  FaCheck,
  FaSpinner,
  FaExclamationCircle,
  FaArrowRight,
  FaIdCard,
  FaUsers,
} from 'react-icons/fa'
import { Link } from 'react-router-dom'
import api from '../services/api.js'
import MembershipOption from './MembershipOption.jsx'
import { MEMBER_GENDER_OPTIONS } from '../utils/memberGender.js'

const defaultFormValues = {
  firstName: '',
  middleName: '',
  lastName: '',
  username: '',
  email: '',
  phone: '',
  address: '',
  dateOfBirth: '',
  gender: '',
  password: '',
  confirmPassword: '',
  membershipType: 'monthly',
  memberCategory: 'regular',
  membershipPlanId: '',
  durationMonths: '1',
  paymentMethod: 'gcash',
  termsAccepted: false,
}

const membershipOptions = [
  {
    value: 'monthly',
    title: 'Monthly Subscription',
    description: '₱800 regular · ₱700 verified student, senior, or PWD',
  },
  {
    value: 'daily_pass',
    title: '1 Day Pass',
    description: '₱100 for one Manila-local calendar day · includes a permanent Member account',
  },
]

const memberCategories = [
  { value: 'regular', label: 'Regular' },
  { value: 'student', label: 'Student Promo' },
  { value: 'senior', label: 'Senior Citizen Promo' },
  { value: 'pwd', label: 'PWD Promo' },
]

function getManilaDate() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function addCalendarMonths(dateValue, months) {
  const [year, month, day] = dateValue.split('-').map(Number)
  const targetMonth = new Date(Date.UTC(year, month - 1 + months, 1))
  const lastDay = new Date(Date.UTC(targetMonth.getUTCFullYear(), targetMonth.getUTCMonth() + 1, 0)).getUTCDate()
  const targetDay = Math.min(day, lastDay)
  return `${targetMonth.getUTCFullYear()}-${String(targetMonth.getUTCMonth() + 1).padStart(2, '0')}-${String(targetDay).padStart(2, '0')}`
}

function formatManilaDate(value) {
  return new Date(`${value}T12:00:00+08:00`).toLocaleDateString(undefined, { timeZone: 'Asia/Manila' })
}

function createIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

const duplicateCaseStorageKey = 'registration_duplicate_name_case'

function readDuplicateNameCase() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(duplicateCaseStorageKey) || 'null')
    return Number.isInteger(saved?.caseId)
      && saved.caseId > 0
      && typeof saved.token === 'string'
      && /^[a-f0-9-]{36}$/i.test(saved.token)
      ? saved
      : null
  } catch {
    return null
  }
}

function isVerifiedOneMonthPlan(plan) {
  return plan?.plan_code === 'monthly_1'
    && Number(plan.duration_months) === 1
    && Number(plan.regular_price) === 800
    && Number(plan.student_price) === 700
    && Number(plan.senior_price) === 700
    && Number(plan.pwd_price) === 700
}

function RegistrationForm() {
  const [values, setValues] = useState(() => ({
    ...defaultFormValues,
    membershipType: new URLSearchParams(window.location.search).get('membership') === 'daily_pass'
      ? 'daily_pass'
      : defaultFormValues.membershipType,
  }))
  const submitInFlight = useRef(false)
  const [errors, setErrors] = useState({})
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submittedData, setSubmittedData] = useState(null)
  const [duplicateCaseId, setDuplicateCaseId] = useState(() => readDuplicateNameCase()?.caseId ?? null)
  const [applicationStatus, setApplicationStatus] = useState(null)
  const [statusLoading, setStatusLoading] = useState(false)
  const [correctionFiles, setCorrectionFiles] = useState({})
  const [correctionLoading, setCorrectionLoading] = useState('')
  const [correctionError, setCorrectionError] = useState('')
  const [paymentProof, setPaymentProof] = useState(null)
  const [discountProof, setDiscountProof] = useState(null)
  const [gcashSettings, setGcashSettings] = useState(null)
  const [gcashSettingsError, setGcashSettingsError] = useState('')
  const [membershipPlans, setMembershipPlans] = useState([])
  const [idempotencyKey, setIdempotencyKey] = useState(() => readDuplicateNameCase()?.token ?? createIdempotencyKey())

  const verifiedMonthlyPlans = useMemo(
    () => membershipPlans.filter(isVerifiedOneMonthPlan),
    [membershipPlans]
  )
  const selectedPlan = verifiedMonthlyPlans.find((plan) => String(plan.id) === String(values.membershipPlanId))
  const selectedPlanPrice = selectedPlan
    ? Number(selectedPlan[`${values.memberCategory || 'regular'}_price`])
    : null
  const durationMonths = Number(values.durationMonths)
  const hasValidDuration = Number.isInteger(durationMonths) && durationMonths >= 1 && durationMonths <= 120
  const amountDue = values.membershipType === 'daily_pass'
    ? 100
    : selectedPlanPrice !== null && hasValidDuration ? selectedPlanPrice * durationMonths : null
  const estimatedMembershipEnd = hasValidDuration ? addCalendarMonths(getManilaDate(), durationMonths) : null

  const checkApplicationStatus = async () => {
    if (!submittedData?.requestId || !submittedData?.applicationToken) return
    setStatusLoading(true)
    setCorrectionError('')
    try {
      const response = await api.post('/registration_status.php?action=check', {
        request_id: submittedData.requestId,
        application_token: submittedData.applicationToken,
      })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load application status.')
      setApplicationStatus(response.data)
    } catch (requestError) {
      setCorrectionError(requestError?.response?.data?.message || requestError?.message || 'Unable to load application status. Please retry.')
    } finally {
      setStatusLoading(false)
    }
  }

  const submitCorrectedDocument = async (documentType) => {
    const file = correctionFiles[documentType]
    if (!file || !submittedData?.applicationToken) return
    setCorrectionLoading(documentType)
    setCorrectionError('')
    try {
      const payload = new FormData()
      payload.append('request_id', submittedData.requestId)
      payload.append('application_token', submittedData.applicationToken)
      payload.append('document_type', documentType)
      payload.append('document', file)
      const response = await api.post('/registration_status.php?action=replace_proof', payload)
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to submit the corrected document.')
      setCorrectionFiles((previous) => ({ ...previous, [documentType]: null }))
      await checkApplicationStatus()
    } catch (requestError) {
      setCorrectionError(requestError?.response?.data?.message || requestError?.message || 'Unable to submit the corrected document. Please retry.')
    } finally {
      setCorrectionLoading('')
    }
  }

  const loadGcashSettings = useCallback(async () => {
    setGcashSettingsError('')
    try {
      const response = await api.get('/gcash_settings.php')
      if (!response.data?.success) {
        setGcashSettings(null)
        setGcashSettingsError(response.data?.message || 'Unable to load GCash payment details.')
        return
      }
      setGcashSettings(response.data.settings || null)
      setMembershipPlans(response.data.membership_plans || [])
    } catch (requestError) {
      setGcashSettings(null)
      setGcashSettingsError(requestError?.response?.data?.message || 'Unable to load GCash payment details.')
    }
  }, [])

  useEffect(() => {
    let active = true
    api.get('/gcash_settings.php')
      .then((response) => {
        if (!active) return
        if (!response.data?.success) {
          setGcashSettings(null)
          setGcashSettingsError(response.data?.message || 'Unable to load GCash payment details.')
          return
        }
        setGcashSettings(response.data.settings || null)
        setMembershipPlans(response.data.membership_plans || [])
      })
      .catch((requestError) => {
        if (!active) return
        setGcashSettings(null)
        setGcashSettingsError(requestError?.response?.data?.message || 'Unable to load GCash payment details.')
      })
    return () => {
      active = false
    }
  }, [])

  const emailPattern = useMemo(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/, [])

  // Password rules validation for live checklist
  const passwordHasMinLength = values.password.length >= 8
  const passwordHasNumber = /\d/.test(values.password)
  const passwordHasUpper = /[A-Z]/.test(values.password)

  // Password strength calculation
  const passwordStrength = useMemo(() => {
    if (!values.password) return 0
    let score = 0
    if (values.password.length >= 8) score += 1
    if (/\d/.test(values.password)) score += 1
    if (/[A-Z]/.test(values.password)) score += 1
    if (/[!@#$%^&*(),.?":{}|<>]/.test(values.password) || values.password.length >= 12) score += 1
    return score
  }, [values.password])

  const strengthLabels = ['Weak', 'Fair', 'Good', 'Strong']
  const strengthColors = ['bg-rose-500', 'bg-amber-500', 'bg-blue-500', 'bg-emerald-500']

  // Progress Calculation
  const activeStep = useMemo(() => {
    if (submitted) return 4
    if (!values.firstName || !values.lastName || !values.username || !values.phone || !values.dateOfBirth || !values.address) {
      return 1
    }
    if (!values.membershipType) {
      return 2
    }
    if (!values.password || !values.confirmPassword || !values.termsAccepted) {
      return 3
    }
    return 4
  }, [values, submitted])

  const validate = () => {
    const newErrors = {}

    if (!values.firstName.trim()) {
      newErrors.firstName = 'First name is required.'
    } else if (values.firstName.trim().length > 50) {
      newErrors.firstName = 'First name cannot exceed 50 characters.'
    }

    if (values.middleName && values.middleName.trim().length > 50) {
      newErrors.middleName = 'Middle name cannot exceed 50 characters.'
    }

    if (!values.lastName.trim()) {
      newErrors.lastName = 'Last name is required.'
    } else if (values.lastName.trim().length > 50) {
      newErrors.lastName = 'Last name cannot exceed 50 characters.'
    }

    if (!values.username.trim()) {
      newErrors.username = 'Username is required.'
    } else if (!/^[A-Za-z0-9_]{3,30}$/.test(values.username.trim())) {
      newErrors.username = 'Username must be 3-30 characters and may only contain letters, numbers, and underscores.'
    }

    if (values.email.trim() && !emailPattern.test(values.email)) {
      newErrors.email = 'Please enter a valid email address.'
    }

    if (!values.phone.trim()) {
      newErrors.phone = 'Phone number is required.'
    } else if (!/^\d{11}$/.test(values.phone)) {
      newErrors.phone = 'Phone number must contain exactly 11 digits.'
    }

    if (!values.address.trim()) {
      newErrors.address = 'Address is required.'
    } else if (values.address.trim().length > 255) {
      newErrors.address = 'Address cannot exceed 255 characters.'
    }

    if (!values.dateOfBirth) {
      newErrors.dateOfBirth = 'Date of birth is required.'
    } else {
      const today = getManilaDate()
      if (values.dateOfBirth > today) {
        newErrors.dateOfBirth = 'Date of birth cannot be in the future.'
      }
    }

    if (values.gender && !MEMBER_GENDER_OPTIONS.includes(values.gender)) {
      newErrors.gender = 'Gender must be one of: Male, Female, or Prefer not to say.'
    }

    if (!values.password) {
      newErrors.password = 'Password is required.'
    } else if (values.password.length < 8) {
      newErrors.password = 'Password must contain at least 8 characters.'
    }

    if (!values.confirmPassword) {
      newErrors.confirmPassword = 'Please confirm your password.'
    } else if (values.confirmPassword !== values.password) {
      newErrors.confirmPassword = 'Passwords do not match.'
    }

    if (!values.termsAccepted) {
      newErrors.termsAccepted = 'You must agree to the terms and conditions.'
    }

    if (!values.membershipType) {
      newErrors.membershipType = 'Please choose a membership option.'
    }

    if (values.membershipType === 'monthly' && !values.memberCategory) {
      newErrors.memberCategory = 'Please choose a member category for monthly membership.'
    }

    if (values.membershipType === 'monthly' && !selectedPlan) {
      newErrors.membershipPlanId = verifiedMonthlyPlans.length
        ? 'Choose the verified monthly price plan.'
        : 'No verified monthly price plan is currently available.'
    }

    if (values.membershipType === 'monthly' && !hasValidDuration) {
      newErrors.durationMonths = 'Choose a whole number from 1 to 120 months.'
    }

    if (!['cash', 'gcash'].includes(values.paymentMethod)) {
      newErrors.paymentMethod = 'Choose Cash or GCash.'
    }

    if (values.paymentMethod === 'gcash' && !paymentProof) {
      newErrors.paymentProof = 'Upload your GCash payment receipt.'
    }
    if (values.paymentMethod === 'gcash'
      && (!gcashSettings?.gcash_account_name || !gcashSettings?.gcash_account_number)) {
      newErrors.gcashSettings = 'GCash registration is unavailable until the gym configures payment details.'
    }

    if (values.membershipType === 'monthly' && values.memberCategory !== 'regular' && !discountProof) {
      newErrors.discountProof = 'Upload a document verifying your selected member category.'
    }

    return newErrors
  }

  const handleChange = (field, value) => {
    if (field === 'phone') value = value.replace(/\D/g, '').slice(0, 11)
    setValues((prev) => ({ ...prev, [field]: value }))
    setErrors((prev) => ({ ...prev, [field]: '', api: '' }))
    if (field === 'paymentMethod' && value === 'cash') {
      setPaymentProof(null)
      setErrors((prev) => ({ ...prev, paymentProof: '' }))
    }
    if (field === 'memberCategory' && value === 'regular') {
      setDiscountProof(null)
      setErrors((prev) => ({ ...prev, discountProof: '' }))
    }
    if (field === 'membershipType' && value !== 'monthly') {
      setDiscountProof(null)
      setErrors((prev) => ({ ...prev, discountProof: '' }))
    }
    if (!duplicateCaseId) setIdempotencyKey(createIdempotencyKey())
  }

  const startNewDuplicateNameAttempt = () => {
    sessionStorage.removeItem(duplicateCaseStorageKey)
    setDuplicateCaseId(null)
    setIdempotencyKey(createIdempotencyKey())
    setErrors((previous) => ({ ...previous, api: '' }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (submitInFlight.current) return
    const newErrors = validate()

    if (Object.keys(newErrors).length > 0) {
      setErrors({
        ...newErrors,
        validationSummary: 'Please correct the highlighted fields before submitting.',
      })
      const firstErrorKey = Object.keys(newErrors)[0]
      const element = document.getElementById(firstErrorKey)
      if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' })
        element.focus()
      }
      return
    }

    submitInFlight.current = true
    setSubmitting(true)
    setErrors({})

    const computedFullName = [values.firstName.trim(), values.middleName.trim(), values.lastName.trim()]
      .filter(Boolean)
      .join(' ')

    try {
      const payload = new FormData()
      const fields = {
        first_name: values.firstName.trim(),
        middle_name: values.middleName.trim(),
        last_name: values.lastName.trim(),
        full_name: computedFullName,
        username: values.username.trim(),
        email: values.email.trim(),
        phone: values.phone.trim(),
        address: values.address.trim(),
        date_of_birth: values.dateOfBirth,
        gender: values.gender || '',
        password: values.password,
        membership_type: values.membershipType,
        member_category: values.memberCategory,
        membership_plan_id: values.membershipType === 'monthly' ? values.membershipPlanId : '',
        duration_months: values.membershipType === 'monthly' ? String(durationMonths) : '',
        payment_method: values.paymentMethod,
        idempotency_key: idempotencyKey,
      }
      Object.entries(fields).forEach(([key, value]) => {
        if (value !== null) payload.append(key, value)
      })
      if (values.paymentMethod === 'gcash' && paymentProof) payload.append('payment_proof', paymentProof)
      if (values.membershipType === 'monthly' && values.memberCategory !== 'regular' && discountProof) {
        payload.append('discount_proof', discountProof)
      }

      const response = await api.post('/register.php', payload)

      if (response.data?.success) {
        setDuplicateCaseId(null)
        sessionStorage.removeItem(duplicateCaseStorageKey)
        setSubmittedData({
          fullName: computedFullName,
          email: values.email,
          membershipType: values.membershipType === 'daily_pass' ? '1 Day Pass' : 'Monthly Subscription',
          paymentMethod: values.paymentMethod,
          requestId: response.data.request_id,
          applicationToken: response.data.application_token,
          planName: selectedPlan?.plan_name,
          durationMonths: values.membershipType === 'monthly' ? durationMonths : null,
          paymentAmount: amountDue,
          paymentStatus: response.data.payment_status,
        })
        setSubmitted(true)
        setValues(defaultFormValues)
        setPaymentProof(null)
        setDiscountProof(null)
        setIdempotencyKey(createIdempotencyKey())
      } else {
        setErrors({ api: response.data?.message || 'Registration failed. Please try again.' })
      }
    } catch (error) {
      const baseMessage = typeof error?.response?.data?.message === 'string'
        ? error.response.data.message
        : 'Unable to reach the registration service. Please try again later.'
      if (error?.response?.data?.code === 'duplicate_name_review_required') {
        const caseId = Number(error.response.data.case_id)
        setDuplicateCaseId(Number.isInteger(caseId) && caseId > 0 ? caseId : null)
        if (Number.isInteger(caseId) && caseId > 0) {
          sessionStorage.setItem(duplicateCaseStorageKey, JSON.stringify({
            caseId,
            token: idempotencyKey,
          }))
        }
        setErrors({ api: baseMessage })
        return
      }
      const diagnostic = error?.response?.data?.diagnostic
      const diagnosticMessage = import.meta.env.DEV && diagnostic
        ? ` (operation: ${diagnostic.operation || 'unknown'}${diagnostic.sqlstate ? `; SQLSTATE ${diagnostic.sqlstate}` : ''}${diagnostic.driver_code ? `; database code ${diagnostic.driver_code}` : ''}${diagnostic.exception ? `; ${diagnostic.exception}` : ''})`
        : ''
      const message = `${baseMessage}${diagnosticMessage}`
      setErrors({ api: message })
    } finally {
      submitInFlight.current = false
      setSubmitting(false)
    }
  }

  // Submitted Success View
  if (submitted) {
    return (
      <div className="rounded-3xl border border-slate-200/80 bg-white p-8 sm:p-10 shadow-sm text-center">
        <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/50">
          <FaCheckCircle className="text-3xl" />
        </div>

        <div className="inline-flex items-center gap-2 rounded-full bg-amber-50 px-3.5 py-1 text-xs font-bold text-amber-700 border border-amber-200/60 mb-3">
          <span className="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
          {submittedData?.membershipType === '1 Day Pass' ? 'Status: Pending Account Approval' : 'Status: Pending Admin Approval'}
        </div>

        <h2 className="text-2xl sm:text-3xl font-bold text-slate-900">Registration Submitted!</h2>
        <p className="mt-2 text-sm sm:text-base text-slate-600 max-w-md mx-auto leading-relaxed">
          {submittedData?.membershipType === '1 Day Pass'
            ? 'Your registration has been received and is awaiting administrator review. Log in to check your account approval status. Dashboard and gym access will remain unavailable until approval; Daily Pass payment verification is handled separately.'
            : submittedData?.paymentMethod === 'gcash'
            ? 'Your GCash proof and application have been received. Monthly accounts are activated after an administrator verifies the payment and any eligibility documents.'
            : 'Your monthly application is pending. An administrator must receive and confirm the cash payment, verify any required eligibility documents, and approve the application before activation.'}
        </p>

        {submittedData && (
          <div className="mt-6 mx-auto max-w-sm rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left text-xs sm:text-sm space-y-2">
            <div className="flex justify-between border-b border-slate-200 pb-2">
              <span className="text-slate-500">Applicant:</span>
              <span className="font-semibold text-slate-900">{submittedData.fullName}</span>
            </div>
            <div className="flex justify-between border-b border-slate-200 pb-2">
              <span className="text-slate-500">Email:</span>
              <span className="font-semibold text-slate-900">{submittedData.email}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-500">Plan:</span>
              <span className="font-semibold text-violet-700">{submittedData.planName || submittedData.membershipType}</span>
            </div>
            {submittedData.durationMonths && (
              <div className="flex justify-between">
                <span className="text-slate-500">Duration:</span>
                <span className="font-semibold text-slate-900">{submittedData.durationMonths} month{submittedData.durationMonths === 1 ? '' : 's'}</span>
              </div>
            )}
            <div className="flex justify-between">
              <span className="text-slate-500">Payment:</span>
              <span className="font-semibold capitalize text-slate-900">{submittedData.paymentMethod}</span>
            </div>
            {submittedData.paymentAmount && (
              <div className="flex justify-between">
                <span className="text-slate-500">Payment:</span>
                <span className="font-semibold text-slate-900">₱{Number(submittedData.paymentAmount).toFixed(2)}</span>
              </div>
            )}
            {submittedData.requestId && (
              <div className="flex justify-between">
                <span className="text-slate-500">Application reference:</span>
                <span className="font-semibold text-slate-900">#{submittedData.requestId}</span>
              </div>
            )}
            {submittedData.applicationToken && (
              <div className="border-t border-slate-200 pt-2">
                <p className="text-slate-500">Private application tracking key (save it):</p>
                <p className="mt-1 break-all font-mono text-[11px] font-semibold text-slate-900">{submittedData.applicationToken}</p>
              </div>
            )}
          </div>
        )}

        {submittedData?.applicationToken && (
          <section className="mx-auto mt-5 max-w-sm rounded-2xl border border-violet-100 bg-violet-50/50 p-4 text-left">
            <div className="flex items-center justify-between gap-3">
              <h3 className="text-sm font-semibold text-slate-900">Application status</h3>
              <button type="button" disabled={statusLoading} onClick={checkApplicationStatus} className="rounded-lg border border-violet-200 bg-white px-3 py-1.5 text-xs font-semibold text-violet-700 disabled:opacity-50">
                {statusLoading ? 'Checking…' : 'Check status'}
              </button>
            </div>
            {applicationStatus && (
              <div className="mt-3 space-y-1 text-xs text-slate-600">
                <p>Application: <span className="font-semibold capitalize text-slate-900">{applicationStatus.status}</span></p>
                {applicationStatus.review_note && (
                  <p className="text-rose-700">Review note: {applicationStatus.review_note}</p>
                )}
                <p>Payment: <span className="font-semibold capitalize text-slate-900">{applicationStatus.payment_status.replaceAll('_', ' ')}</span></p>
                <p>Discount: <span className="font-semibold capitalize text-slate-900">{applicationStatus.discount_status.replaceAll('_', ' ')}</span></p>
              </div>
            )}
            {correctionError && <p role="alert" className="mt-3 text-xs text-rose-700">{correctionError}</p>}
            {['payment_proof', 'discount_proof'].map((documentType) => {
              const status = documentType === 'payment_proof'
                ? applicationStatus?.payment_status
                : applicationStatus?.discount_status
              if (status !== 'needs_correction') return null
              return (
                <div key={documentType} className="mt-3 space-y-2 border-t border-violet-100 pt-3">
                  <label className="block text-xs font-medium text-slate-700">
                    Replace {documentType === 'payment_proof' ? 'payment receipt' : 'eligibility document'}
                    <input type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onChange={(event) => setCorrectionFiles((previous) => ({ ...previous, [documentType]: event.target.files?.[0] || null }))} className="mt-1 block w-full text-xs" />
                  </label>
                  {applicationStatus.documents?.find((item) => item.document_type === documentType)?.review_note && (
                    <p className="text-xs text-rose-700">
                      Reviewer note: {applicationStatus.documents.find((item) => item.document_type === documentType).review_note}
                    </p>
                  )}
                  <button type="button" disabled={!correctionFiles[documentType] || correctionLoading === documentType} onClick={() => submitCorrectedDocument(documentType)} className="rounded-lg bg-violet-700 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">
                    {correctionLoading === documentType ? 'Uploading…' : 'Submit replacement'}
                  </button>
                </div>
              )
            })}
          </section>
        )}

        <div className="mt-8">
          <Link
            to="/login"
            className="inline-flex items-center justify-center gap-2 rounded-2xl bg-violet-700 px-8 py-3.5 text-sm font-semibold text-white shadow-md shadow-violet-700/20 transition hover:bg-violet-800"
          >
            {submittedData?.membershipType === '1 Day Pass' ? 'Log in to check status' : 'Back to Login'}
            <FaArrowRight className="text-xs" />
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-3xl border border-slate-200/80 bg-white p-6 sm:p-10 shadow-sm">
      {/* Live Step Progress Indicator */}
      <div className="mb-8">
        <div className="flex items-center justify-between">
          {[
            { step: 1, label: 'Profile' },
            { step: 2, label: 'Plan' },
            { step: 3, label: 'Security' },
            { step: 4, label: 'Done' },
          ].map((s, idx) => (
            <div key={s.step} className="flex flex-1 items-center">
              <div className="flex flex-col items-center">
                <div
                  className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all ${
                    activeStep > s.step
                      ? 'bg-emerald-500 text-white'
                      : activeStep === s.step
                      ? 'bg-violet-700 text-white ring-4 ring-violet-100'
                      : 'bg-slate-100 text-slate-400'
                  }`}
                >
                  {activeStep > s.step ? <FaCheck className="text-[10px]" /> : s.step}
                </div>
                <span
                  className={`mt-1.5 text-[11px] font-semibold tracking-wider uppercase ${
                    activeStep >= s.step ? 'text-slate-800' : 'text-slate-400'
                  }`}
                >
                  {s.label}
                </span>
              </div>
              {idx < 3 && (
                <div
                  className={`mx-2 h-0.5 flex-1 transition-all ${
                    activeStep > s.step ? 'bg-emerald-500' : 'bg-slate-200'
                  }`}
                />
              )}
            </div>
          ))}
        </div>
      </div>

      {/* Global API Error Alert */}
      {(errors.api || errors.validationSummary) && (
        <div className="mb-6 flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
          <FaExclamationCircle className="shrink-0 text-base text-rose-600" />
          <div>
            <p role="alert" aria-live="polite" className="font-medium">{errors.api || errors.validationSummary}</p>
            {errors.api && duplicateCaseId && (
              <>
                <p className="mt-1 text-xs">Review reference #{duplicateCaseId}. No account or payment was created. Contact the gym administrator; if authorized, resubmit this same form with the reviewed details.</p>
                <button type="button" onClick={startNewDuplicateNameAttempt} className="mt-2 text-xs font-semibold underline">
                  Start a new attempt with changed details
                </button>
              </>
            )}
          </div>
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate className="space-y-8">
        {/* SECTION 1: Personal Information */}
        <section className="space-y-4">
          <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
              <FaUser className="text-sm" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">Personal Information</h2>
              <p className="text-xs text-slate-500">Enter your name and contact details</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 pt-2">
            {/* First Name */}
            <div className="space-y-1">
              <label htmlFor="firstName" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                First Name <span className="text-rose-500">*</span>
              </label>
              <input
                id="firstName"
                type="text"
                value={values.firstName}
                onChange={(e) => handleChange('firstName', e.target.value)}
                placeholder="e.g. Juan"
                maxLength={50}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.firstName
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.firstName && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.firstName}
                </p>
              )}
            </div>

            {/* Middle Name */}
            <div className="space-y-1">
              <label htmlFor="middleName" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Middle Name <span className="text-[11px] text-slate-400 font-normal lowercase">(optional)</span>
              </label>
              <input
                id="middleName"
                type="text"
                value={values.middleName}
                onChange={(e) => handleChange('middleName', e.target.value)}
                placeholder="e.g. Santos"
                maxLength={50}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.middleName
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.middleName && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.middleName}
                </p>
              )}
            </div>

            {/* Last Name */}
            <div className="space-y-1 sm:col-span-2">
              <label htmlFor="lastName" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Last Name <span className="text-rose-500">*</span>
              </label>
              <input
                id="lastName"
                type="text"
                value={values.lastName}
                onChange={(e) => handleChange('lastName', e.target.value)}
                placeholder="e.g. Dela Cruz"
                maxLength={50}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.lastName
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.lastName && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.lastName}
                </p>
              )}
            </div>

            {/* Username */}
            <div className="space-y-1">
              <label htmlFor="username" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Username <span className="text-rose-500">*</span>
              </label>
              <input
                id="username"
                type="text"
                value={values.username}
                onChange={(e) => handleChange('username', e.target.value)}
                placeholder="juan123"
                maxLength={30}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.username
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.username && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.username}
                </p>
              )}
            </div>

            {/* Email Address */}
            <div className="space-y-1">
              <label htmlFor="email" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Email Address <span className="text-[11px] text-slate-400 font-normal lowercase">(optional)</span>
              </label>
              <input
                id="email"
                type="email"
                value={values.email}
                onChange={(e) => handleChange('email', e.target.value)}
                placeholder="juan@example.com"
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.email
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.email && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.email}
                </p>
              )}
            </div>

            {/* Phone Number */}
            <div className="space-y-1">
              <label htmlFor="phone" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Phone Number <span className="text-rose-500">*</span>
              </label>
              <input
                id="phone"
                type="tel"
                inputMode="numeric"
                maxLength={11}
                pattern="[0-9]{11}"
                value={values.phone}
                onChange={(e) => handleChange('phone', e.target.value)}
                placeholder="09XXXXXXXXX (11 digits)"
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.phone
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.phone && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.phone}
                </p>
              )}
            </div>

            {/* Date of Birth */}
            <div className="space-y-1">
              <label htmlFor="dateOfBirth" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Date of Birth <span className="text-rose-500">*</span>
              </label>
              <input
                id="dateOfBirth"
                type="date"
                max={getManilaDate()}
                value={values.dateOfBirth}
                onChange={(e) => handleChange('dateOfBirth', e.target.value)}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.dateOfBirth
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.dateOfBirth && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.dateOfBirth}
                </p>
              )}
            </div>

            {/* Gender */}
            <div className="space-y-1">
              <label htmlFor="gender" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Gender <span className="text-[11px] text-slate-400 font-normal lowercase">(optional)</span>
              </label>
              <select
                id="gender"
                value={values.gender}
                onChange={(e) => handleChange('gender', e.target.value)}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 outline-none transition ${
                  errors.gender
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              >
                <option value="">Not specified</option>
                {MEMBER_GENDER_OPTIONS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
              </select>
              {errors.gender && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.gender}
                </p>
              )}
            </div>

            {/* Address */}
            <div className="space-y-1 sm:col-span-2">
              <label htmlFor="address" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Home Address <span className="text-rose-500">*</span>
              </label>
              <input
                id="address"
                type="text"
                value={values.address}
                onChange={(e) => handleChange('address', e.target.value)}
                placeholder="House/Unit No., Street, Barangay, City"
                maxLength={255}
                className={`w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                  errors.address
                    ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                    : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                }`}
              />
              {errors.address && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.address}
                </p>
              )}
            </div>
          </div>
        </section>

        {/* SECTION 2: Membership Type */}
        <section className="space-y-4">
          <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
              <FaIdCard className="text-sm" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">Membership Type</h2>
              <p className="text-xs text-slate-500">Choose the membership plan that suits your schedule</p>
            </div>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 pt-2">
            {membershipOptions.map((opt) => (
              <MembershipOption
                key={opt.value}
                option={opt}
                selected={values.membershipType === opt.value}
                onSelect={(val) => handleChange('membershipType', val)}
              />
            ))}
          </div>
          {errors.membershipType && (
            <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
              <FaExclamationCircle className="text-[10px]" /> {errors.membershipType}
            </p>
          )}
        </section>

        {/* SECTION 3: Member Category */}
        {values.membershipType === 'monthly' && (
        <section className="space-y-4">
          <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
              <FaUsers className="text-sm" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">Member Category</h2>
              <p className="text-xs text-slate-500">Choose a category for monthly membership pricing</p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 pt-2 sm:grid-cols-4">
            {memberCategories.map((category) => (
              <button
                key={category.value}
                type="button"
                onClick={() => handleChange('memberCategory', category.value)}
                className={`rounded-2xl border-2 px-3 py-3 text-sm font-semibold transition-all focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${
                  values.memberCategory === category.value && values.membershipType === 'monthly'
                    ? 'border-violet-600 bg-violet-50 text-violet-800 ring-4 ring-violet-500/10'
                    : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50/50'
                }`}
              >
                {category.label}
              </button>
            ))}
          </div>
          {errors.memberCategory && (
            <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
              <FaExclamationCircle className="text-[10px]" /> {errors.memberCategory}
            </p>
          )}
          {values.membershipType === 'monthly' && (
            <div className="space-y-1">
              <label htmlFor="membershipPlanId" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Monthly price plan
              </label>
              <select
                id="membershipPlanId"
                value={values.membershipPlanId}
                onChange={(event) => handleChange('membershipPlanId', event.target.value)}
                className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10"
              >
                <option value="">Choose the verified monthly rate</option>
                {verifiedMonthlyPlans.map((plan) => (
                  <option key={plan.id} value={String(plan.id)}>
                    {plan.plan_name} — ₱{Number(plan[`${values.memberCategory}_price`]).toFixed(2)} per month
                  </option>
                ))}
              </select>
              {errors.membershipPlanId && <p className="text-xs font-medium text-rose-600">{errors.membershipPlanId}</p>}
              </div>
          )}
          {values.membershipType === 'monthly' && (
            <div className="grid gap-3 sm:grid-cols-2">
              <label htmlFor="durationMonths" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Number of months
                <input
                  id="durationMonths"
                  type="number"
                  min="1"
                  max="120"
                  step="1"
                  inputMode="numeric"
                  value={values.durationMonths}
                  onChange={(event) => handleChange('durationMonths', event.target.value)}
                  className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-normal normal-case text-slate-900"
                />
                {errors.durationMonths && <span className="mt-1 block text-xs font-medium text-rose-600">{errors.durationMonths}</span>}
              </label>
              <div className="rounded-xl bg-violet-50 p-3 text-sm text-violet-900">
                <p className="font-semibold">
                  Total: {amountDue === null ? 'Choose a plan and valid duration' : `₱${amountDue.toFixed(2)}`}
                </p>
                <p className="mt-1 text-xs">
                  {selectedPlanPrice === null ? 'Monthly rate not selected.' : `₱${selectedPlanPrice.toFixed(2)} × ${hasValidDuration ? durationMonths : '—'} month${durationMonths === 1 ? '' : 's'}`}
                </p>
                {estimatedMembershipEnd && hasValidDuration && (
                  <p className="mt-1 text-xs">Estimated period if approved today: {formatManilaDate(getManilaDate())} – {formatManilaDate(estimatedMembershipEnd)}</p>
                )}
              </div>
            </div>
          )}
        </section>
        )}

        {(
          <section className="space-y-4 rounded-2xl border border-violet-100 bg-violet-50/40 p-4 sm:p-5">
            <div>
              <h2 className="text-base font-bold text-slate-900">Payment Method</h2>
              <p className="mt-1 text-sm font-semibold text-violet-800">
                Amount due: {amountDue === null ? 'Select an available monthly plan' : `₱${amountDue.toFixed(2)}`}
              </p>
              <p className="mt-1 text-xs leading-relaxed text-slate-600">
                {values.membershipType === 'daily_pass'
                  ? 'Pay ₱100 by Cash or GCash. Staff must confirm Cash; GCash requires receipt and reference verification.'
                  : 'Choose Cash or GCash. Cash is confirmed by an administrator; GCash requires receipt and reference verification.'}
              </p>
            </div>
            <label htmlFor="paymentMethod" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
              Payment method
              <select id="paymentMethod" value={values.paymentMethod} onChange={(event) => handleChange('paymentMethod', event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-normal normal-case">
                <option value="cash">Cash — confirm with gym staff</option>
                <option value="gcash">GCash — upload proof for manual verification</option>
              </select>
            </label>
            {errors.paymentMethod && <p className="text-xs font-medium text-rose-600">{errors.paymentMethod}</p>}
            {values.paymentMethod === 'cash' ? (
              <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                {values.membershipType === 'daily_pass'
                  ? 'Your Member account is created now, but 1 Day Pass access stays unavailable until staff confirms the ₱100 payment.'
                  : 'Your application will not activate until an administrator records and confirms receipt of the correct payment.'}
              </p>
            ) : <>
            {gcashSettingsError ? (
              <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                <p>{gcashSettingsError}</p>
                <button type="button" onClick={loadGcashSettings} className="mt-2 font-semibold underline">
                  Retry loading payment details
                </button>
              </div>
            ) : gcashSettings?.gcash_account_name && gcashSettings?.gcash_account_number ? (
              <div className="rounded-xl border border-violet-200 bg-white p-4 text-sm">
                <img
                  src={`${import.meta.env.BASE_URL}images/gym/gcashqr.jpg`}
                  alt="Gym GCash payment QR code"
                  className="mx-auto mb-4 h-auto max-h-72 w-full max-w-64 rounded-lg object-contain"
                />
                <p><span className="font-semibold">GCash account:</span> {gcashSettings.gcash_account_name}</p>
                <p className="mt-1"><span className="font-semibold">Mobile number:</span> {gcashSettings.gcash_account_number}</p>
              </div>
            ) : (
              <p role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                GCash account details have not been configured by the gym. Do not transfer funds. GCash payment will be available once the details are set.
              </p>
            )}
            {errors.gcashSettings && <p className="text-xs font-medium text-rose-600">{errors.gcashSettings}</p>}
            <div className="space-y-1">
              <label htmlFor="paymentProof" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                GCash receipt <span className="text-rose-500">*</span>
              </label>
              <input
                id="paymentProof"
                type="file"
                accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                onChange={(event) => {
                  setPaymentProof(event.target.files?.[0] || null)
                  setErrors((previous) => ({ ...previous, paymentProof: '' }))
                  if (!duplicateCaseId) setIdempotencyKey(createIdempotencyKey())
                }}
                className="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-violet-800"
              />
              <p className="text-[11px] text-slate-500">JPG, PNG, or PDF · maximum 5 MB. Staff will verify the actual transaction; uploading proof does not mark payment as paid. {amountDue === null ? 'Select a monthly plan to see the amount due.' : `Amount due ₱${amountDue.toFixed(2)}.`}</p>
              {paymentProof && <p className="text-xs text-slate-600">Selected: {paymentProof.name}</p>}
              {errors.paymentProof && <p className="text-xs font-medium text-rose-600">{errors.paymentProof}</p>}
            </div>
            </>}
            {values.membershipType === 'monthly' && values.memberCategory !== 'regular' && (
              <div className="space-y-1">
                <label htmlFor="discountProof" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                  {values.memberCategory === 'pwd' ? 'PWD' : values.memberCategory} eligibility document <span className="text-rose-500">*</span>
                </label>
                <input
                  id="discountProof"
                  type="file"
                  accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                  onChange={(event) => {
                    setDiscountProof(event.target.files?.[0] || null)
                    setErrors((previous) => ({ ...previous, discountProof: '' }))
                    if (!duplicateCaseId) setIdempotencyKey(createIdempotencyKey())
                  }}
                  className="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-violet-800"
                />
                <p className="text-[11px] text-slate-500">JPG, PNG, or PDF · maximum 5 MB</p>
                {discountProof && <p className="text-xs text-slate-600">Selected: {discountProof.name}</p>}
                {errors.discountProof && <p className="text-xs font-medium text-rose-600">{errors.discountProof}</p>}
              </div>
            )}
          </section>
        )}

        {/* SECTION 4: Account Security */}
        <section className="space-y-4">
          <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
              <FaLock className="text-sm" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">Account Security</h2>
              <p className="text-xs text-slate-500">Set a secure password for your member portal</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 pt-2">
            {/* Password */}
            <div className="space-y-1">
              <label htmlFor="password" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Password <span className="text-rose-500">*</span>
              </label>
              <div className="relative">
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  value={values.password}
                  onChange={(e) => handleChange('password', e.target.value)}
                  placeholder="At least 8 characters"
                  className={`w-full rounded-xl border px-3.5 py-2.5 pr-10 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                    errors.password
                      ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                      : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                  }`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none"
                  aria-label="Toggle password visibility"
                >
                  {showPassword ? <FaEyeSlash /> : <FaEye />}
                </button>
              </div>
              {errors.password && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.password}
                </p>
              )}

              {/* Password Strength Indicator */}
              {values.password && (
                <div className="mt-2 space-y-1.5 rounded-xl border border-slate-100 bg-slate-50/60 p-2.5 text-xs">
                  <div className="flex items-center justify-between text-[11px] font-medium text-slate-600">
                    <span>Strength:</span>
                    <span className="font-bold">{strengthLabels[passwordStrength - 1] || 'Too short'}</span>
                  </div>
                  <div className="flex h-1.5 w-full gap-1 overflow-hidden rounded-full bg-slate-200">
                    {[1, 2, 3, 4].map((level) => (
                      <div
                        key={level}
                        className={`h-full flex-1 transition-all ${
                          passwordStrength >= level ? strengthColors[passwordStrength - 1] : 'bg-transparent'
                        }`}
                      />
                    ))}
                  </div>
                  <div className="grid grid-cols-3 gap-1 pt-1 text-[10px] text-slate-500">
                    <span className={`flex items-center gap-1 ${passwordHasMinLength ? 'text-emerald-600 font-semibold' : ''}`}>
                      <FaCheck className="text-[8px]" /> 8+ chars
                    </span>
                    <span className={`flex items-center gap-1 ${passwordHasNumber ? 'text-emerald-600 font-semibold' : ''}`}>
                      <FaCheck className="text-[8px]" /> Number
                    </span>
                    <span className={`flex items-center gap-1 ${passwordHasUpper ? 'text-emerald-600 font-semibold' : ''}`}>
                      <FaCheck className="text-[8px]" /> Uppercase
                    </span>
                  </div>
                </div>
              )}
            </div>

            {/* Confirm Password */}
            <div className="space-y-1">
              <label htmlFor="confirmPassword" className="block text-xs font-semibold uppercase tracking-wider text-slate-700">
                Confirm Password <span className="text-rose-500">*</span>
              </label>
              <div className="relative">
                <input
                  id="confirmPassword"
                  type={showConfirmPassword ? 'text' : 'password'}
                  value={values.confirmPassword}
                  onChange={(e) => handleChange('confirmPassword', e.target.value)}
                  placeholder="Re-enter your password"
                  className={`w-full rounded-xl border px-3.5 py-2.5 pr-10 text-sm text-slate-900 placeholder:text-slate-400 outline-none transition ${
                    errors.confirmPassword
                      ? 'border-rose-300 bg-rose-50/40 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/10'
                      : 'border-slate-200 bg-slate-50/40 hover:border-slate-300 focus:bg-white focus:border-violet-600 focus:ring-2 focus:ring-violet-500/10'
                  }`}
                />
                <button
                  type="button"
                  onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none"
                  aria-label="Toggle confirm password visibility"
                >
                  {showConfirmPassword ? <FaEyeSlash /> : <FaEye />}
                </button>
              </div>
              {errors.confirmPassword && (
                <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600 mt-1">
                  <FaExclamationCircle className="text-[10px]" /> {errors.confirmPassword}
                </p>
              )}
            </div>
          </div>
        </section>

        {/* Terms & Submit Section */}
        <div className="space-y-4 pt-2">
          <label className="flex items-start gap-3 cursor-pointer select-none">
            <input
              type="checkbox"
              id="termsAccepted"
              checked={values.termsAccepted}
              onChange={(e) => handleChange('termsAccepted', e.target.checked)}
              className="mt-1 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
            />
            <span className="text-xs text-slate-600 leading-relaxed">
              I agree to the <span className="font-semibold text-violet-700">Gym Terms and Privacy Policy</span>. I understand that all membership requests are subject to approval by gym administration.
            </span>
          </label>
          {errors.termsAccepted && (
            <p className="flex items-center gap-1 text-[11px] font-medium text-rose-600">
              <FaExclamationCircle className="text-[10px]" /> {errors.termsAccepted}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="w-full flex items-center justify-center gap-2 rounded-2xl bg-violet-700 py-3.5 text-sm font-bold text-white shadow-lg shadow-violet-700/25 transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? (
              <>
                <FaSpinner className="animate-spin text-sm" /> Submitting Request...
              </>
            ) : (
              <>
                Submit Registration <FaArrowRight className="text-xs" />
              </>
            )}
          </button>
        </div>
      </form>
    </div>
  )
}

export default RegistrationForm
