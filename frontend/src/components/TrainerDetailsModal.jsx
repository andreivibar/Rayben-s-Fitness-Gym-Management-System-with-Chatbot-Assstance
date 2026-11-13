import { FaTimes } from 'react-icons/fa'
import ProfilePictureEditor from './ProfilePictureEditor.jsx'

export default function TrainerDetailsModal({ open, trainer, onClose, onPictureUpdated }) {
  if (!open || !trainer) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-8">
      <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-sm uppercase tracking-[0.28em] text-slate-500">Trainer details</p>
            <h2 className="mt-2 text-2xl font-semibold text-slate-900">{trainer.full_name}</h2>
          </div>
          <button type="button" onClick={onClose} className="rounded-2xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900">
            <FaTimes />
          </button>
        </div>
        <div className="px-6 py-6">
          <div className="mb-6 rounded-3xl border border-slate-200 bg-slate-50 p-5">
            <ProfilePictureEditor entity="trainer" targetId={trainer.trainer_id} profilePicture={trainer.profile_picture} name={trainer.full_name} code={trainer.trainer_code} onUpdated={onPictureUpdated} />
          </div>
          <div className="space-y-3 text-sm text-slate-700">
            <p><strong>Trainer Code:</strong> {trainer.trainer_code}</p>
            <p><strong>Email:</strong> {trainer.email}</p>
            <p><strong>Phone:</strong> {trainer.phone || '—'}</p>
            <p><strong>Status:</strong> {trainer.status}</p>
            <p><strong>Created:</strong> {new Date(trainer.created_at).toLocaleString()}</p>
          </div>
          <div className="mt-6 text-right"><button onClick={onClose} className="rounded-2xl bg-violet-700 px-4 py-2 text-white">Close</button></div>
        </div>
      </div>
    </div>
  )
}
