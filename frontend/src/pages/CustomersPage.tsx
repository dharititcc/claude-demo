import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Download, Plus, Search, Trash2, Pencil } from 'lucide-react'
import toast from 'react-hot-toast'
import { useCustomers, useDeleteCustomer } from '@/hooks/useCustomers'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuthStore } from '@/store/auth'
import { customerService } from '@/services/customers'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { CustomerFormDialog } from '@/components/customers/CustomerFormDialog'
import type { Customer, CustomerFilters, CustomerStatus } from '@/types'

const STATUSES: Array<CustomerStatus | ''> = ['', 'lead', 'active', 'inactive', 'churned']

const statusVariant: Record<CustomerStatus, 'success' | 'default' | 'warning' | 'danger'> = {
  active: 'success',
  lead: 'default',
  inactive: 'warning',
  churned: 'danger',
}

export default function CustomersPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<CustomerFilters['sort']>('created_at')
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc')
  const [editing, setEditing] = useState<Customer | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)

  // Debounced so typing doesn't fire a request per keystroke.
  const debouncedSearch = useDebounced(search, 300)

  const can = useAuthStore((s) => s.can)

  const filters: CustomerFilters = useMemo(
    () => ({ q: debouncedSearch, status, page, sort, direction, per_page: 10 }),
    [debouncedSearch, status, page, sort, direction],
  )

  const { data, isLoading, isFetching } = useCustomers(filters)
  const deleteCustomer = useDeleteCustomer()

  function toggleSort(column: string) {
    if (sort === column) {
      setDirection((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSort(column)
      setDirection('asc')
    }
    setPage(1)
  }

  async function handleExport() {
    try {
      await customerService.exportCsv(filters)
      toast.success('Export downloaded.')
    } catch {
      toast.error('Could not export customers.')
    }
  }

  function handleDelete(customer: Customer) {
    if (!window.confirm(`Delete ${customer.name}? This can be undone by an admin.`)) return
    deleteCustomer.mutate(customer.id)
  }

  const customers = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Customers</h1>
          <p className="text-sm text-muted-foreground">
            {meta ? `${meta.total} total` : 'Loading…'}
          </p>
        </div>

        <div className="flex gap-2">
          {can('customers.export') && (
            <Button variant="outline" onClick={handleExport}>
              <Download size={16} />
              Export
            </Button>
          )}
          {can('customers.create') && (
            <Button
              onClick={() => {
                setEditing(null)
                setDialogOpen(true)
              }}
            >
              <Plus size={16} />
              New customer
            </Button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="relative min-w-56 flex-1">
          <Search
            size={16}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="pl-9"
            placeholder="Search name, email, company…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            aria-label="Search customers"
          />
        </div>

        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
          aria-label="Filter by status"
          className="h-10 rounded-md border bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {STATUSES.map((s) => (
            <option key={s || 'all'} value={s}>
              {s === '' ? 'All statuses' : s}
            </option>
          ))}
        </select>
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/40 text-left">
              <tr>
                {[
                  { key: 'name', label: 'Name' },
                  { key: 'company', label: 'Company' },
                  { key: 'status', label: 'Status' },
                  { key: 'lifetime_value', label: 'Value' },
                  { key: 'created_at', label: 'Added' },
                ].map((col) => (
                  <th key={col.key} className="px-4 py-3 font-medium">
                    <button
                      type="button"
                      onClick={() => toggleSort(col.key)}
                      className="inline-flex items-center gap-1 hover:text-foreground"
                    >
                      {col.label}
                      {sort === col.key && <span aria-hidden>{direction === 'asc' ? '↑' : '↓'}</span>}
                    </button>
                  </th>
                ))}
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {isLoading ? (
                <tr>
                  <td colSpan={6} className="py-16 text-center">
                    <Spinner className="mx-auto h-5 w-5" />
                  </td>
                </tr>
              ) : customers.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-16 text-center text-muted-foreground">
                    {debouncedSearch || status
                      ? 'No customers match those filters.'
                      : 'No customers yet.'}
                  </td>
                </tr>
              ) : (
                customers.map((customer) => (
                  <tr key={customer.id} className="hover:bg-accent/50">
                    <td className="px-4 py-3">
                      <Link
                        to={`/customers/${customer.id}`}
                        className="font-medium hover:text-primary hover:underline"
                      >
                        {customer.name}
                      </Link>
                      <p className="text-xs text-muted-foreground">{customer.email ?? '—'}</p>
                      {customer.tags && customer.tags.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {customer.tags.map((tag) => (
                            <span
                              key={tag.id}
                              className="rounded px-1.5 py-0.5 text-[10px] font-medium"
                              style={{ background: `${tag.color}22`, color: tag.color }}
                            >
                              {tag.name}
                            </span>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{customer.company ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge variant={statusVariant[customer.status]}>{customer.status}</Badge>
                    </td>
                    <td className="px-4 py-3 tabular-nums">
                      {customer.lifetime_value.toLocaleString(undefined, {
                        style: 'currency',
                        currency: 'USD',
                        maximumFractionDigits: 0,
                      })}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">
                      {customer.created_at
                        ? new Date(customer.created_at).toLocaleDateString()
                        : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1">
                        {can('customers.update') && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Edit ${customer.name}`}
                            onClick={() => {
                              setEditing(customer)
                              setDialogOpen(true)
                            }}
                          >
                            <Pencil size={15} />
                          </Button>
                        )}
                        {can('customers.delete') && (
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Delete ${customer.name}`}
                            onClick={() => handleDelete(customer)}
                          >
                            <Trash2 size={15} className="text-destructive" />
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t px-4 py-3">
            <p className="text-sm text-muted-foreground">
              Page {meta.current_page} of {meta.last_page}
              {isFetching && ' · updating…'}
            </p>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        )}
      </Card>

      <CustomerFormDialog
        open={dialogOpen}
        customer={editing}
        onClose={() => setDialogOpen(false)}
      />
    </div>
  )
}
