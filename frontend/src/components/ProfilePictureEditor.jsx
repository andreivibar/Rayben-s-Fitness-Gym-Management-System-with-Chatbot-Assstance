import { useEffect, useRef, useState } from 'react'
import { FaCamera, FaImage, FaTrash, FaUser, FaSpinner } from 'react-icons/fa'
import api from '../services/api'
import { profilePictureUrl } from '../utils/profilePictureUrl'
import ProfileImagePreview from './ProfileImagePreview.jsx'

export default function ProfilePictureEditor({ entity, targetId, profilePicture, name, code, onUpdated, compact = false, displayOnly = false, accent = 'violet', removeLabel = 'Remove', large = false }) {
  const inputRef = useRef(null)
  const [preview, setPreview] = useState('')
  const [busy, setBusy] = useState(false)
  const [progress, setProgress] = useState(0)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [previewOpen, setPreviewOpen] = useState(false)
  const [pendingFile, setPendingFile] = useState(null)
  const [confirmAction, setConfirmAction] = useState(null)
  const getResponseError = (response, fallback) => {
    const data = response?.data
    if (data?.message) return data.message
    if (response && typeof data !== 'object') {
      return `The server returned HTTP ${response.status} without a valid upload response. Refresh the page to check whether the picture was saved before trying again.`
    }
    return fallback
  }

  useEffect(() => () => {
    if (preview) URL.revokeObjectURL(preview)
  }, [preview])

  const selectFile = () => inputRef.current?.click()

  const handleFileChange = async (event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    setMessage('')
    setError('')
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      setError('Use a JPG, PNG, or WEBP image.')
      return
    }
    if (file.size > 5 * 1024 * 1024) {
      setError('Image must be 5 MB or smaller.')
      return
    }

    setPreview(URL.createObjectURL(file))
    setPendingFile(file)
    setConfirmAction('upload')
  }

  const uploadPicture = async () => {
    if (!pendingFile) return
    setBusy(true)
    setProgress(0)
    try {
      const formData = new FormData()
      formData.append('action', 'upload')
      formData.append('entity', entity)
      if (targetId) formData.append('id', targetId)
      formData.append('profile_picture', pendingFile)
      const response = await api.post('/profile_picture.php', formData, {
        onUploadProgress: (progressEvent) => {
          if (progressEvent.total) setProgress(Math.round((progressEvent.loaded * 100) / progressEvent.total))
        },
      })
      if (!response.data || typeof response.data !== 'object' || response.data.success !== true) {
        throw new Error(getResponseError(response, 'The image could not be saved. Please try again.'))
      }
      setMessage('Profile picture updated.')
      setPendingFile(null)
      onUpdated?.(response.data.profile_picture)
    } catch (err) {
      setPreview('')
      setError(err?.response ? getResponseError(err.response, err.message) : (err?.message || 'Upload failed. Check your connection and try again.'))
    } finally {
      setBusy(false)
      setConfirmAction(null)
    }
  }

  const removePicture = async () => {
    if (!profilePicture || busy) return
    setMessage('')
    setError('')
    setBusy(true)
    try {
      const formData = new FormData()
      formData.append('action', 'remove')
      formData.append('entity', entity)
      if (targetId) formData.append('id', targetId)
      const response = await api.post('/profile_picture.php', formData)
      if (!response.data || typeof response.data !== 'object' || response.data.success !== true) {
        throw new Error(getResponseError(response, 'The image could not be removed. Please try again.'))
      }
      setPreview('')
      setMessage('Profile picture removed.')
      onUpdated?.(null)
    } catch (err) {
      setError(err?.response ? getResponseError(err.response, err.message) : (err?.message || 'Remove failed. Check your connection and try again.'))
    } finally {
      setBusy(false)
      setConfirmAction(null)
    }
  }

  const cancelConfirmation = () => {
    if (confirmAction === 'upload') {
      setPreview('')
      setPendingFile(null)
    }
    setConfirmAction(null)
  }

  const imageSource = preview || profilePictureUrl(profilePicture)
  const accentClasses = accent === 'cyan'
    ? {
        avatar: 'border-cyan-100 bg-cyan-50 text-cyan-800 hover:border-cyan-300 focus:ring-cyan-600',
        button: 'bg-cyan-800 hover:bg-cyan-900',
      }
    : {
        avatar: 'border-violet-100 bg-violet-50 text-violet-700 hover:border-violet-300 focus:ring-violet-500',
        button: 'bg-violet-700 hover:bg-violet-800',
      }

  return (
    <div className={`flex ${compact ? 'items-center gap-3' : 'flex-col items-center gap-4'}`}>
      <button
        type="button"
        onClick={() => setPreviewOpen(true)}
        className={`${compact ? 'h-14 w-14' : large ? 'h-36 w-36' : 'h-28 w-28'} relative flex shrink-0 items-center justify-center overflow-hidden rounded-full border-4 shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 ${accentClasses.avatar}`}
        aria-label="View profile picture"
      >
        {imageSource ? <img src={imageSource} alt={`${name || 'Profile'} profile`} className="h-full w-full object-cover" /> : <FaUser className={compact ? 'text-xl' : 'text-4xl'} />}
        {busy && <div className="absolute inset-0 flex items-center justify-center bg-slate-950/50 text-white"><FaSpinner className="animate-spin" /></div>}
      </button>
      <div className={displayOnly ? 'hidden' : (compact ? 'min-w-0' : 'w-full text-center')}>
        {!compact && <p className="font-semibold text-slate-900">{name || 'Profile picture'}</p>}
        {!displayOnly && <div className={`mt-2 flex ${compact ? 'flex-wrap' : 'justify-center'} gap-2`}>
          <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={handleFileChange} className="hidden" />
          <button type="button" onClick={selectFile} disabled={busy} className={`inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-60 ${accentClasses.button}`}><FaCamera /> {busy ? `Uploading ${progress}%` : 'Change Photo'}</button>
          {profilePicture && <button type="button" onClick={() => setConfirmAction('remove')} disabled={busy} className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"><FaTrash /> {removeLabel}</button>}
        </div>}
        {!compact && <p className="mt-2 flex items-center justify-center gap-1 text-xs text-slate-500"><FaImage /> JPG, PNG, or WEBP up to 5 MB</p>}
        {busy && (
          <div className="mt-3 w-full" aria-live="polite">
            <div className="mb-1 flex justify-between text-xs text-slate-500">
              <span>Uploading photo</span><span>{progress}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
              <div className="h-full rounded-full bg-cyan-700 transition-[width]" style={{ width: `${progress}%` }} />
            </div>
          </div>
        )}
        {message && <p role="status" className="mt-2 text-xs font-medium text-emerald-700">{message}</p>}
        {error && <p role="alert" className="mt-2 text-xs text-rose-700">{error}</p>}
      </div>
      <ProfileImagePreview open={previewOpen} imageUrl={imageSource} name={name} code={code} onClose={() => setPreviewOpen(false)} />
      {confirmAction && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-4" role="presentation">
          <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="profile-picture-confirm-title">
            <h2 id="profile-picture-confirm-title" className="text-lg font-semibold text-slate-900">{confirmAction === 'upload' ? 'Change profile picture?' : 'Remove profile picture?'}</h2>
            <p className="mt-2 text-sm text-slate-600">{confirmAction === 'upload' ? 'Use this selected image as your new profile picture?' : 'Are you sure you want to remove your current profile picture?'}</p>
            <div className="mt-6 flex justify-end gap-3">
              <button type="button" onClick={cancelConfirmation} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">Cancel</button>
              <button type="button" onClick={confirmAction === 'upload' ? uploadPicture : removePicture} disabled={busy} className={`rounded-xl px-4 py-2 text-sm font-medium text-white disabled:opacity-60 ${accentClasses.button}`}>{busy ? 'Processing...' : 'Yes, Confirm'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
