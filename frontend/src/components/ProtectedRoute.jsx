import { useEffect, useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { getStoredUser } from '../utils/authState'

function useCurrentUser() {
  const [user, setUser] = useState(getStoredUser)

  useEffect(() => {
    const refreshUser = () => setUser(getStoredUser())
    window.addEventListener('gym:auth-invalidated', refreshUser)
    window.addEventListener('gym:auth-updated', refreshUser)
    window.addEventListener('storage', refreshUser)
    return () => {
      window.removeEventListener('gym:auth-invalidated', refreshUser)
      window.removeEventListener('gym:auth-updated', refreshUser)
      window.removeEventListener('storage', refreshUser)
    }
  }, [])

  return user
}

export default function ProtectedRoute({ children, allowedRoles }) {
  const location = useLocation()
  const user = useCurrentUser()

  // Not authenticated -> redirect to login
  if (!user || !user.role) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  const role = (user.role || '').toString().toLowerCase().trim()

  // Role validation if allowedRoles is specified
  if (allowedRoles && allowedRoles.length > 0) {
    const normalizedAllowed = allowedRoles.map((r) => r.toLowerCase().trim())
    if (!normalizedAllowed.includes(role)) {
      // If user is logged in with different role, redirect to their respective dashboard
      if (role === 'admin' || role === 'administrator') {
        return <Navigate to="/dashboard" replace />
      }
      if (role === 'trainer') {
        return <Navigate to="/trainer" replace />
      }
      if (role === 'member') {
        return <Navigate to="/member/dashboard" replace />
      }
      return <Navigate to="/login" replace />
    }
  }

  if (role === 'member') {
    const approvalStatus = user.initial_approval?.status
    const isApprovalPage = location.pathname === '/member/pending-approval'
    if (approvalStatus && approvalStatus !== 'approved' && !isApprovalPage) {
      return <Navigate to="/member/pending-approval" replace />
    }
    if (isApprovalPage && (!approvalStatus || approvalStatus === 'approved')) {
      return <Navigate to="/member/dashboard" replace />
    }
  }

  return children
}

export function GuestOnlyRoute({ children }) {
  const user = useCurrentUser()
  const role = (user?.role || '').toString().toLowerCase().trim()

  if (role === 'admin' || role === 'administrator') return <Navigate to="/dashboard" replace />
  if (role === 'trainer') return <Navigate to="/trainer" replace />
  if (role === 'member') {
    const approvalStatus = user?.initial_approval?.status
    return <Navigate to={approvalStatus && approvalStatus !== 'approved' ? '/member/pending-approval' : '/member/dashboard'} replace />
  }

  return children
}
