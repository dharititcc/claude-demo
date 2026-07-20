import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getToken } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { useMe } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'

/**
 * Gate for the Super Admin area.
 *
 * Like ProtectedRoute, this is a UX guard, not the security boundary — the API
 * returns 404 to a non-super-admin on every admin route regardless of what the
 * SPA renders. Its job is to avoid showing an admin shell that would only 404,
 * and to keep the admin section off the radar of ordinary users.
 */
export function AdminRoute() {
  const location = useLocation()
  const hasToken = Boolean(getToken())
  const user = useAuthStore((s) => s.user)
  const { isLoading } = useMe()

  if (!hasToken) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />
  }

  // Wait for the session to resolve before deciding — otherwise a super admin
  // refreshing an admin page would be bounced to the dashboard on first paint.
  if (isLoading && !user) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (!user?.is_super_admin) {
    return <Navigate to="/dashboard" replace />
  }

  return <Outlet />
}
