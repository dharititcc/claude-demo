import { useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import {
  ArrowLeft,
  Building,
  Download,
  Globe,
  Mail,
  Paperclip,
  Phone,
  Trash2,
  Upload,
} from 'lucide-react'
import { useCustomer, customerKeys } from '@/hooks/useCustomers'
import { customerService } from '@/services/customers'
import { attachmentService } from '@/services/attachments'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import type { CustomerStatus } from '@/types'

const statusVariant: Record<CustomerStatus, 'success' | 'default' | 'warning' | 'danger'> = {
  active: 'success',
  lead: 'default',
  inactive: 'warning',
  churned: 'danger',
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export default function CustomerDetailPage() {
  const { id } = useParams()
  const customerId = Number(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [note, setNote] = useState('')

  const can = useAuthStore((s) => s.can)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  const { data: customer, isLoading, isError } = useCustomer(customerId)

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: customerKeys(orgSlug).detail(customerId) })

  const addNote = useMutation({
    mutationFn: (body: string) => customerService.addNote(customerId, body),
    onSuccess: () => {
      setNote('')
      refresh()
      toast.success('Note added.')
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not add the note.')),
  })

  const upload = useMutation({
    mutationFn: (file: File) => attachmentService.upload(customerId, file),
    onSuccess: () => {
      refresh()
      toast.success('File uploaded.')
      if (fileInput.current) fileInput.current.value = ''
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not upload the file.')),
  })

  const removeAttachment = useMutation({
    mutationFn: (attachmentId: number) => attachmentService.remove(customerId, attachmentId),
    onSuccess: () => {
      refresh()
      toast.success('Attachment deleted.')
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not delete the attachment.')),
  })

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (isError || !customer) {
    return (
      <Card>
        <CardContent className="pt-6 text-center">
          <p className="text-muted-foreground">This customer doesn't exist in this organization.</p>
          <Button className="mt-4" variant="outline" onClick={() => navigate('/customers')}>
            Back to customers
          </Button>
        </CardContent>
      </Card>
    )
  }

  const detail = [
    { icon: Mail, label: 'Email', value: customer.email },
    { icon: Phone, label: 'Phone', value: customer.phone },
    { icon: Building, label: 'Company', value: customer.company },
    { icon: Globe, label: 'Website', value: customer.website },
  ].filter((d) => d.value)

  return (
    <div className="space-y-6">
      <Link
        to="/customers"
        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft size={14} /> Customers
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{customer.name}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <Badge variant={statusVariant[customer.status]}>{customer.status}</Badge>
            {customer.tags?.map((tag) => (
              <span
                key={tag.id}
                className="rounded px-1.5 py-0.5 text-xs font-medium"
                style={{ background: `${tag.color}22`, color: tag.color }}
              >
                {tag.name}
              </span>
            ))}
          </div>
        </div>
        <div className="text-right">
          <p className="text-sm text-muted-foreground">Lifetime value</p>
          <p className="text-xl font-semibold tabular-nums">
            {customer.lifetime_value.toLocaleString(undefined, {
              style: 'currency',
              currency: 'USD',
            })}
          </p>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {detail.length === 0 ? (
              <p className="text-sm text-muted-foreground">No contact details recorded.</p>
            ) : (
              detail.map(({ icon: Icon, label, value }) => (
                <div key={label} className="flex items-start gap-2 text-sm">
                  <Icon size={15} className="mt-0.5 shrink-0 text-muted-foreground" />
                  <span className="break-all">{value}</span>
                </div>
              ))
            )}
            {customer.address.city && (
              <p className="pt-2 text-sm text-muted-foreground">
                {[customer.address.line1, customer.address.city, customer.address.state, customer.address.country]
                  .filter(Boolean)
                  .join(', ')}
              </p>
            )}
          </CardContent>
        </Card>

        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Notes</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {can('customers.update') && (
                <div className="flex gap-2">
                  <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="Add a note…"
                    rows={2}
                    aria-label="New note"
                    className="flex-1 rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  />
                  <Button
                    onClick={() => addNote.mutate(note)}
                    loading={addNote.isPending}
                    disabled={!note.trim()}
                  >
                    Add
                  </Button>
                </div>
              )}

              {!customer.notes?.length ? (
                <p className="py-4 text-center text-sm text-muted-foreground">No notes yet.</p>
              ) : (
                <ul className="divide-y">
                  {customer.notes.map((n) => (
                    <li key={n.id} className="py-3">
                      <p className="whitespace-pre-wrap text-sm">{n.body}</p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {n.created_at ? new Date(n.created_at).toLocaleString() : ''}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle className="flex items-center gap-2">
                <Paperclip size={16} /> Attachments
              </CardTitle>
              {can('files.upload') && (
                <>
                  <input
                    ref={fileInput}
                    type="file"
                    className="hidden"
                    onChange={(e) => {
                      const file = e.target.files?.[0]
                      if (file) upload.mutate(file)
                    }}
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    loading={upload.isPending}
                    onClick={() => fileInput.current?.click()}
                  >
                    <Upload size={14} /> Upload
                  </Button>
                </>
              )}
            </CardHeader>
            <CardContent>
              {!customer.attachments?.length ? (
                <p className="py-4 text-center text-sm text-muted-foreground">No files attached.</p>
              ) : (
                <ul className="divide-y">
                  {customer.attachments.map((a) => (
                    <li key={a.id} className="flex items-center justify-between gap-3 py-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{a.filename}</p>
                        <p className="text-xs text-muted-foreground">{formatBytes(a.size)}</p>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <a href={a.url} target="_blank" rel="noreferrer" download>
                          <Button variant="ghost" size="icon" aria-label={`Download ${a.filename}`}>
                            <Download size={15} />
                          </Button>
                        </a>
                        {can('files.delete') && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Delete ${a.filename}`}
                            onClick={() => removeAttachment.mutate(a.id)}
                          >
                            <Trash2 size={15} className="text-destructive" />
                          </Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
