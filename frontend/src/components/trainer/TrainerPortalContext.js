import { createContext, useContext } from 'react'

export const TrainerPortalContext = createContext(null)

export function useTrainerPortal() {
  const context = useContext(TrainerPortalContext)
  if (!context) throw new Error('useTrainerPortal must be used within TrainerPortalProvider.')
  return context
}
