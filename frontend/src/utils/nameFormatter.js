/**
 * Helper to format a user's full name from separated name components.
 * 
 * Rules:
 *  - Format: first_name + (middle_name ? ' ' + middle_name : '') + ' ' + last_name
 *  - Handles objects: formatFullName({ first_name, middle_name, last_name, full_name })
 *  - Handles positional args: formatFullName(first_name, middle_name, last_name)
 *  - Falls back gracefully to legacy `full_name` or non-empty parts.
 */
export function formatFullName(firstOrObject, middle, last) {
  if (!firstOrObject) return ''

  if (typeof firstOrObject === 'object') {
    const { first_name, middle_name, last_name, full_name } = firstOrObject
    const trimmedFirst = (first_name || '').trim()
    const trimmedMiddle = (middle_name || '').trim()
    const trimmedLast = (last_name || '').trim()

    if (trimmedFirst || trimmedLast) {
      return [trimmedFirst, trimmedMiddle, trimmedLast].filter(Boolean).join(' ')
    }
    return (full_name || '').trim()
  }

  const trimmedFirst = (firstOrObject || '').trim()
  const trimmedMiddle = (middle || '').trim()
  const trimmedLast = (last || '').trim()

  if (trimmedFirst || trimmedLast) {
    return [trimmedFirst, trimmedMiddle, trimmedLast].filter(Boolean).join(' ')
  }

  return ''
}

export default formatFullName
