import { ArrowLeft, Ban, CheckCircle2, RotateCcw, Trash2, UserCog } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import {
  useActivateOrganization,
  useAdminOrganization,
  useDeleteOrganization,
  useImpersonate,
  useRestoreOrganization,
  useSuspendOrganization,
} from '@/hooks/useAdmin'
import { OrgStatusBadge } from '@/components/admin/OrgStatusBadge'
import { OrgProfileCard } from '@/components/admin/OrgProfileCard'
import { LimitsCard } from '@/components/admin/LimitsCard'
import { formatDate, formatDateTime, metric } from '@/lib/adminFormat'
import type { AdminOrganization } from '@/types/admin'
import { usePageTitle } from '@/hooks/usePageTitle'

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-sm">{value || <span className="text-muted-foreground">—</span>}</dd>
    </div>
  )
}

export default function AdminOrganizationDetailPage() {
  const { id = '' } = useParams()
  const { data, isLoading, isError } = useAdminOrganization(id)
  const org = data?.organization
  const limits = data?.limits

  const suspend = useSuspendOrganization()
  const activate = useActivateOrganization()
  const remove = useDeleteOrganization()
  const restore = useRestoreOrganization()
  const impersonate = useImpersonate()

  usePageTitle(org ? `${org.name} · Admin` : 'Admin')

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (isError || !org) {
    return (
      <div className="space-y-4">
        <BackLink />
        <p className="text-sm text-destructive">Could not load this organization.</p>
      </div>
    )
  }

  const isDeleted = Boolean(org.deleted_at)
  const busy =
    suspend.isPending || activate.isPending || remove.isPending || restore.isPending || impersonate.isPending

  return (
    <div className="space-y-6">
      <BackLink />

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-semibold">{org.name}</h1>
            <OrgStatusBadge org={org} />
          </div>
          <p className="text-sm text-muted-foreground">{org.slug}</p>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Profile + subscription */}
        <div className="space-y-6 lg:col-span-2">
          <OrgProfileCard org={org} />

          <Card>
            <CardHeader>
              <CardTitle>Subscription</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid gap-4 sm:grid-cols-2">
                <Field label="Plan" value={org.plan?.name} />
                <Field label="Status" value={org.subscription.status} />
                <Field label="Billing cycle" value={org.subscription.interval} />
                <Field
                  label="On trial"
                  value={org.subscription.on_trial ? `Until ${formatDate(org.subscription.trial_ends_at)}` : 'No'}
                />
                <Field label="Ends" value={formatDate(org.subscription.ends_at)} />
              </dl>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Usage</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <Field label="Users" value={metric(org.metrics.total_users)} />
                <Field label="Customers" value={metric(org.metrics.total_customers)} />
                <Field label="Projects" value={metric(org.metrics.total_projects)} />
                <Field label="Tasks" value={metric(org.metrics.total_tasks)} />
                <Field label="Files" value={metric(org.metrics.total_files)} />
                <Field
                  label="Storage"
                  value={org.metrics.storage_mb === null ? '—' : `${metric(org.metrics.storage_mb)} MB`}
                />
              </dl>
              <p className="mt-4 text-xs text-muted-foreground">
                {org.metrics.stats_refreshed_at
                  ? `Usage as of ${formatDateTime(org.metrics.stats_refreshed_at)}.`
                  : 'Usage figures appear after the next stats refresh.'}
              </p>
            </CardContent>
          </Card>

          {limits && <LimitsCard orgId={org.id} limits={limits} />}
        </div>

        {/* Actions */}
        <Card className="h-fit">
          <CardHeader>
            <CardTitle>Actions</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {isDeleted ? (
              <Button
                className="w-full"
                loading={restore.isPending}
                disabled={busy}
                onClick={() => restore.mutate(org.id)}
              >
                <RotateCcw size={16} /> Restore organization
              </Button>
            ) : (
              <>
                <ImpersonateAction org={org} disabled={busy} pending={impersonate.isPending} onConfirm={impersonate.mutate} />

                {org.status === 'suspended' ? (
                  <Button
                    variant="outline"
                    className="w-full"
                    loading={activate.isPending}
                    disabled={busy}
                    onClick={() => activate.mutate(org.id)}
                  >
                    <CheckCircle2 size={16} /> Activate
                  </Button>
                ) : (
                  <Button
                    variant="outline"
                    className="w-full"
                    loading={suspend.isPending}
                    disabled={busy}
                    onClick={() => {
                      if (confirm(`Suspend ${org.name}? Members lose access immediately.`)) {
                        suspend.mutate(org.id)
                      }
                    }}
                  >
                    <Ban size={16} /> Suspend
                  </Button>
                )}

                <Button
                  variant="destructive"
                  className="w-full"
                  loading={remove.isPending}
                  disabled={busy}
                  onClick={() => {
                    if (
                      confirm(
                        `Delete ${org.name}? Access is cut immediately. The database is kept and the org can be restored until it is purged.`,
                      )
                    ) {
                      remove.mutate(org.id)
                    }
                  }}
                >
                  <Trash2 size={16} /> Delete
                </Button>
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function ImpersonateAction({
  org,
  disabled,
  pending,
  onConfirm,
}: {
  org: AdminOrganization
  disabled: boolean
  pending: boolean
  onConfirm: (args: { orgId: string }) => void
}) {
  return (
    <Button
      className="w-full"
      loading={pending}
      disabled={disabled || !org.owner}
      title={org.owner ? undefined : 'This organization has no owner to impersonate'}
      onClick={() => {
        if (
          confirm(
            `Log in as ${org.owner?.email} in ${org.name}? Your session will act as that user, limited to this organization, for up to one hour. This is logged.`,
          )
        ) {
          onConfirm({ orgId: org.id })
        }
      }}
    >
      <UserCog size={16} /> Impersonate owner
    </Button>
  )
}

function BackLink() {
  return (
    <Link
      to="/admin/organizations"
      className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft size={16} /> All organizations
    </Link>
  )
}
