import { useState } from 'react'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { useAdminActivity } from '@/hooks/useAdmin'
import { formatDateTime } from '@/lib/adminFormat'

/** Human labels for the dotted action verbs the API records. */
const ACTION_LABELS: Record<string, string> = {
  'organization.updated': 'Edited',
  'organization.limits.updated': 'Limits changed',
  'organization.suspended': 'Suspended',
  'organization.activated': 'Activated',
  'organization.deleted': 'Deleted',
  'organization.restored': 'Restored',
  'organization.purged': 'Purged',
  'organization.impersonation.started': 'Started impersonation',
  'organization.impersonation.stopped': 'Stopped impersonation',
}

function actionLabel(action: string): string {
  return ACTION_LABELS[action] ?? action
}

function actionVariant(action: string): 'default' | 'success' | 'warning' | 'danger' | 'muted' {
  if (action.includes('purged') || action.includes('deleted')) return 'danger'
  if (action.includes('suspended')) return 'warning'
  if (action.includes('impersonation')) return 'default'
  if (action.includes('restored') || action.includes('activated')) return 'success'
  return 'muted'
}

export default function AdminActivityPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading, isFetching, isError } = useAdminActivity({ page, per_page: 30 })

  const rows = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Audit log</h1>
        <p className="text-sm text-muted-foreground">
          Every platform-admin action across all organizations. Read-only.
        </p>
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex min-h-[30vh] items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : isError ? (
          <p className="p-6 text-sm text-destructive">Could not load the audit log.</p>
        ) : rows.length === 0 ? (
          <p className="p-6 text-sm text-muted-foreground">No admin activity recorded yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-3 font-medium">When</th>
                  <th className="px-4 py-3 font-medium">Action</th>
                  <th className="px-4 py-3 font-medium">Organization</th>
                  <th className="px-4 py-3 font-medium">By</th>
                  <th className="px-4 py-3 font-medium">Detail</th>
                </tr>
              </thead>
              <tbody className={isFetching ? 'opacity-60 transition-opacity' : undefined}>
                {rows.map((entry) => (
                  <tr key={entry.id} className="border-b last:border-0 align-top">
                    <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                      {formatDateTime(entry.created_at)}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={actionVariant(entry.action)}>{actionLabel(entry.action)}</Badge>
                    </td>
                    <td className="px-4 py-3">{entry.target.label ?? entry.target.id ?? '—'}</td>
                    <td className="px-4 py-3">
                      {entry.admin ? (
                        entry.admin.email
                      ) : (
                        <span className="text-muted-foreground">System</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{entry.description ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

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
