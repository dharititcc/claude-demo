import { useRef, useState } from 'react'
import { Download, Eye, FileText, History, Trash2, Upload } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import {
  useCustomerDocuments,
  useDocumentVersions,
  useReplaceDocument,
  useUploadDocument,
} from '@/hooks/useCustomerDocuments'
import { apiErrorMessage } from '@/hooks/useAuth'
import { fileService } from '@/services/files'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { formatDateTime } from '@/lib/date'
import type { CustomerDocument, DocumentCategory } from '@/types'

const CATEGORIES: Array<{ value: '' | DocumentCategory; label: string }> = [
  { value: '', label: 'All' },
  { value: 'contract', label: 'Contracts' },
  { value: 'invoice', label: 'Invoices' },
  { value: 'proposal', label: 'Proposals' },
  { value: 'report', label: 'Reports' },
  { value: 'identity', label: 'Identity' },
  { value: 'other', label: 'Other' },
]

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/**
 * Documents filed against a customer.
 *
 * The list shows current versions only; the history panel reaches the rest.
 * Downloading and deleting go through the Files module's own endpoints — this
 * tab adds no second way to do either.
 */
export function DocumentsTab({ customerId }: { customerId: number }) {
  const can = useAuthStore((s) => s.can)
  const queryClient = useQueryClient()

  const [category, setCategory] = useState<'' | DocumentCategory>('')
  const [uploadCategory, setUploadCategory] = useState<DocumentCategory>('other')
  const [historyFor, setHistoryFor] = useState<number | null>(null)

  const uploadInput = useRef<HTMLInputElement>(null)
  const replaceInput = useRef<HTMLInputElement>(null)
  const replacingId = useRef<number | null>(null)

  const documents = useCustomerDocuments(customerId, category || undefined)
  const versions = useDocumentVersions(customerId, historyFor)
  const upload = useUploadDocument(customerId)
  const replace = useReplaceDocument(customerId)

  const remove = useMutation({
    mutationFn: (id: number) => fileService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      toast.success('Document deleted.')
    },
    onError: (error) => toast.error(apiErrorMessage(error, 'Could not delete the document.')),
  })

  if (!can('files.view')) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-sm text-muted-foreground">
          You do not have permission to view files.
        </CardContent>
      </Card>
    )
  }

  const rows = documents.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="inline-flex flex-wrap rounded-md border p-0.5" role="group" aria-label="Filter by category">
          {CATEGORIES.map((c) => (
            <button
              key={c.value || 'all'}
              type="button"
              onClick={() => setCategory(c.value)}
              aria-pressed={category === c.value}
              className={`rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                category === c.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'
              }`}
            >
              {c.label}
            </button>
          ))}
        </div>

        {can('files.upload') && (
          <div className="flex items-center gap-2">
            <label htmlFor="doc-category" className="sr-only">
              Category for the next upload
            </label>
            <select
              id="doc-category"
              value={uploadCategory}
              onChange={(e) => setUploadCategory(e.target.value as DocumentCategory)}
              className="h-9 rounded-md border bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {CATEGORIES.filter((c) => c.value).map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </select>

            <input
              ref={uploadInput}
              type="file"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0]
                if (file) upload.mutate({ file, category: uploadCategory })
                // Reset so selecting the same file twice still fires a change.
                e.target.value = ''
              }}
            />

            <Button onClick={() => uploadInput.current?.click()} disabled={upload.isPending}>
              <Upload size={16} className="mr-1.5" />
              Upload
            </Button>
          </div>
        )}
      </div>

      {/* Hidden input reused for every "replace"; the target id is held in a ref. */}
      <input
        ref={replaceInput}
        type="file"
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0]
          const id = replacingId.current
          if (file && id) replace.mutate({ documentId: id, file })
          replacingId.current = null
          e.target.value = ''
        }}
      />

      {documents.isLoading ? (
        <div className="flex h-40 items-center justify-center">
          <Spinner className="h-6 w-6" />
        </div>
      ) : documents.isError ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-destructive">
            Could not load the documents.
          </CardContent>
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-muted-foreground">
            {category ? 'No documents in this category.' : 'No documents filed for this customer yet.'}
          </CardContent>
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <ul className="divide-y">
            {rows.map((doc) => (
              <li key={doc.id} className="p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <FileText size={18} className="shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                      <p className="truncate font-medium">{doc.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {formatBytes(doc.size)}
                        {doc.created_at && ` · ${formatDateTime(doc.created_at)}`}
                      </p>
                    </div>
                    {doc.category && <Badge variant="muted">{doc.category}</Badge>}
                    {doc.version > 1 && <Badge>v{doc.version}</Badge>}
                  </div>

                  <div className="flex items-center gap-1">
                    {/* Preview is allow-listed server-side; anything else can
                        only be downloaded, never rendered inline. */}
                    {doc.is_previewable && (
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Preview ${doc.name}`}
                        onClick={() => fileService.preview(doc.id)}
                      >
                        <Eye size={15} />
                      </Button>
                    )}

                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={`Download ${doc.name}`}
                      onClick={() => fileService.download(doc.id, doc.name)}
                    >
                      <Download size={15} />
                    </Button>

                    {doc.version > 1 && (
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Version history for ${doc.name}`}
                        onClick={() => setHistoryFor(historyFor === doc.id ? null : doc.id)}
                      >
                        <History size={15} />
                      </Button>
                    )}

                    {can('files.upload') && (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={replace.isPending}
                        onClick={() => {
                          replacingId.current = doc.id
                          replaceInput.current?.click()
                        }}
                      >
                        Replace
                      </Button>
                    )}

                    {can('files.delete') && (
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Delete ${doc.name}`}
                        disabled={remove.isPending}
                        onClick={() => {
                          if (window.confirm(`Delete ${doc.name}?`)) remove.mutate(doc.id)
                        }}
                      >
                        <Trash2 size={15} />
                      </Button>
                    )}
                  </div>
                </div>

                {historyFor === doc.id && (
                  <div className="mt-3 rounded-md border bg-muted/30 p-3">
                    {versions.isLoading ? (
                      <Spinner className="h-4 w-4" />
                    ) : (
                      <ul className="space-y-1 text-xs">
                        {(versions.data ?? []).map((v: CustomerDocument) => (
                          <li key={v.id} className="flex items-center justify-between gap-2">
                            <span>
                              v{v.version} · {v.name} · {formatBytes(v.size)}
                              {v.created_at && ` · ${formatDateTime(v.created_at)}`}
                            </span>
                            <Button
                              variant="ghost"
                              size="sm"
                              aria-label={`Download version ${v.version}`}
                              onClick={() => fileService.download(v.id, v.name)}
                            >
                              <Download size={13} />
                            </Button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
