import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getToken } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { useMe } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'

/**
 * Gate for authenticated routes.
 *
 * This is a UX guard, not a security boundary — the API authorizes every
 * request independently. Its job is to avoid rendering an app shell that would
 * immediately 401.
 */
export function ProtectedRoute() {
  const location = useLocation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const hasToken = Boolean(getToken())

  // Revalidate the persisted session against the API on load; a token may have
  // been revoked server-side since the store was written to localStorage.
  const { isLoading, isError } = useMe()

  if (!hasToken) {
    // Remember where they were headed so login can send them back.
    return <Navigate to="/login" state={{ from: location.pathname }} replace />
  }

  if (isLoading && !isAuthenticated) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (isError) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />
  }

  return <Outlet />
}

/**
 * Redirects signed-in users away from login/register.
 */
export function GuestRoute() {
  const hasToken = Boolean(getToken())

  return hasToken ? <Navigate to="/dashboard" replace /> : <Outlet />
}
