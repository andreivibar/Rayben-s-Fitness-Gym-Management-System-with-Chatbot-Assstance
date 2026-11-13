export function getStoredUser() {
  for (const getStorage of [() => localStorage, () => sessionStorage]) {
    try {
      const storage = getStorage()
      const raw = storage.getItem('user')
      if (raw) {
        const user = JSON.parse(raw)
        if (user?.role && user?.auth_token) return user
      }
    } catch {
      // Check the next storage location.
    }
  }
  return null
}

export function persistStoredUser(user) {
  let lastError = null
  for (const getStorage of [() => localStorage, () => sessionStorage]) {
    try {
      getStorage().setItem('user', JSON.stringify(user))
      window.dispatchEvent(new Event('gym:auth-updated'))
      return
    } catch (error) {
      lastError = error
    }
  }
  throw lastError || new Error('Unable to save the login session.')
}

export function clearAuthState() {
  for (const getStorage of [() => localStorage, () => sessionStorage]) {
    try {
      getStorage().removeItem('user')
    } catch {
      // Continue clearing other relevant state.
    }
  }
  try {
    localStorage.removeItem('gym-attendance-qr-token')
  } catch {
    // QR display cache is non-sensitive and expires server-side.
  }
}
