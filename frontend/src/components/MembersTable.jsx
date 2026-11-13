import { FaEdit, FaEye, FaLock, FaExchangeAlt, FaUser, FaUserTie } from 'react-icons/fa'
import ActionMenu from './ui/ActionMenu.jsx'
import { formatFullName } from '../utils/nameFormatter'
import { profilePictureUrl } from '../utils/profilePictureUrl'

function MembersTable({ members, onView, onEdit, onPromote, onStatusChange, onResetPassword }) {
  return (
    <div className="min-w-0 overflow-hidden rounded-b-2xl bg-white">
      <div className="max-h-[min(74vh,900px)] min-w-0 overflow-auto overscroll-contain">
        <table className="w-full min-w-[1470px] table-fixed border-separate border-spacing-0 text-left text-sm text-slate-700">
          <colgroup>
            <col className="w-[115px]" />
            <col className="w-[185px]" />
            <col className="w-[200px]" />
            <col className="w-[135px]" />
            <col className="w-[115px]" />
            <col className="w-[95px]" />
            <col className="w-[95px]" />
            <col className="w-[190px]" />
            <col className="w-[150px]" />
            <col className="w-[190px]" />
          </colgroup>
          <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] font-semibold uppercase tracking-wider text-slate-600 shadow-[0_1px_0_0_rgb(226_232_240)]">
            <tr>
              <th scope="col" className="px-3 py-3">Member Code</th>
              <th scope="col" className="px-3 py-3">Name</th>
              <th scope="col" className="px-3 py-3">Contact</th>
              <th scope="col" className="px-3 py-3">Gender</th>
              <th scope="col" className="px-3 py-3">Type</th>
              <th scope="col" className="px-3 py-3">Start</th>
              <th scope="col" className="px-3 py-3">End</th>
              <th scope="col" className="px-3 py-3">Gym Access</th>
              <th scope="col" className="px-3 py-3">Account Status</th>
              <th scope="col" className="px-3 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {members.length > 0 ? (
              members.map((member) => {
                const displayName = formatFullName(member.firstName, member.middleName, member.lastName) || member.fullName
                const actionItems = [
                  {
                    label: member.accountRole === 'member' && member.accountUserId && member.accountStatusKey === 'active'
                      ? 'Promote to Trainer'
                      : null,
                    icon: <FaUserTie />,
                    onClick: () => onPromote?.(member),
                  },
                  {
                    label: member.accountStatusKey === 'deactivated' ? 'Reactivate' : 'Deactivate',
                    icon: <FaExchangeAlt />,
                    onClick: () => {
                      const accountStatus = member.accountStatusKey === 'deactivated' ? 'active' : 'deactivated'
                      onStatusChange?.(member, accountStatus)
                    },
                  },
                  {
                    label: 'Reset Password',
                    icon: <FaLock />,
                    onClick: () => onResetPassword?.(member),
                  },
                ].filter((action) => action.label)

                return (
                  <tr key={member.id} className="align-middle transition-colors hover:bg-slate-50/80">
                    <td className="px-3 py-3 font-semibold text-slate-900">
                      <span className="whitespace-nowrap">{member.memberCode}</span>
                    </td>
                    <td className="px-3 py-3">
                      <div className="flex min-w-0 items-center gap-3">
                        <div className="relative flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-violet-100 bg-violet-50 text-violet-700">
                          <FaUser className="text-sm" />
                          {member.profilePicture && (
                            <img
                              src={profilePictureUrl(member.profilePicture)}
                              alt={`${displayName || 'Member'} profile`}
                              className="absolute inset-0 h-full w-full object-cover"
                              onError={(event) => { event.currentTarget.style.display = 'none' }}
                            />
                          )}
                        </div>
                        <span className="min-w-0 break-words font-semibold text-slate-900">{displayName}</span>
                      </div>
                    </td>
                    <td className="px-3 py-3">
                      <p className="break-all font-medium text-slate-900">{member.email || '—'}</p>
                      <p className="mt-0.5 whitespace-nowrap text-xs text-slate-500">{member.phone || '—'}</p>
                    </td>
                    <td className="px-3 py-3 font-medium text-slate-900">
                      <span className="whitespace-nowrap">{member.gender || 'Not specified'}</span>
                    </td>
                    <td className="px-3 py-3">
                      <span className="inline-flex whitespace-nowrap rounded-full bg-violet-100 px-2.5 py-1 text-xs font-semibold text-violet-700">
                        {member.membershipType === 'Monthly Subscription'
                          ? 'Monthly'
                          : member.membershipType === '1 Day Pass'
                            ? '1 Day Pass'
                            : '1 Day Pass (legacy)'}
                      </span>
                    </td>
                    <td className="px-3 py-3 text-xs font-medium text-slate-900">
                      <span className="whitespace-nowrap">{member.membershipStart || '—'}</span>
                    </td>
                    <td className="px-3 py-3 text-xs font-medium text-slate-900">
                      <span className="whitespace-nowrap">{member.membershipEnd || '—'}</span>
                    </td>
                    <td className="px-3 py-3">
                      {member.gymAccessControlStatus === 'restricted' ? (
                        <div className="space-y-1">
                          <span className="inline-flex whitespace-nowrap rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700">
                            Restricted
                          </span>
                          <p className="text-[11px] leading-4 text-slate-500">Eligibility: {member.gymAccessStatus}</p>
                        </div>
                      ) : (
                        <span
                          className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${
                            member.gymAccessStatus === 'Active Monthly Subscription'
                              || member.gymAccessStatus === 'Valid 1 Day Pass'
                              ? 'bg-emerald-100 text-emerald-700'
                              : member.gymAccessStatus === 'Pending Payment or Approval'
                                ? 'bg-amber-100 text-amber-700'
                                : 'bg-slate-100 text-slate-700'
                          }`}
                        >
                          {member.gymAccessStatus}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-3">
                      <div className="flex flex-col items-start gap-1">
                        <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${
                          member.accountStatusKey === 'deactivated'
                            ? 'bg-slate-100 text-slate-700'
                            : 'bg-emerald-100 text-emerald-700'
                        }`}>
                          {member.accountStatus}
                        </span>
                        {member.accountRole && (
                          <span className="text-[11px] font-medium capitalize leading-4 text-slate-500">
                            {member.accountRole} account
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-3">
                      <div className="flex items-center justify-end gap-1.5 whitespace-nowrap">
                        <button
                          type="button"
                          onClick={() => onView(member)}
                          title="View details"
                          aria-label={`View ${displayName}`}
                          className="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-slate-200 bg-white px-2 text-xs font-medium text-slate-700 transition hover:bg-slate-100"
                        >
                          <FaEye /> <span>View</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => onEdit(member)}
                          title="Edit member profile"
                          aria-label={`Edit ${displayName}`}
                          className="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-slate-200 bg-white px-2 text-xs font-medium text-slate-700 transition hover:bg-slate-100"
                        >
                          <FaEdit /> <span>Edit</span>
                        </button>
                        <ActionMenu actions={actionItems} />
                      </div>
                    </td>
                  </tr>
                )
              })
            ) : (
              <tr>
                <td colSpan={10} className="px-5 py-12 text-center text-sm text-slate-500">
                  No members match your search or filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export default MembersTable
