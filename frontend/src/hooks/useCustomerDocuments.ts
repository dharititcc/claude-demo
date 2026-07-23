import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { customerService } from '@/services/customers'
import { apiErrorMessage } from './useAuth'
import { useAuthStore } from '@/store/auth'

/** Scoped by organization, like every other tenant-facing cache key. */
export function documentKeys(orgSlug: string | null, customerId: number) {
  return {
    list: (category?: string) =>
      ['customers', orgSlug, 'detail', customerId, 'documents', category ?? 'all'] as const,
    versions: (documentId: number) =>
      ['customers', orgSlug, 'detail', customerId, 'documents', documentId, 'versions'] as const,
  }
}

export function useCustomerDocuments(customerId: number, category?: string) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: documentKeys(orgSlug, customerId).list(category),
    queryFn: () => customerService.documents(customerId, category),
    enabled: Boolean(orgSlug && customerId),
  })
}

/** Only fetched when a history panel is actually open. */
export function useDocumentVersions(customerId: number, documentId: number | null) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  return useQuery({
    queryKey: documentKeys(orgSlug, customerId).versions(documentId ?? 0),
    queryFn: () => customerService.documentVersions(customerId, documentId as number),
    enabled: Boolean(orgSlug && customerId && documentId),
  })
}

/**
 * Uploads change the organization's storage usage as well as this list, so the
 * billing/usage caches are dropped too — otherwise the storage meter would sit
 * stale until something else happened to refresh it.
 */
function useRefreshDocuments(customerId: number) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  return () => {
    queryClient.invalidateQueries({ queryKey: ['customers', orgSlug, 'detail', customerId] })
    queryClient.invalidateQueries({ queryKey: ['billing', orgSlug] })
  }
}

export function useUploadDocument(customerId: number) {
  const refresh = useRefreshDocuments(customerId)

  return useMutation({
    mutationFn: ({ file, category }: { file: File; category?: string }) =>
      customerService.uploadDocument(customerId, file, category),
    onSuccess: () => {
      refresh()
      toast.success('Document uploaded.')
    },
    // The API refuses blocked types and a full storage quota with specific
    // messages, so they are surfaced rather than replaced.
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not upload the document.')),
  })
}

export function useReplaceDocument(customerId: number) {
  const refresh = useRefreshDocuments(customerId)

  return useMutation({
    mutationFn: ({ documentId, file }: { documentId: number; file: File }) =>
      customerService.replaceDocument(customerId, documentId, file),
    onSuccess: (doc) => {
      refresh()
      toast.success(`Version ${doc.version} uploaded. The previous one is kept.`)
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not replace the document.')),
  })
}
