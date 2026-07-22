import { useState } from 'react'
import { AlertTriangle, Check, Pencil, Plus, Trash2 } from 'lucide-react'
import { useAdminPlans, useDeletePlan } from '@/hooks/useAdmin'
import { usePageTitle } from '@/hooks/usePageTitle'
import { PlanFormDialog } from '@/components/admin/PlanFormDialog'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { metric } from '@/lib/adminFormat'
import type { AdminPlan } from '@/types/admin'

/** Minor units to a readable price. */
function money(minor: number, currency: string): string {
  return (minor / 100).toLocaleString(undefined, {
    style: 'currency',
    currency: currency || 'USD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

/** null is unlimited — deliberately different from 0 ("none allowed"). */
function limitText(value: number | null): string {
  return value === null ? 'Unlimited' : metric(value)
}

export default function AdminPlansPage() {
  usePageTitle('Plans · Admin')

  const plans = useAdminPlans()
  const remove = useDeletePlan()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<AdminPlan | null>(null)

  function openNew() {
    setEditing(null)
    setDialogOpen(true)
  }

  function openEdit(plan: AdminPlan) {
    setEditing(plan)
    setDialogOpen(true)
  }

  function onDelete(plan: AdminPlan) {
    // The API refuses this too — this is only to save a round trip and to
    // explain the rule before the click.
    if (plan.organizations_count > 0) return

    if (!window.confirm(`Delete the ${plan.name} plan? This cannot be undone.`)) return

    remove.mutate(plan.id)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Plans</h1>
          <p className="text-sm text-muted-foreground">
            The subscription catalogue. Changes take effect on each organization's next request.
          </p>
        </div>
        <Button onClick={openNew}>
          <Plus size={16} className="mr-1.5" />
          New plan
        </Button>
      </div>

      <Card className="overflow-hidden">
        {plans.isLoading ? (
          <div className="flex h-64 items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : plans.isError ? (
          <p className="p-6 text-sm text-destructive">Could not load the plans.</p>
        ) : (plans.data ?? []).length === 0 ? (
          <p className="p-6 text-sm text-muted-foreground">
            No plans yet. Add one to start selling subscriptions.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Plan</th>
                  <th className="px-4 py-3">Price</th>
                  <th className="px-4 py-3">Limits</th>
                  <th className="px-4 py-3">Stripe</th>
                  <th className="px-4 py-3">Orgs</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {(plans.data ?? []).map((plan) => (
                  <tr key={plan.id} className="border-b last:border-0">
                    <td className="px-4 py-3">
                      <div className="font-medium">{plan.name}</div>
                      <div className="text-xs text-muted-foreground">{plan.slug}</div>
                    </td>

                    <td className="px-4 py-3 whitespace-nowrap">
                      <div>{money(plan.monthly_amount, plan.currency)} /mo</div>
                      <div className="text-xs text-muted-foreground">
                        {money(plan.annual_amount, plan.currency)} /yr
                      </div>
                    </td>

                    <td className="px-4 py-3 text-xs whitespace-nowrap">
                      <div>{limitText(plan.limits.users)} users</div>
                      <div>{limitText(plan.limits.customers)} customers</div>
                      <div className="text-muted-foreground">
                        {limitText(plan.limits.storage_mb)} MB storage
                      </div>
                    </td>

                    {/*
                      An interval with no price id cannot be subscribed to at
                      all — checkout refuses it. Surfacing that here means it is
                      found in the catalogue rather than at the till.
                    */}
                    <td className="px-4 py-3">
                      <div className="flex flex-col gap-1">
                        <StripeFlag ready={plan.stripe.monthly_ready} label="Monthly" />
                        <StripeFlag ready={plan.stripe.annual_ready} label="Annual" />
                      </div>
                    </td>

                    <td className="px-4 py-3 tabular-nums">{metric(plan.organizations_count)}</td>

                    <td className="px-4 py-3">
                      <Badge variant={plan.is_active ? 'success' : 'muted'}>
                        {plan.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </td>

                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label={`Edit ${plan.name}`}
                          onClick={() => openEdit(plan)}
                        >
                          <Pencil size={16} />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label={`Delete ${plan.name}`}
                          disabled={plan.organizations_count > 0 || remove.isPending}
                          title={
                            plan.organizations_count > 0
                              ? 'In use by an organization — deactivate it instead.'
                              : 'Delete this plan'
                          }
                          onClick={() => onDelete(plan)}
                        >
                          <Trash2 size={16} />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <PlanFormDialog open={dialogOpen} plan={editing} onClose={() => setDialogOpen(false)} />
    </div>
  )
}

function StripeFlag({ ready, label }: { ready: boolean; label: string }) {
  return (
    <span
      className={`inline-flex items-center gap-1 text-xs ${ready ? 'text-muted-foreground' : 'text-destructive'}`}
    >
      {ready ? <Check size={12} /> : <AlertTriangle size={12} />}
      {label}
      {!ready && ' — no price id'}
    </span>
  )
}
