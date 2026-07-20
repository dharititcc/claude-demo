import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { keepPreviousData } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { customerService } from '@/services/customers'
import { apiErrorMessage } from './useAuth'
import { useAuthStore } from '@/store/auth'
import type { CustomerFilters, CustomerPayload } from '@/types'

/**
 * Cache keys are scoped by organization: switching orgs must never surface
 * another organization's rows from cache.
 */
export function customerKeys(orgSlug: string | null) {
  return {
    all: ['customers', orgSlug] as const,
    list: (filters: CustomerFilters) => ['customers', orgSlug, 'list', filters] as const,
    detail: (id: number) => ['customers', orgSlug, 'detail', id] as const,
  }
}

export function useCustomers(filters: CustomerFilters) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: customerKeys(orgSlug).list(filters),
    queryFn: () => customerService.list(filters),
    // Keep the previous page visible while the next loads, so the table doesn't
    // collapse to a spinner on every keystroke or page change.
    placeholderData: keepPreviousData,
    enabled: Boolean(orgSlug),
  })
}

export function useCustomer(id: number | null) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: customerKeys(orgSlug).detail(id ?? 0),
    queryFn: () => customerService.get(id as number),
    enabled: Boolean(orgSlug && id),
  })
}

export function useCreateCustomer() {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CustomerPayload) => customerService.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: customerKeys(orgSlug).all })
      toast.success('Customer created.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not create the customer.')),
  })
}

export function useUpdateCustomer() {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<CustomerPayload> }) =>
      customerService.update(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: customerKeys(orgSlug).all })
      toast.success('Customer updated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the customer.')),
  })
}

export function useDeleteCustomer() {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => customerService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: customerKeys(orgSlug).all })
      toast.success('Customer deleted.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not delete the customer.')),
  })
}
