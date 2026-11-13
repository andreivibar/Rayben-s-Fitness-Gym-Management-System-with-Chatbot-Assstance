import { useState } from 'react'

export default function ResetPasswordModal({
  open,
  onClose,
  onConfirm,
  member,
  accountType = 'member',
  loading = false,
  externalError = '',
}) {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState('')

  if (!open) return null

  const handleSubmit = () => {
    if (loading) return
    setError('')
    if (!password || password.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }
    if (password !== confirm) {
      setError('Passwords do not match.')
      return
    }
    onConfirm(password)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="w-full max-w-md rounded-2xl bg-white p-6">
        <h3 className="text-lg font-semibold">Reset password for {member?.fullName || member?.email}</h3>
        <p className="mt-2 text-sm text-slate-600">Enter a new password for this {accountType}.</p>
        <div className="mt-4 space-y-3">
          <input
            type="password"
            placeholder="New password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={loading}
            className="w-full rounded-xl border px-4 py-2"
          />
          <input
            type="password"
            placeholder="Confirm password"
            autoComplete="new-password"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            disabled={loading}
            className="w-full rounded-xl border px-4 py-2"
          />
          {(error || externalError) && <p role="alert" className="text-xs text-rose-600">{error || externalError}</p>}
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <button onClick={onClose} disabled={loading} className="rounded-lg px-4 py-2 disabled:opacity-50">Cancel</button>
          <button onClick={handleSubmit} disabled={loading} className="rounded-lg bg-emerald-600 px-4 py-2 text-white disabled:opacity-50">
            {loading ? 'Resetting...' : 'Reset'}
          </button>
        </div>
      </div>
    </div>
  )
}
