import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { AlertTriangle, Check, CreditCard, Download, ExternalLink } from 'lucide-react'
import { billingService } from '@/services/billing'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { cn, safeHttpUrl } from '@/lib/utils'
import { formatDate } from '@/lib/date'
import type { BillingInterval, Plan, UsageMetric } from '@/types'
import { usePageTitle } from '@/hooks/usePageTitle'

function money(minorUnits: number, currency: string): string {
  return (minorUnits / 100).toLocaleString(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  })
}

function UsageBar({ label, metric }: { label: string; metric: UsageMetric }) {
  // A null limit means unlimited — never render a bar implying a ceiling.
  const unlimited = metric.limit === null
  const pct = unlimited ? 0 : Math.min(100, (metric.used / Math.max(1, metric.limit!)) * 100)

  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between text-sm">
        <span>{label}</span>
        <span className={cn('tabular-nums', metric.exceeded && 'font-medium text-destructive')}>
          {metric.used.toLocaleString()}
          {unlimited ? (
            <span className="text-muted-foreground"> / unlimited</span>
          ) : (
            <span className="text-muted-foreground"> / {metric.limit!.toLocaleString()}</span>
          )}
        </span>
      </div>
      {!unlimited && (
        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
          <div
            className={cn(
              'h-full rounded-full transition-all',
              metric.exceeded ? 'bg-destructive' : pct > 80 ? 'bg-amber-500' : 'bg-primary',
            )}
            style={{ width: `${pct}%` }}
          />
        </div>
      )}
    </div>
  )
}

