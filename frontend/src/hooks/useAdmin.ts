import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { adminService } from '@/services/admin'
import { beginImpersonation, endImpersonation } from '@/services/api'
import { apiErrorMessage } from './useAuth'
import { useAuthStore } from '@/store/auth'
import type {
  AdminActivityFilters,
  AdminOrgFilters,
  AdminPlanPayload,
  LimitOverrides,
} from '@/types/admin'

/**
 * Admin cache keys. Not org-scoped — these read across every organization, so
 * unlike the tenant hooks there is no active-org segment.
 */
export const adminKeys = {
  organizations: ['admin', 'organizations'] as const,
  organizationList: (filters: AdminOrgFilters) => ['admin', 'organizations', 'list', filters] as const,
  organization: (id: string) => ['admin', 'organizations', 'detail', id] as const,
  stats: ['admin', 'stats'] as const,
  activity: (filters: AdminActivityFilters) => ['admin', 'activity', filters] as const,
  plans: ['admin', 'plans'] as const,
}

export function useAdminStats() {
  return useQuery({
    queryKey: adminKeys.stats,
    queryFn: () => adminService.stats(),
    staleTime: 60_000,
  })
}

export function useAdminOrganizations(filters: AdminOrgFilters) {
  return useQuery({
    queryKey: adminKeys.organizationList(filters),
    queryFn: () => adminService.listOrganizations(filters),
    placeholderData: keepPreviousData,
  })
}

export function useAdminOrganization(id: string | null) {
  return useQuery({
    queryKey: adminKeys.organization(id ?? ''),
    queryFn: () => adminService.getOrganization(id as string),
    enabled: Boolean(id),
  })
}

export function useAdminActivity(filters: AdminActivityFilters) {
  return useQuery({
    queryKey: adminKeys.activity(filters),
    queryFn: () => adminService.listActivity(filters),
    placeholderData: keepPreviousData,
  })
}

/** Refresh everything the admin screens read after a mutation changes an org. */
function useInvalidateAdmin() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['admin'] })
}

export function useSuspendOrganization() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (id: string) => adminService.suspend(id),
    onSuccess: () => {
      invalidate()
      toast.success('Organization suspended.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not suspend the organization.')),
  })
}

export function useActivateOrganization() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (id: string) => adminService.activate(id),
    onSuccess: () => {
      invalidate()
      toast.success('Organization activated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not activate the organization.')),
  })
}

export function useDeleteOrganization() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (id: string) => adminService.remove(id),
    onSuccess: () => {
      invalidate()
      toast.success('Organization deleted. It can be restored until it is purged.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not delete the organization.')),
  })
}

export function useRestoreOrganization() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (id: string) => adminService.restore(id),
    onSuccess: () => {
      invalidate()
      toast.success('Organization restored.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not restore the organization.')),
  })
}

export function useUpdateOrganization() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Record<string, unknown> }) =>
      adminService.updateOrganization(id, payload),
    onSuccess: () => {
      invalidate()
      toast.success('Organization updated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the organization.')),
  })
}

export function useUpdateLimits() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: ({ id, overrides }: { id: string; overrides: LimitOverrides }) =>
      adminService.updateLimits(id, overrides),
    onSuccess: () => {
      invalidate()
      toast.success('Limits updated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the limits.')),
  })
}

/**
 * Start impersonating. On success we swap to the impersonation token, drop all
 * cached (admin-context) data, and drop the user into the normal app — where the
 * banner will show they are impersonating.
 */
export function useImpersonate() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ orgId, userId }: { orgId: string; userId?: number }) =>
      adminService.impersonate(orgId, userId),
    onSuccess: (session) => {
      beginImpersonation(session.token, session.organization.slug)
      // Wipe every cache entry so nothing from the admin session bleeds into the
      // impersonated one.
      queryClient.clear()
      toast.success(`Now acting as ${session.user.email}.`)
      navigate('/dashboard', { replace: true })
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not start impersonation.')),
  })
}

/**
 * Stop impersonating: revoke the token server-side, then restore the parked
 * admin session. If the admin token was lost (e.g. a hard reload cleared it),
 * fall back to the login screen rather than leaving a dead session.
 */
export function useStopImpersonation() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const clear = useAuthStore((s) => s.clear)

  return useMutation({
    mutationFn: () => adminService.stopImpersonation(),
    onSettled: () => {
      const restored = endImpersonation()
      queryClient.clear()

      if (restored) {
        toast.success('Returned to your admin session.')
        navigate('/admin/organizations', { replace: true })
      } else {
        clear()
        navigate('/login', { replace: true })
      }
    },
  })
}

// ─── Plan master ───

export function useAdminPlans() {
  return useQuery({
    queryKey: adminKeys.plans,
    queryFn: () => adminService.listPlans(),
    staleTime: 60_000,
  })
}

export function useCreatePlan() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (payload: AdminPlanPayload) => adminService.createPlan(payload),
    onSuccess: () => {
      invalidate()
      toast.success('Plan created.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not create the plan.')),
  })
}

export function useUpdatePlan() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: AdminPlanPayload }) =>
      adminService.updatePlan(id, payload),
    onSuccess: () => {
      invalidate()
      toast.success('Plan updated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the plan.')),
  })
}

/**
 * Deleting is refused server-side while organizations are on the plan, and the
 * 422 explains why — so the error message is surfaced as-is rather than being
 * replaced with a generic one.
 */
export function useDeletePlan() {
  const invalidate = useInvalidateAdmin()
  return useMutation({
    mutationFn: (id: number) => adminService.deletePlan(id),
    onSuccess: () => {
      invalidate()
      toast.success('Plan deleted.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not delete the plan.')),
  })
}
