import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { Select, type SelectOption } from '@/components/ui/Select'
import { SkeletonGroup, SkeletonTable, type SkeletonColumn } from '@/components/ui/Skeleton'

// The admin tables use an uppercase, borderless header rather than the tinted one.
const ADMIN_TABLE_HEAD = 'border-b text-left'

// Organization + slug, owner + email, plan, status pill, users, projects, registered.
const ORG_COLUMNS: SkeletonColumn[] = [
  { width: 'w-36', lines: 2, headerWidth: 'w-24' },
  { width: 'w-28', lines: 2 },
  { width: 'w-16', headerWidth: 'w-10' },
  { badge: true, headerWidth: 'w-12' },
  { width: 'w-8', align: 'right', headerWidth: 'w-10' },
  { width: 'w-8', align: 'right', headerWidth: 'w-14' },
  { width: 'w-20', headerWidth: 'w-20' },
]
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

const TRASHED_OPTIONS: SelectOption[] = [
  { value: '', label: 'Live only' },
  { value: 'with', label: 'Include deleted' },
  { value: 'only', label: 'Deleted only' },
]

const SORT_OPTIONS: SelectOption[] = [
  { value: '-created_at', label: 'Newest first' },
  { value: 'created_at', label: 'Oldest first' },
  { value: 'name', label: 'Name A–Z' },
  { value: '-name', label: 'Name Z–A' },
  { value: '-members_count', label: 'Most users' },
]

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

        <Select
          className="w-40"
          value={status}
          onChange={(v) => onFilterChange(setStatus)(v as OrganizationStatus | '')}
          options={STATUS_OPTIONS}
          aria-label="Filter by status"
        />

        <Select
          className="w-40"
          value={trashed}
          onChange={(v) => onFilterChange(setTrashed)(v as '' | 'with' | 'only')}
          options={TRASHED_OPTIONS}
          aria-label="Include deleted"
        />

        <Select
          className="w-40"
          value={sort}
          onChange={onFilterChange(setSort)}
          options={SORT_OPTIONS}
          aria-label="Sort by"
        />
      </div>

      {/* Table */}
      <Card className="overflow-hidden">
        {isLoading ? (
          <SkeletonGroup label="Loading organizations">
            <SkeletonTable rows={8} columns={ORG_COLUMNS} headerClassName={ADMIN_TABLE_HEAD} />
          </SkeletonGroup>
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
