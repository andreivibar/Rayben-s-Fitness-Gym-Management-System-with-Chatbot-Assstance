import { useCallback, useEffect, useState } from 'react'
import { FaBell, FaCheck } from 'react-icons/fa'
import api from '../services/api'

function formatCreatedAt(value) {
  if (!value) return ''
  const normalized = String(value).includes('T') ? String(value) : `${String(value).replace(' ', 'T')}+08:00`
  return new Date(normalized).toLocaleString('en-PH', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

export default function NotificationCenter() {
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [error, setError] = useState('')

  const loadNotifications = useCallback(async () => {
    try {
      const response = await api.get('/notifications.php', { params: { action: 'list' } })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load notifications.')
      setItems(response.data.notifications || [])
      setUnreadCount(Number(response.data.unread_count || 0))
      setError('')
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to load notifications.')
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(loadNotifications)
  }, [loadNotifications])

  const markRead = async (id) => {
    try {
      const response = await api.post('/notifications.php?action=mark-read', { notification_id: id })
      if (!response.data?.success) throw new Error(response.data?.message || 'Unable to mark notification read.')
      setItems((current) => current.map((item) => item.id === id ? { ...item, is_read: true } : item))
      setUnreadCount((current) => Math.max(0, current - 1))
    } catch (requestError) {
      setError(requestError?.response?.data?.message || requestError?.message || 'Unable to update notification.')
    }
  }

  return (
    <div className="relative">
      <button type="button" onClick={() => { setOpen((current) => !current); if (!open) loadNotifications() }} aria-label={`Notifications, ${unreadCount} unread`} aria-expanded={open} className="relative flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
        <FaBell />
        {unreadCount > 0 && <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-4 text-white">{unreadCount > 99 ? '99+' : unreadCount}</span>}
      </button>
      {open && (
        <section className="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
          <div className="border-b border-slate-100 px-4 py-3">
            <h2 className="text-sm font-semibold text-slate-900">Notifications</h2>
            <p className="text-xs text-slate-500">{unreadCount} unread</p>
          </div>
          <div className="max-h-96 overflow-y-auto">
            {error ? <p role="alert" className="p-4 text-sm text-rose-700">{error}</p> : items.length === 0 ? <p className="p-6 text-center text-sm text-slate-500">You have no notifications.</p> : (
              <ul className="divide-y divide-slate-100">
                {items.map((item) => (
                  <li key={item.id} className={`p-4 ${item.is_read ? 'bg-white' : 'bg-violet-50/60'}`}>
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold text-slate-900">{item.title}</p>
                        <p className="mt-1 text-xs leading-relaxed text-slate-600">{item.message}</p>
                        <p className="mt-2 text-[11px] text-slate-400">{formatCreatedAt(item.created_at)}</p>
                      </div>
                      {!item.is_read && <button type="button" onClick={() => markRead(item.id)} aria-label="Mark notification read" className="rounded-lg p-2 text-violet-700 hover:bg-violet-100"><FaCheck /></button>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>
      )}
    </div>
  )
}
