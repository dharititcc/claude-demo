import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { ArrowUpRight, DollarSign, TrendingUp, UserPlus, Users } from 'lucide-react'
import { api } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import type { DashboardStats } from '@/types'

const STATUS_COLORS: Record<string, string> = {
  active: '#10b981',
  lead: '#6366f1',
  inactive: '#f59e0b',
  churned: '#ef4444',
}

function StatCard({
  label,
  value,
  icon: Icon,
  hint,
}: {
  label: string
  value: string
  icon: typeof Users
  hint?: string
}) {
  return (
    <Card>
      <CardContent className="flex items-center justify-between pt-6">
        <div className="min-w-0">
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 truncate text-2xl font-semibold">{value}</p>
          {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
        <div className="ml-3 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
          <Icon size={20} />
        </div>
      </CardContent>
    </Card>
  )
}

export default function DashboardPage() {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const user = useAuthStore((s) => s.user)

  const { data, isLoading, isError } = useQuery({
    // Scoped by org: metrics must never be served from another org's cache.
    queryKey: ['dashboard', orgSlug],
    queryFn: async () => {
      const { data } = await api.get<{ data: DashboardStats }>('/v1/dashboard')
      return data.data
    },
    enabled: Boolean(orgSlug),
  })

  // No active organization — the dashboard is org-scoped, so there is nothing to
  // show. A super admin belongs to no organization by design, so point them at
  // the control plane instead of leaving them on a dead page.
  if (!orgSlug) {
    return (
      <Card>
        <CardContent className="space-y-3 pt-6 text-center">
          <p className="text-muted-foreground">
            {user?.is_super_admin
              ? 'You are signed in as a platform admin and belong to no organization.'
              : 'You have no active organization. Create or join one to get started.'}
          </p>
          {user?.is_super_admin && (
            <Link
              to="/admin"
              className="inline-flex text-sm font-medium text-primary hover:underline"
            >
              Go to Platform Admin →
            </Link>
          )}
        </CardContent>
      </Card>
    )
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (isError || !data) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-muted-foreground">
          Could not load the dashboard. Please try again.
        </CardContent>
      </Card>
    )
  }

  const currency = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: data.revenue.currency || 'USD',
    maximumFractionDigits: 0,
  })

  const statusData = Object.entries(data.customers.by_status).map(([name, value]) => ({
    name,
    value,
  }))

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{data.organization.name}</h1>
          <p className="text-sm text-muted-foreground">Here's how things look today.</p>
        </div>
        {data.organization.on_trial && data.organization.trial_ends_at && (
          <Badge variant="warning">
            Trial ends {new Date(data.organization.trial_ends_at).toLocaleDateString()}
          </Badge>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Total customers" value={String(data.customers.total)} icon={Users} />
        <StatCard
          label="Active"
          value={String(data.customers.by_status.active ?? 0)}
          icon={TrendingUp}
        />
        <StatCard
          label="New this month"
          value={String(data.customers.new_this_month)}
          icon={UserPlus}
        />
        <StatCard
          label="Lifetime value"
          value={currency.format(data.revenue.lifetime_value)}
          icon={DollarSign}
          hint="Active customers"
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>New customers</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data.growth}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                  <XAxis
                    dataKey="label"
                    stroke="var(--muted-foreground)"
                    fontSize={12}
                    tickLine={false}
                    axisLine={false}
                  />
                  <YAxis
                    stroke="var(--muted-foreground)"
                    fontSize={12}
                    tickLine={false}
                    axisLine={false}
                    allowDecimals={false}
                  />
                  <Tooltip
                    contentStyle={{
                      background: 'var(--card)',
                      border: '1px solid var(--border)',
                      borderRadius: 8,
                      color: 'var(--foreground)',
                    }}
                  />
                  <Bar dataKey="count" fill="var(--primary)" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>By status</CardTitle>
          </CardHeader>
          <CardContent>
            {statusData.length === 0 ? (
              <p className="py-16 text-center text-sm text-muted-foreground">No customers yet.</p>
            ) : (
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={statusData} dataKey="value" nameKey="name" innerRadius={50} outerRadius={80}>
                      {statusData.map((entry) => (
                        <Cell key={entry.name} fill={STATUS_COLORS[entry.name] ?? '#94a3b8'} />
                      ))}
                    </Pie>
                    <Tooltip
                      contentStyle={{
                        background: 'var(--card)',
                        border: '1px solid var(--border)',
                        borderRadius: 8,
                        color: 'var(--foreground)',
                      }}
                    />
                  </PieChart>
                </ResponsiveContainer>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle>Recent customers</CardTitle>
          <Link
            to="/customers"
            className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
          >
            View all <ArrowUpRight size={14} />
          </Link>
        </CardHeader>
        <CardContent>
          {data.recent_customers.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              No customers yet.{' '}
              <Link to="/customers" className="text-primary hover:underline">
                Add your first one.
              </Link>
            </p>
          ) : (
            <ul className="divide-y">
              {data.recent_customers.map((customer) => (
                <li key={customer.id} className="flex items-center justify-between py-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{customer.name}</p>
                    <p className="truncate text-sm text-muted-foreground">
                      {customer.company ?? customer.email ?? '—'}
                    </p>
                  </div>
                  <Badge variant={customer.status === 'active' ? 'success' : 'muted'}>
                    {customer.status}
                  </Badge>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
