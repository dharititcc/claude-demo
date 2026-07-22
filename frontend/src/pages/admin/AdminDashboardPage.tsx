import {
  Building2,
  CheckCircle2,
  Clock,
  CreditCard,
  FolderKanban,
  HardDrive,
  Users,
  XCircle,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAdminStats } from '@/hooks/useAdmin'
import { metric } from '@/lib/adminFormat'
import { usePageTitle } from '@/hooks/usePageTitle'

interface StatCardProps {
  label: string
  value: string
  icon: LucideIcon
  hint?: string
}

function StatCard({ label, value, icon: Icon, hint }: StatCardProps) {
  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-4 p-5">
        <div className="min-w-0">
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
          {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Icon size={20} />
        </div>
      </CardContent>
    </Card>
  )
}

export default function AdminDashboardPage() {
  usePageTitle('Admin')

  const { data: stats, isLoading, isError } = useAdminStats()

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (isError || !stats) {
    return <p className="text-sm text-destructive">Could not load platform statistics.</p>
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Platform overview</h1>
        <p className="text-sm text-muted-foreground">Every organization on the platform, at a glance.</p>
      </div>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total organizations" value={metric(stats.total)} icon={Building2} />
        <StatCard label="Active" value={metric(stats.active)} icon={CheckCircle2} />
        <StatCard label="On trial" value={metric(stats.trial)} icon={Clock} />
        <StatCard label="Suspended" value={metric(stats.suspended)} icon={XCircle} />
        <StatCard label="Trials expired" value={metric(stats.expired)} icon={Clock} />
        <StatCard label="Paid" value={metric(stats.paid)} icon={CreditCard} />
        <StatCard label="Total users" value={metric(stats.total_users)} icon={Users} hint="Distinct across all orgs" />
        <StatCard
          label="Total projects"
          value={metric(stats.total_projects)}
          icon={FolderKanban}
          hint={stats.total_projects === null ? 'Awaiting first stats refresh' : undefined}
        />
        <StatCard
          label="Storage used"
          value={stats.total_storage_mb === null ? '—' : `${metric(stats.total_storage_mb)} MB`}
          icon={HardDrive}
          hint={stats.total_storage_mb === null ? 'Awaiting first stats refresh' : undefined}
        />
      </section>
    </div>
  )
}
