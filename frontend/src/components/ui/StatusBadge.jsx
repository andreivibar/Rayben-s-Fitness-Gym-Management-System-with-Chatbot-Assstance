export default function StatusBadge({ status, className = '' }) {
  const normalized = String(status || '').trim().toLowerCase()

  const styles = {
    active: 'bg-emerald-100 text-emerald-700 border border-emerald-200',
    approved: 'bg-emerald-100 text-emerald-700 border border-emerald-200',
    confirmed: 'bg-emerald-100 text-emerald-700 border border-emerald-200',
    completed: 'bg-emerald-100 text-emerald-700 border border-emerald-200',
    pending: 'bg-amber-100 text-amber-700 border border-amber-200',
    awaiting_member: 'bg-violet-100 text-violet-700 border border-violet-200',
    awaiting_payment: 'bg-amber-100 text-amber-800 border border-amber-300',
    inactive: 'bg-rose-100 text-rose-700 border border-rose-200',
    expired: 'bg-rose-100 text-rose-700 border border-rose-200',
    rejected: 'bg-rose-100 text-rose-700 border border-rose-200',
    cancelled: 'bg-slate-200 text-slate-700 border border-slate-300',
    default: 'bg-slate-100 text-slate-700 border border-slate-200',
  }

  const color = styles[normalized] || styles.default

  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${color} ${className}`}>
      {normalized === 'awaiting_member'
        ? 'Awaiting member response'
        : normalized === 'awaiting_payment'
          ? 'Awaiting payment'
          : status}
    </span>
  )
}
