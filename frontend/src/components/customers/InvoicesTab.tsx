import { useState } from 'react'
import { Ban, Pencil, Plus, Printer, Send, Trash2, Wallet } from 'lucide-react'
import {
  useDeleteInvoice,
  useInvoices,
  useRecordPayment,
  useSendInvoice,
  useVoidInvoice,
} from '@/hooks/useInvoices'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate } from '@/lib/date'
import { InvoiceFormDialog } from './InvoiceFormDialog'
import type { Invoice, InvoiceDisplayStatus } from '@/types'

/** Derived states are coloured by what they mean to the person chasing money. */
const statusVariant: Record<InvoiceDisplayStatus, 'default' | 'success' | 'warning' | 'danger' | 'muted'> = {
  draft: 'muted',
  sent: 'default',
  partial: 'warning',
  overdue: 'danger',
  paid: 'success',
  void: 'muted',
}

const FILTERS: Array<{ value: '' | InvoiceDisplayStatus; label: string }> = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'paid', label: 'Paid' },
]

export function InvoicesTab({ customerId, currency }: { customerId: number; currency: string }) {
  const can = useAuthStore((s) => s.can)
  const canCreate = can('invoices.create')
  const canUpdate = can('invoices.update')
  const canDelete = can('invoices.delete')

  const [status, setStatus] = useState<'' | InvoiceDisplayStatus>('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Invoice | null>(null)

  const invoices = useInvoices({ customer_id: customerId, status: status || undefined, per_page: 50 })
  const send = useSendInvoice()
  const pay = useRecordPayment()
  const voidInvoice = useVoidInvoice()
  const remove = useDeleteInvoice()

  if (!can('invoices.view')) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-sm text-muted-foreground">
          You do not have permission to view invoices.
        </CardContent>
      </Card>
    )
  }

  const rows = invoices.data?.data ?? []

  const asMoney = (amount: number, code: string) =>
    amount.toLocaleString(undefined, { style: 'currency', currency: code || 'USD' })

  function onPay(invoice: Invoice) {
    const entered = window.prompt(
      `Record a payment against ${invoice.number}. Outstanding: ${invoice.balance}`,
      String(invoice.balance),
    )

    if (entered === null) return

    const amount = Number(entered)

    if (!Number.isFinite(amount) || amount <= 0) return

    pay.mutate({ id: invoice.id, amount })
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="inline-flex rounded-md border p-0.5" role="group" aria-label="Filter by status">
          {FILTERS.map((f) => (
            <button
              key={f.value || 'all'}
              type="button"
              onClick={() => setStatus(f.value)}
              aria-pressed={status === f.value}
              className={`rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                status === f.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>

        {canCreate && (
          <Button
            onClick={() => {
              setEditing(null)
              setDialogOpen(true)
            }}
          >
            <Plus size={16} className="mr-1.5" />
            New invoice
          </Button>
        )}
      </div>

      {invoices.isLoading ? (
        <div className="flex h-40 items-center justify-center">
          <Spinner className="h-6 w-6" />
        </div>
      ) : invoices.isError ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-destructive">
            Could not load the invoices.
          </CardContent>
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <CardContent className="pt-6 text-center text-sm text-muted-foreground">
            {status ? `No ${status} invoices.` : 'No invoices for this customer yet.'}
          </CardContent>
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Invoice</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Due</th>
                  <th className="px-4 py-3 text-right">Total</th>
                  <th className="px-4 py-3 text-right">Balance</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((invoice) => (
                  <tr key={invoice.id} className="border-b last:border-0">
                    <td className="px-4 py-3">
                      <div className="font-medium tabular-nums">{invoice.number}</div>
                      <div className="text-xs text-muted-foreground">
                        Issued {formatDate(invoice.issue_date)}
                        {invoice.paid_at && ` · Paid ${formatDate(invoice.paid_at)}`}
                      </div>
                    </td>

                    <td className="px-4 py-3">
                      <Badge variant={statusVariant[invoice.display_status]}>
                        {invoice.display_status}
                      </Badge>
                    </td>

                    <td className="px-4 py-3 whitespace-nowrap">
                      <span className={invoice.is_overdue ? 'text-destructive' : 'text-muted-foreground'}>
                        {formatDate(invoice.due_date)}
                      </span>
                    </td>

                    <td className="px-4 py-3 text-right tabular-nums">
                      {asMoney(invoice.total, invoice.currency)}
                      {invoice.tax_total > 0 && (
                        <div className="text-xs text-muted-foreground">
                          incl. {asMoney(invoice.tax_total, invoice.currency)} tax
                        </div>
                      )}
                    </td>

                    <td className="px-4 py-3 text-right tabular-nums">
                      {asMoney(invoice.balance, invoice.currency)}
                    </td>

                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1">
                        {/* Print uses the browser: the invoice is already on
                            screen, and a server-rendered PDF is a separate job. */}
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label={`Print ${invoice.number}`}
                          onClick={() => window.print()}
                        >
                          <Printer size={15} />
                        </Button>

                        {canUpdate && invoice.status === 'draft' && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Edit ${invoice.number}`}
                            onClick={() => {
                              setEditing(invoice)
                              setDialogOpen(true)
                            }}
                          >
                            <Pencil size={15} />
                          </Button>
                        )}

                        {canUpdate && invoice.status === 'draft' && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Send ${invoice.number}`}
                            disabled={send.isPending}
                            onClick={() => send.mutate(invoice.id)}
                          >
                            <Send size={15} />
                          </Button>
                        )}

                        {canUpdate && invoice.status === 'sent' && invoice.balance > 0 && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Record a payment against ${invoice.number}`}
                            disabled={pay.isPending}
                            onClick={() => onPay(invoice)}
                          >
                            <Wallet size={15} />
                          </Button>
                        )}

                        {canDelete && invoice.status === 'draft' && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Delete draft ${invoice.number}`}
                            disabled={remove.isPending}
                            onClick={() => {
                              if (window.confirm(`Delete draft ${invoice.number}?`)) {
                                remove.mutate(invoice.id)
                              }
                            }}
                          >
                            <Trash2 size={15} />
                          </Button>
                        )}

                        {/* Issued invoices are voided, never deleted, so the
                            number sequence keeps no gaps. */}
                        {canDelete && invoice.status === 'sent' && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Void ${invoice.number}`}
                            disabled={voidInvoice.isPending}
                            onClick={() => {
                              if (window.confirm(`Void ${invoice.number}? Its number is kept.`)) {
                                voidInvoice.mutate(invoice.id)
                              }
                            }}
                          >
                            <Ban size={15} />
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <InvoiceFormDialog
        open={dialogOpen}
        customerId={customerId}
        invoice={editing}
        currency={currency}
        onClose={() => setDialogOpen(false)}
      />
    </div>
  )
}
