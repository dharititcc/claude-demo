import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { invoiceService } from '@/services/invoices'
import { apiErrorMessage } from './useAuth'
import { useAuthStore } from '@/store/auth'
import type { InvoiceFilters, InvoicePayload } from '@/types'

/** Scoped by organization, like every other tenant-facing cache key. */
export function invoiceKeys(orgSlug: string | null) {
  return {
    all: ['invoices', orgSlug] as const,
    list: (filters: InvoiceFilters) => ['invoices', orgSlug, 'list', filters] as const,
    detail: (id: number) => ['invoices', orgSlug, 'detail', id] as const,
  }
}

export function useInvoices(filters: InvoiceFilters = {}) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: invoiceKeys(orgSlug).list(filters),
    queryFn: () => invoiceService.list(filters),
    // Keep the previous page visible while the next loads.
    placeholderData: keepPreviousData,
    enabled: Boolean(orgSlug),
  })
}

export function useInvoice(id: number | null) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: invoiceKeys(orgSlug).detail(id ?? 0),
    queryFn: () => invoiceService.get(id as number),
    enabled: Boolean(orgSlug && id),
  })
}

/**
 * Every invoice mutation can change the customer's rollup too, so both caches
 * are dropped rather than just the invoice list.
 */
function useRefreshInvoices() {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return () => {
    queryClient.invalidateQueries({ queryKey: ['invoices', orgSlug] })
    queryClient.invalidateQueries({ queryKey: ['customers', orgSlug] })
  }
}

export function useCreateInvoice(customerId: number) {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: (payload: InvoicePayload) => invoiceService.create(customerId, payload),
    onSuccess: (invoice) => {
      refresh()
      toast.success(`Invoice ${invoice.number} created as a draft.`)
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not create the invoice.')),
  })
}

export function useUpdateInvoice() {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: InvoicePayload }) =>
      invoiceService.update(id, payload),
    onSuccess: () => {
      refresh()
      toast.success('Invoice updated.')
    },
    // The API refuses to restate an issued invoice's figures and says why, so
    // its message is surfaced rather than replaced.
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not update the invoice.')),
  })
}

export function useSendInvoice() {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: (id: number) => invoiceService.send(id),
    onSuccess: (invoice) => {
      refresh()
      toast.success(`Invoice ${invoice.number} sent.`)
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not send the invoice.')),
  })
}

export function useRecordPayment() {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: ({ id, amount }: { id: number; amount: number }) =>
      invoiceService.recordPayment(id, amount),
    onSuccess: (invoice) => {
      refresh()
      toast.success(
        invoice.status === 'paid' ? 'Invoice paid in full.' : 'Part payment recorded.',
      )
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not record the payment.')),
  })
}

export function useVoidInvoice() {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: (id: number) => invoiceService.void(id),
    onSuccess: (invoice) => {
      refresh()
      toast.success(`Invoice ${invoice.number} voided.`)
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not void the invoice.')),
  })
}

export function useDeleteInvoice() {
  const refresh = useRefreshInvoices()

  return useMutation({
    mutationFn: (id: number) => invoiceService.remove(id),
    onSuccess: () => {
      refresh()
      toast.success('Draft deleted.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not delete the draft.')),
  })
}
