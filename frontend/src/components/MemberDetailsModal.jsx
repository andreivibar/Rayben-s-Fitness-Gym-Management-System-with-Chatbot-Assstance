import { FaTimes } from 'react-icons/fa'
import ProfilePictureEditor from './ProfilePictureEditor.jsx'

const categoryLabels = {
  regular: 'Regular',
  student: 'Student',
  senior: 'Senior',
  pwd: 'PWD',
}

function MemberDetailsModal({
  open,
  member,
  onClose,
  onPictureUpdated,
}) {
  if (!open || !member) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-8">
      <div className="flex w-full max-w-3xl max-h-full flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-5">
          <div>
            <p className="text-sm uppercase tracking-[0.28em] text-slate-500">Member details</p>
            <h2 className="mt-2 text-2xl font-semibold text-slate-900">{member.fullName}</h2>
          </div>
          <button type="button" onClick={onClose} className="rounded-2xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900">
            <FaTimes />
          </button>
        </div>

        <div className="grid flex-1 gap-6 overflow-y-auto px-6 py-6 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="space-y-6">
            <div className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <ProfilePictureEditor entity="member" targetId={member.id} profilePicture={member.profilePicture} name={member.fullName} code={member.memberCode} onUpdated={onPictureUpdated} />
            </div>
            <div className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Member Code</p>
              <p className="mt-2 text-2xl font-semibold break-all text-slate-900">{member.memberCode}</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Email</p>
                <p className="mt-2 break-all text-slate-900">{member.email}</p>
              </div>
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Phone</p>
                <p className="mt-2 break-words text-slate-900">{member.phone}</p>
              </div>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Address</p>
              <p className="mt-2 break-words text-slate-900">{member.address}</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Date of Birth</p>
                <p className="mt-2 text-slate-900">{member.dob}</p>
              </div>
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Gender</p>
                <p className="mt-2 text-slate-900">{member.gender || 'Not specified'}</p>
              </div>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Registered</p>
              <p className="mt-2 text-slate-900">{member.dateRegistered}</p>
            </div>
          </div>

          <div className="space-y-6">
            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Membership Service</p>
              <p className="mt-2 text-slate-900">{member.membershipType}</p>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Membership Category</p>
                  <p className="mt-2 text-slate-900">{categoryLabels[member.memberCategory] || 'Regular'}</p>
                </div>
              </div>
              <div className="mt-4 border-t border-slate-100 pt-4">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Manual Gym Access</p>
                <p className={`mt-2 font-semibold ${member.gymAccessControlStatus === 'restricted' ? 'text-rose-700' : 'text-emerald-700'}`}>
                  {member.gymAccessControlStatus === 'restricted' ? 'Restricted' : 'Allowed'}
                </p>
                <p className="mt-1 text-xs text-slate-500">Entry still requires a valid paid subscription or verified day pass.</p>
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Start Date</p>
                <p className="mt-2 text-slate-900">{member.membershipStart || 'N/A'}</p>
              </div>
              <div className="rounded-3xl border border-slate-200 bg-white p-5">
                <p className="text-sm uppercase tracking-[0.24em] text-slate-500">End Date</p>
                <p className="mt-2 text-slate-900">{member.membershipEnd || 'N/A'}</p>
              </div>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Membership Status</p>
              <span className={`mt-3 inline-flex rounded-full px-4 py-2 text-sm font-semibold ${member.status === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                {member.status}
              </span>
            </div>

            <div className="rounded-3xl border border-slate-200 bg-white p-5">
              <p className="text-sm uppercase tracking-[0.24em] text-slate-500">Account Status</p>
              <span className={`mt-3 inline-flex rounded-full px-4 py-2 text-sm font-semibold ${member.accountStatusKey === 'deactivated' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-700'}`}>
                {member.accountStatus}
              </span>
            </div>

          </div>
        </div>
      </div>
    </div>
  )
}

export default MemberDetailsModal
