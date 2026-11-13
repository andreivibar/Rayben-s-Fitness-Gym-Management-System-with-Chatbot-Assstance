import api from '../services/api'
import { clearAuthState, getStoredUser } from './authState'

export { clearAuthState, getStoredUser }

export async function logout(navigate, destination = '/login') {
  const user = getStoredUser()
  const revokeRequest = user?.auth_token
    ? api.post('/logout.php', {}, {
      timeout: 3000,
      headers: {
        Authorization: `Bearer ${user.auth_token}`,
        'X-Auth-Token': user.auth_token,
        ...(user.email ? { 'X-User-Email': user.email } : {})
      }
    })
    : Promise.resolve()

  clearAuthState()
  navigate(destination, { replace: true })
  try {
    await revokeRequest
  } catch {
    try {
      sessionStorage.setItem('gym-logout-server-warning', '1')
    } catch {
      // Navigation and local logout have already completed.
    }
    window.dispatchEvent(new Event('gym:logout-server-unavailable'))
  }
}
