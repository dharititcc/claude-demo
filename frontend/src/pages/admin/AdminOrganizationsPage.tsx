import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useDebounced } from '@/hooks/useDebounced'
import { useAdminOrganizations } from '@/hooks/useAdmin'
import { OrgStatusBadge } from '@/components/admin/OrgStatusBadge'
import { formatDate, metric } from '@/lib/adminFormat'
import type { AdminOrgFilters } from '@/types/admin'
import type { OrganizationStatus } from '@/types'
import { usePageTitle } from '@/hooks/usePageTitle'

const STATUS_OPTIONS: Array<{ value: OrganizationStatus | ''; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'trial', label: 'Trial' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'cancelled', label: 'Cancelled' },
]

const selectClass =
  'h-10 rounded-md border bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

export default function AdminOrganizationsPage() {
  usePageTitle('Organizations · Admin')

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<OrganizationStatus | ''>('')
  const [trashed, setTrashed] = useState<'' | 'with' | 'only'>('')
  const [sort, setSort] = useState('-created_at')
  const [page, setPage] = useState(1)

  const debouncedSearch = useDebounced(search)

  const filters: AdminOrgFilters = {
    search: debouncedSearch,
    status,
    trashed,
    sort,
    page,
    per_page: 20,
  }

  const { data, isLoading, isFetching, isError } = useAdminOrganizations(filters)

  // Any filter change returns to page one, or the user can land on an empty page.
  function onFilterChange<T>(setter: (v: T) => void) {
    return (value: T) => {
      setter(value)
      setPage(1)
    }
  }

  const rows = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Organizations</h1>
        <p className="text-sm text-muted-foreground">
          {meta ? `${meta.total.toLocaleString()} organization${meta.total === 1 ? '' : 's'}` : 'All organizations'}
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search
            size={16}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
            aria-hidden
          />
          <Input
            id="org-search"
            className="pl-9"
            placeholder="Search name, phone, or owner"
            value={search}
            onChange={(e) => onFilterChange(setSearch)(e.target.value)}
            aria-label="Search organizations"
          />
        </div>

        <select
          className={selectClass}
          value={status}
          onChange={(e) => onFilterChange(setStatus)(e.target.value as OrganizationStatus | '')}
          aria-label="Filter by status"
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>

        <select
          className={selectClass}
          value={trashed}
          onChange={(e) => onFilterChange(setTrashed)(e.target.value as '' | 'with' | 'only')}
          aria-label="Include deleted"
        >
          <option value="">Live only</option>
          <option value="with">Include deleted</option>
          <option value="only">Deleted only</option>
        </select>

        <select
          className={selectClass}
          value={sort}
          onChange={(e) => onFilterChange(setSort)(e.target.value)}
          aria-label="Sort by"
        >
          <option value="-created_at">Newest first</option>
          <option value="created_at">Oldest first</option>
          <option value="name">Name A–Z</option>
          <option value="-name">Name Z–A</option>
          <option value="-members_count">Most users</option>
        </select>
      </div>

      {/* Table */}
      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex min-h-[30vh] items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : isError ? (
          <p className="p-6 text-sm text-destructive">Could not load organizations.</p>
        ) : rows.length === 0 ? (
          <p className="p-6 text-sm text-muted-foreground">No organizations match these filters.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-3 font-medium">Organization</th>
                  <th className="px-4 py-3 font-medium">Owner</th>
                  <th className="px-4 py-3 font-medium">Plan</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 text-right font-medium">Users</th>
                  <th className="px-4 py-3 text-right font-medium">Projects</th>
                  <th className="px-4 py-3 font-medium">Registered</th>
                </tr>
              </thead>
              <tbody className={isFetching ? 'opacity-60 transition-opacity' : undefined}>
                {rows.map((org) => (
                  <tr key={org.id} className="border-b last:border-0 hover:bg-accent/40">
                    <td className="px-4 py-3">
                      <Link to={`/admin/organizations/${org.id}`} className="font-medium hover:underline">
                        {org.name}
                      </Link>
                      <p className="text-xs text-muted-foreground">{org.slug}</p>
                    </td>
                    <td className="px-4 py-3">
                      {org.owner ? (
                        <>
                          <p>{org.owner.name}</p>
                          <p className="text-xs text-muted-foreground">{org.owner.email}</p>
                        </>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3">{org.plan?.name ?? <span className="text-muted-foreground">—</span>}</td>
                    <td className="px-4 py-3">
                      <OrgStatusBadge org={org} />
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">{metric(org.metrics.total_users)}</td>
                    <td className="px-4 py-3 text-right tabular-nums">{metric(org.metrics.total_projects)}</td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(org.registered_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Page {meta.current_page} of {meta.last_page}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
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
    </div>
  )
}