export default function BillingPage() {
  usePageTitle('Billing')

  const [interval, setInterval] = useState<BillingInterval>('monthly')
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const can = useAuthStore((s) => s.can)
  const queryClient = useQueryClient()

  const overview = useQuery({
    queryKey: ['billing', orgSlug],
    queryFn: billingService.overview,
    enabled: Boolean(orgSlug),
  })

  const plans = useQuery({
    queryKey: ['plans', orgSlug],
    queryFn: billingService.plans,
    enabled: Boolean(orgSlug),
  })

  const invoices = useQuery({
    queryKey: ['invoices', orgSlug],
    queryFn: billingService.invoices,
    enabled: Boolean(orgSlug && can('billing.view')),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['billing', orgSlug] })

  const swap = useMutation({
    mutationFn: (plan: Plan) => billingService.swapPlan({ plan: plan.slug, interval }),
    onSuccess: () => {
      toast.success('Plan updated.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not change the plan.')),
  })

  const cancel = useMutation({
    mutationFn: billingService.cancel,
    onSuccess: () => {
      toast.success('Subscription will end at the close of this period.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not cancel.')),
  })

  const resume = useMutation({
    mutationFn: billingService.resume,
    onSuccess: () => {
      toast.success('Subscription resumed.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not resume.')),
  })

  if (overview.isLoading || plans.isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (!overview.data || !plans.data) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-muted-foreground">
          Could not load billing information.
        </CardContent>
      </Card>
    )
  }

  const { subscription, usage, payment_method: pm } = overview.data
  const manage = can('billing.manage')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Billing</h1>
        <p className="text-sm text-muted-foreground">Plan, usage, and invoices for this organization.</p>
      </div>

      {!manage && (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">
            Only an owner can change the plan or payment method.
          </CardContent>
        </Card>
      )}

      {subscription.cancelled && subscription.on_grace_period && (
        <Card className="border-amber-500/50">
          <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-6">
            <div className="flex items-start gap-2">
              <AlertTriangle size={18} className="mt-0.5 shrink-0 text-amber-500" />
              <div>
                <p className="text-sm font-medium">Your subscription is ending</p>
                <p className="text-sm text-muted-foreground">
                  Access continues until{' '}
                  {subscription.ends_at && formatDate(subscription.ends_at)}.
                </p>
              </div>
            </div>
            {manage && (
              <Button size="sm" onClick={() => resume.mutate()} loading={resume.isPending}>
                Resume subscription
              </Button>
            )}
          </CardContent>
        </Card>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Current plan</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <p className="text-xl font-semibold">{subscription.plan?.name ?? 'No plan'}</p>
              <div className="mt-1 flex flex-wrap items-center gap-2">
                {subscription.status && (
                  <Badge variant={subscription.active ? 'success' : 'muted'}>
                    {subscription.status}
                  </Badge>
                )}
                {subscription.on_trial && subscription.trial_ends_at && (
                  <Badge variant="warning">
                    Trial ends {formatDate(subscription.trial_ends_at)}
                  </Badge>
                )}
                {subscription.interval && <Badge variant="muted">{subscription.interval}</Badge>}
              </div>
              {subscription.renews_at && !subscription.cancelled && (
                <p className="mt-2 text-xs text-muted-foreground">
                  Renews {formatDate(subscription.renews_at)}
                </p>
              )}
            </div>
            {manage && subscription.active && !subscription.cancelled && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  if (window.confirm('Cancel at the end of the current period?')) cancel.mutate()
                }}
                loading={cancel.isPending}
              >
                Cancel plan
              </Button>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <CreditCard size={16} /> Payment method
            </CardTitle>
          </CardHeader>
          <CardContent>
            {pm?.last_four ? (
              <div>
                <p className="text-sm">
                  <span className="capitalize">{pm.brand}</span> ···· {pm.last_four}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Expires {pm.exp_month}/{pm.exp_year}
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">No card on file.</p>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Usage</CardTitle>
          <CardDescription>Against your plan's limits this period.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <UsageBar label="Team members" metric={usage.users} />
          <UsageBar label="Customers" metric={usage.customers} />
          <UsageBar label="Storage (MB)" metric={usage.storage_mb} />
        </CardContent>
      </Card>

      <div>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Plans</h2>
          <div className="inline-flex rounded-md border p-0.5" role="group" aria-label="Billing interval">
            {(['monthly', 'annual'] as const).map((i) => (
              <button
                key={i}
                type="button"
                onClick={() => setInterval(i)}
                aria-pressed={interval === i}
                className={cn(
                  'rounded px-3 py-1 text-sm capitalize transition-colors',
                  interval === i ? 'bg-primary text-primary-foreground' : 'hover:bg-accent',
                )}
              >
                {i}
                {i === 'annual' && <span className="ml-1 text-xs opacity-80">2 months free</span>}
              </button>
            ))}
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {plans.data.map((plan) => (
            <Card key={plan.id} className={cn(plan.is_current && 'border-primary ring-1 ring-primary')}>
              <CardHeader>
                <CardTitle className="flex items-center justify-between">
                  {plan.name}
                  {plan.is_current && <Badge>Current</Badge>}
                </CardTitle>
                <CardDescription>{plan.description}</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <p className="text-2xl font-semibold">
                  {money(interval === 'annual' ? plan.annual_amount : plan.monthly_amount, plan.currency)}
                  <span className="text-sm font-normal text-muted-foreground">
                    /{interval === 'annual' ? 'yr' : 'mo'}
                  </span>
                </p>

                <ul className="space-y-1.5">
                  {plan.features.map((f) => (
                    <li key={f} className="flex items-start gap-2 text-sm">
                      <Check size={14} className="mt-0.5 shrink-0 text-primary" />
                      {f}
                    </li>
                  ))}
                </ul>

                {manage && !plan.is_current && (
                  <Button
                    className="w-full"
                    variant={plan.monthly_amount === 0 ? 'outline' : 'primary'}
                    loading={swap.isPending}
                    onClick={() => swap.mutate(plan)}
                  >
                    {subscription.active ? 'Switch to this plan' : 'Choose plan'}
                  </Button>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      </div>

      {can('billing.view') && (
        <Card>
          <CardHeader>
            <CardTitle>Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            {!invoices.data?.length ? (
              <p className="py-6 text-center text-sm text-muted-foreground">No invoices yet.</p>
            ) : (
              <ul className="divide-y">
                {invoices.data.map((invoice) => (
                  <li key={invoice.id} className="flex items-center justify-between gap-3 py-3">
                    <div className="min-w-0">
                      <p className="text-sm font-medium">
                        {invoice.number ?? invoice.id}
                        <Badge variant={invoice.paid ? 'success' : 'warning'} className="ml-2">
                          {invoice.status}
                        </Badge>
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {formatDate(invoice.date)} · {invoice.total}
                        {invoice.tax && ` (incl. ${invoice.tax} tax)`}
                      </p>
                    </div>
                    <a href={safeHttpUrl(invoice.download_url)} target="_blank" rel="noreferrer">
                      <Button variant="ghost" size="sm">
                        <Download size={14} /> PDF
                        <ExternalLink size={12} className="opacity-60" />
                      </Button>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
