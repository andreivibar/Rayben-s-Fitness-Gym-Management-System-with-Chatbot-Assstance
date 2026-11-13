import { useEffect, useState } from 'react'
import api from '../services/api.js'

async function fetchGcashSettings() {
  const response = await api.get('gcash_settings.php')
  if (!response.data?.success) {
    throw new Error(response.data?.message || 'Unable to load GCash settings.')
  }
  return response.data.settings || {}
}

function GcashSettingsPanel() {
  const [accountName, setAccountName] = useState('')
  const [accountNumber, setAccountNumber] = useState('')
  const [updatedBy, setUpdatedBy] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  useEffect(() => {
    let active = true
    fetchGcashSettings()
      .then((settings) => {
        if (!active) return
        setAccountName(settings.gcash_account_name || '')
        setAccountNumber(settings.gcash_account_number || '')
        setUpdatedBy(settings.updated_by_admin_name || '')
      })
      .catch((requestError) => {
        if (active) setError(requestError?.message || 'Unable to load GCash settings.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [])

  const retryLoading = async () => {
    setLoading(true)
    setError('')
    try {
      const settings = await fetchGcashSettings()
      setAccountName(settings.gcash_account_name || '')
      setAccountNumber(settings.gcash_account_number || '')
      setUpdatedBy(settings.updated_by_admin_name || '')
    } catch (requestError) {
      setError(requestError?.message || 'Unable to load GCash settings.')
    } finally {
      setLoading(false)
    }
  }

  const saveSettings = async (event) => {
    event.preventDefault()
    const cleanName = accountName.trim()
    const cleanNumber = accountNumber.replace(/[\s-]+/g, '')
    if (!cleanName || cleanName.length > 120) {
      setSuccess('')
      setError('Enter a GCash account name up to 120 characters.')
      return
    }
    if (!/^(09\d{9}|639\d{9})$/.test(cleanNumber)) {
      setSuccess('')
      setError('Enter a valid GCash mobile number (09xxxxxxxxx or 639xxxxxxxxx).')
      return
    }
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const response = await api.put('gcash_settings.php', {
        gcash_account_name: cleanName,
        gcash_account_number: cleanNumber,
      })
      if (!response.data?.success) {
        setError(response.data?.message || 'Unable to save GCash settings.')
        return
      }
      setAccountName(response.data.settings.gcash_account_name)
      setAccountNumber(response.data.settings.gcash_account_number)
      setUpdatedBy(response.data.settings.updated_by_admin_name || '')
      setSuccess(response.data.message || 'GCash details saved.')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || 'Unable to save GCash settings. Please retry.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h3 className="text-sm font-semibold text-slate-900">GCash Collection Details</h3>
      <p className="mt-1 text-xs text-slate-500">
        These details appear to members and applicants in the existing GCash payment instructions.
      </p>
      {loading ? (
        <p role="status" className="mt-4 text-sm text-slate-500">Loading GCash details...</p>
      ) : (
        <>
        <p className="mt-3 text-xs text-slate-500">
          Last updated by: <span className="font-medium text-slate-700">{updatedBy || 'Not available'}</span>
        </p>
        <form onSubmit={saveSettings} className="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <label className="text-xs font-semibold text-slate-700">
            Account name
            <input
              value={accountName}
              onChange={(event) => {
                setAccountName(event.target.value)
                setError('')
                setSuccess('')
              }}
              maxLength={120}
              required
              autoComplete="off"
              className="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100"
              placeholder="GCash account name"
            />
          </label>
          <label className="text-xs font-semibold text-slate-700">
            GCash mobile number
            <input
              value={accountNumber}
              onChange={(event) => {
                setAccountNumber(event.target.value)
                setError('')
                setSuccess('')
              }}
              maxLength={16}
              inputMode="tel"
              autoComplete="off"
              required
              className="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100"
              placeholder="09xxxxxxxxx"
            />
          </label>
          <button
            type="submit"
            disabled={saving || loading}
            className="min-h-11 rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-900 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Save details'}
          </button>
        </form>
        </>
      )}
      {error && (
        <div role="alert" className="mt-3 text-sm text-rose-700">
          <p>{error}</p>
          <button type="button" onClick={retryLoading} className="mt-1 font-semibold underline">
            Retry loading
          </button>
        </div>
      )}
      {success && <p role="status" className="mt-3 text-sm text-emerald-700">{success}</p>}
    </section>
  )
}

export default GcashSettingsPanel
