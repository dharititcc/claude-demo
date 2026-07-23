import { useState } from 'react'
import { Mail, Pencil, Phone, Plus, Search, Star, Trash2 } from 'lucide-react'
import { useCustomerContacts, useDeleteContact, useUpdateContact } from '@/hooks/useCustomerContacts'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ContactFormDialog } from './ContactFormDialog'
import type { CustomerContact } from '@/types'

/**
 * People at a customer company.
 *
 * Contacts are governed by customers.* — whoever may edit the company may
 * manage its people — so the write controls follow customers.update.
 */
export function ContactsTab({ customerId }: { customerId: number }) {
  const can = useAuthStore((s) => s.can)
  const canManage = can('customers.update')

  const [search, setSearch] = useState('')
  const debounced = useDebounced(search, 300)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<CustomerContact | null>(null)

  const contacts = useCustomerContacts(customerId, { q: debounced })
  const update = useUpdateContact(customerId)
  const remove = useDeleteContact(customerId)

  function openNew() {
    setEditing(null)
    setDialogOpen(true)
  }

  function openEdit(contact: CustomerContact) {
    setEditing(contact)
    setDialogOpen(true)
  }

  function onDelete(contact: CustomerContact) {
    if (!window.confirm(`Remove ${contact.full_name} from this customer?`)) return

    remove.mutate(contact.id)
  }

  const rows = contacts.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="relative max-w-xs flex-1">
          <Search
            size={15}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="pl-9"
            placeholder="Search name, email, job title…"
            aria-label="Search contacts"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        {canManage && (
          <Button onClick={openNew}>
            <Plus size={16} className="mr-1.5" />
            New contact
          </Button>
        )}
      </div>

      {contacts.isLoading ? (
        <div className="flex h-40 items-center justify-center">
          <Spinner className="h-6 w-6" />
        </div>
      ) : contacts.isError ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-destructive">
            Could not load the contacts.
          </CardContent>
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-muted-foreground">
            {debounced
              ? 'No contacts match that search.'
              : 'No contacts yet. Add the person you deal with at this company.'}
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {rows.map((contact) => (
            <Card key={contact.id} className={contact.is_primary ? 'border-primary/50' : undefined}>
              <CardContent className="space-y-2 pt-5">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="flex items-center gap-1.5 font-medium">
                      <span className="truncate">{contact.full_name}</span>
                      {contact.is_primary && (
                        <Star size={13} className="shrink-0 fill-primary text-primary" aria-label="Primary contact" />
                      )}
                    </p>
                    {(contact.job_title || contact.department) && (
                      <p className="truncate text-xs text-muted-foreground">
                        {[contact.job_title, contact.department].filter(Boolean).join(' · ')}
                      </p>
                    )}
                  </div>

                  {contact.status === 'inactive' && <Badge variant="muted">Inactive</Badge>}
                </div>

                {contact.email && (
                  <a
                    href={`mailto:${contact.email}`}
                    className="flex items-center gap-2 text-sm text-primary hover:underline"
                  >
                    <Mail size={14} className="shrink-0" />
                    <span className="truncate">{contact.email}</span>
                  </a>
                )}

                {(contact.mobile || contact.phone) && (
                  <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Phone size={14} className="shrink-0" />
                    <span className="truncate">{contact.mobile || contact.phone}</span>
                  </p>
                )}

                {canManage && (
                  <div className="flex justify-end gap-1 pt-1">
                    {!contact.is_primary && (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Make ${contact.full_name} the primary contact`}
                        disabled={update.isPending}
                        onClick={() =>
                          update.mutate({ id: contact.id, payload: { is_primary: true } })
                        }
                      >
                        <Star size={14} className="mr-1" /> Make primary
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={`Edit ${contact.full_name}`}
                      onClick={() => openEdit(contact)}
                    >
                      <Pencil size={15} />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={`Remove ${contact.full_name}`}
                      disabled={remove.isPending}
                      onClick={() => onDelete(contact)}
                    >
                      <Trash2 size={15} />
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <ContactFormDialog
        open={dialogOpen}
        customerId={customerId}
        contact={editing}
        onClose={() => setDialogOpen(false)}
      />
    </div>
  )
}
