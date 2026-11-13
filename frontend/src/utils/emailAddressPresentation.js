export function getEmailAddressPresentation(email) {
  const currentEmail = typeof email === 'string' ? email.trim() : ''
  const hasEmail = currentEmail !== ''

  return {
    currentEmail,
    hasEmail,
    emptyMessage: hasEmail ? '' : 'No email address registered.',
    actionLabel: hasEmail ? 'Change Email Address' : 'Add Email Address',
  }
}
