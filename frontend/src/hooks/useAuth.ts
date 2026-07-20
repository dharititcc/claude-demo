import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { authService } from '@/services/auth'
import { getToken } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import type {
  ApiError,
  LoginCredentials,
  RegisterPayload,
  TwoFactorChallengePayload,
} from '@/types'
import { isAxiosError } from 'axios'

/** Turn an Axios failure into a message worth showing a human. */
export function apiErrorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (isAxiosError<ApiError>(error)) {
    const data = error.response?.data
    // Prefer the first field-level error; it is more specific than the summary.
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return firstFieldError ?? data?.message ?? fallback
  }
  return fallback
}

/**
 * Loads the signed-in user. Only runs when a token exists, so anonymous
 * visitors don't fire a guaranteed-401 request on every page load.
 */
export function useMe() {
  const setUser = useAuthStore((s) => s.setUser)
  const setImpersonation = useAuthStore((s) => s.setImpersonation)

  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      const { user, impersonation } = await authService.me()
      setUser(user)
      // Server-authoritative: this is what drives the "you are impersonating"
      // banner, not any client-side guess.
      setImpersonation(impersonation)
      return user
    },
    enabled: Boolean(getToken()),
    staleTime: 5 * 60_000,
    retry: false,
  })
}

/** Role and permissions for the active organization. */
export function useOrgContext() {
  const activeOrgSlug = useAuthStore((s) => s.activeOrgSlug)
  const setPermissions = useAuthStore((s) => s.setPermissions)

  return useQuery({
    queryKey: ['context', activeOrgSlug],
    queryFn: async () => {
      const context = await authService.context()
      setPermissions(context.permissions)
      return context
    },
    enabled: Boolean(getToken() && activeOrgSlug),
    staleTime: 5 * 60_000,
    retry: false,
  })
}

/**
 * Sign in with a password.
 *
 * `onChallenge` fires when the account has 2FA and the password alone was not
 * enough. The caller must handle it — a successful password is not a session.
 */
export function useLogin(onChallenge?: (challengeToken: string) => void) {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (credentials: LoginCredentials) => authService.login(credentials),
    onSuccess: (result) => {
      if (result.status === 'two_factor_required') {
        onChallenge?.(result.challengeToken)
        return
      }

      const { user, organizations } = result.session
      setSession(user, organizations)
      queryClient.clear()
      toast.success(`Welcome back, ${user.name.split(' ')[0]}`)
      // A super admin belongs to no organization, so the org-scoped dashboard
      // has nothing to show them — send them straight to the control plane.
      const landing = user.is_super_admin && organizations.length === 0 ? '/admin' : '/dashboard'
      navigate(landing, { replace: true })
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not sign you in.')),
  })
}

/** Second half of a 2FA sign-in: challenge + code becomes a session. */
export function useTwoFactorChallenge() {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: TwoFactorChallengePayload) =>
      authService.completeTwoFactorChallenge(payload),
    onSuccess: (data) => {
      setSession(data.user, data.organizations)
      queryClient.clear()
      toast.success(`Welcome back, ${data.user.name.split(' ')[0]}`)
      navigate('/dashboard', { replace: true })
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'That code was not accepted.')),
  })
}

export function useRegister() {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)

  return useMutation({
    mutationFn: (payload: RegisterPayload) => authService.register(payload),
    onSuccess: (data) => {
      setSession(data.user, [data.organization])
      toast.success('Your organization is ready.')
      navigate('/dashboard', { replace: true })
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not create your account.')),
  })
}

export function useLogout() {
  const navigate = useNavigate()
  const clear = useAuthStore((s) => s.clear)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => authService.logout(),
    // Clear local state on settled, not success: if the request fails the user
    // still expects to be signed out.
    onSettled: () => {
      clear()
      queryClient.clear()
      navigate('/login', { replace: true })
    },
  })
}
