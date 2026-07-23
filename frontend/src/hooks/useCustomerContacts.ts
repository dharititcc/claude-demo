import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { customerService } from '@/services/customers'
import { apiErrorMessage } from './useAuth'
import { useAuthStore } from '@/store/auth'
import type { CustomerContactPayload } from '@/types'

/**
 * Contacts of one customer.
 *
 * Keys are scoped by organization for the same reason as customerKeys:
 * switching orgs must never surface another organization's rows from cache.
 */
export function contactKeys(orgSlug: string | null, customerId: number) {
  return {
    list: (params: { q?: string; status?: string }) =>
      ['customers', orgSlug, 'detail', customerId, 'contacts', params] as const,
  }
}

export function useCustomerContacts(customerId: number, params: { q?: string; status?: string } = {}) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: contactKeys(orgSlug, customerId).list(params),
    queryFn: () => customerService.contacts(customerId, params),
    enabled: Boolean(orgSlug && customerId),
  })
}

/**
 * Invalidate the customer as a whole, not just the contact list.
 *
 * Adding or promoting a contact changes the customer payload too — the detail
 * endpoint embeds contacts and a count, and the list embeds the primary
 * contact — so refreshing only the contacts query would leave those stale.
 */
function useRefreshCustomer(customerId: number) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return () => {
    queryClient.invalidateQueries({ queryKey: ['customers', orgSlug, 'detail', customerId] })
    queryClient.invalidateQueries({ queryKey: ['customers', orgSlug, 'list'] })
  }
}

export function useCreateContact(customerId: number) {
  const refresh = useRefreshCustomer(customerId)

  return useMutation({
    mutationFn: (payload: CustomerContactPayload) =>
      customerService.createContact(customerId, payload),
    onSuccess: () => {
      refresh()
      toast.success('Contact added.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not add the contact.')),
  })
}

export function useUpdateContact(customerId: number) {
  const refresh = useRefreshCustomer(customerId)

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: CustomerContactPayload }) =>
      customerService.updateContact(customerId, id, payload),
    onSuccess: () => {
      refresh()
      toast.success('Contact updated.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the contact.')),
  })
}

export function useDeleteContact(customerId: number) {
  const refresh = useRefreshCustomer(customerId)

  return useMutation({
    mutationFn: (id: number) => customerService.deleteContact(customerId, id),
    onSuccess: () => {
      refresh()
      toast.success('Contact removed.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not remove the contact.')),
  })
}
