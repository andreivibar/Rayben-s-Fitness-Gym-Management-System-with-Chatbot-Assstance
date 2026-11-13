const apiRoot = import.meta.env.VITE_PROFILE_IMAGE_BASE_URL || (
  import.meta.env.DEV
    ? 'http://localhost/Gym-Management-System/backend/'
    : `${window.location.origin}/Gym-Management-System/backend/`
)

export function profilePictureUrl(path) {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  return `${apiRoot.replace(/\/$/, '')}/${path.replace(/^\//, '')}`
}
