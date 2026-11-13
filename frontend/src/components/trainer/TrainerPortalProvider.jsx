import { useEffect, useMemo, useRef, useState } from 'react'
import api from '../../services/api'
import { getStoredUser } from '../../utils/authState'
import { TrainerPortalContext } from './TrainerPortalContext.js'

export default function TrainerPortalProvider({ children }) {
  const user = getStoredUser()
  const [trainer, setTrainer] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const profileRequestRef = useRef(null)

  useEffect(() => {
    let active = true
    const loadProfile = async () => {
      if (String(user?.role || '').toLowerCase().trim() !== 'trainer') {
        setError('Your trainer session could not be verified. Sign in again.')
        setLoading(false)
        return
      }
      let request
      try {
        request = profileRequestRef.current
        if (!request) {
          request = api.post('/trainer_profile.php', { email: user.email })
          profileRequestRef.current = request
        }
        const response = await request
        if (!response.data?.success) throw new Error(response.data?.message || 'Unable to load trainer profile.')
        if (active) {
          setTrainer(response.data.trainer)
          setError('')
        }
      } catch (requestError) {
        console.error('Unable to load trainer portal profile:', requestError)
        if (active) setError('Unable to load your trainer profile. Please refresh or sign in again.')
      } finally {
        if (profileRequestRef.current === request) profileRequestRef.current = null
        if (active) setLoading(false)
      }
    }
    void loadProfile()
    return () => { active = false }
  }, [user?.email, user?.role])

  const value = useMemo(() => ({ trainer, setTrainer, loading, error }), [error, loading, trainer])
  return <TrainerPortalContext.Provider value={value}>{children}</TrainerPortalContext.Provider>
}
