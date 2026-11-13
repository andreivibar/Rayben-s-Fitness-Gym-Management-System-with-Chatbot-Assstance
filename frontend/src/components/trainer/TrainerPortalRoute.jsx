import { Outlet } from 'react-router-dom'
import { getStoredUser } from '../../utils/authState'
import TrainerPortalLayout from './TrainerPortalLayout.jsx'
import TrainerPortalProvider from './TrainerPortalProvider.jsx'

export default function TrainerPortalRoute() {
  const user = getStoredUser()
  const role = String(user?.role || '').toLowerCase().trim()

  if (role !== 'trainer') return <Outlet />

  return (
    <TrainerPortalProvider>
      <TrainerPortalLayout />
    </TrainerPortalProvider>
  )
}
