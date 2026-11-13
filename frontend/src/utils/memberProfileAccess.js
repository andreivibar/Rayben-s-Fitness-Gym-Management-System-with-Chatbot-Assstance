export function canAccessMemberProfile(user) {
  return Boolean(user && String(user.role || '').toLowerCase().trim() === 'member')
}
