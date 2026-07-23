import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Toggle } from '@/components/ui/Toggle'
import { useCreatePlan, useUpdatePlan } from '@/hooks/useAdmin'
import type { AdminPlan, AdminPlanPayload } from '@/types/admin'

/**
 * A whole number, or blank. Number inputs still hand back strings, so validate
 * as a string and convert at the submit boundary (z.coerce.number() would type
 * the field as `unknown` and break react-hook-form's inference).
 */
const wholeOrBlank = (message: string) =>
  z.string().refine((v) => v === '' || /^\d+$/.test(v), message)

/** A money amount in major units, e.g. 49 or 49.00. */
const amount = z
  .string()
  .refine((v) => v === '' || /^\d+(\.\d{1,2})?$/.test(v), 'Enter an amount, e.g. 49.00.')

const schema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  slug: z
    .string()
    .max(255)
    .refine(
      (v) => v === '' || /^[a-z0-9]+(-[a-z0-9]+)*$/.test(v),
      'Lowercase letters, numbers and single dashes only.',
    ),
  description: z.string().max(1000),
  monthly_amount: amount,
  annual_amount: amount,
  currency: z.string().length(3, 'Use a 3-letter code, e.g. USD.'),
  trial_days: wholeOrBlank('Enter a number of days.'),
  max_users: wholeOrBlank('Enter a number, or leave blank for unlimited.'),
  max_customers: wholeOrBlank('Enter a number, or leave blank for unlimited.'),
  max_storage_mb: wholeOrBlank('Enter a number, or leave blank for unlimited.'),
  stripe_monthly_price_id: z.string().max(255),
  stripe_annual_price_id: z.string().max(255),
  features: z.string(), // one per line in the UI, split on submit
  is_active: z.boolean(),
  sort_order: wholeOrBlank('Enter a number.'),
})

type FormValues = z.infer<typeof schema>

const empty: FormValues = {
  name: '',
  slug: '',
  description: '',
  monthly_amount: '',
  annual_amount: '',
  currency: 'USD',
  trial_days: '14',
  max_users: '',
  max_customers: '',
  max_storage_mb: '',
  stripe_monthly_price_id: '',
  stripe_annual_price_id: '',
  features: '',
  is_active: true,
  sort_order: '',
}

/** Minor units (what the API stores) to a major-unit string for the form. */
function toMajor(minor: number): string {
  return (minor / 100).toFixed(2)
}

/**
 * A blank limit means unlimited (null); "0" means none allowed. The two are
 * deliberately different in UsageService, so the empty string must map to null
 * rather than to 0.
 */
function limit(value: string): number | null {
  return value === '' ? null : Number(value)
}

/**
 * Create/edit dialog for the plan catalogue. One component serves both so the
 * field list and validation cannot drift between them.
 */
export function PlanFormDialog({
  open,
  plan,
  onClose,
}: {
  open: boolean
  plan: AdminPlan | null
  onClose: () => void
}) {
  const create = useCreatePlan()
  const update = useUpdatePlan()

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty })

  // Repopulate whenever the target changes — the dialog is reused, so values
  // from a previously edited plan would otherwise persist.
  useEffect(() => {
    if (!open) return

    reset(
      plan
        ? {
            name: plan.name,
            slug: plan.slug,
            description: plan.description ?? '',
            monthly_amount: toMajor(plan.monthly_amount),
            annual_amount: toMajor(plan.annual_amount),
            currency: plan.currency,
            trial_days: String(plan.trial_days),
            // null (unlimited) renders as blank, not "0".
            max_users: plan.limits.users === null ? '' : String(plan.limits.users),
            max_customers: plan.limits.customers === null ? '' : String(plan.limits.customers),
            max_storage_mb: plan.limits.storage_mb === null ? '' : String(plan.limits.storage_mb),
            stripe_monthly_price_id: plan.stripe.monthly_price_id ?? '',
            stripe_annual_price_id: plan.stripe.annual_price_id ?? '',
            features: (plan.features ?? []).join('\n'),
            is_active: plan.is_active,
            sort_order: String(plan.sort_order),
          }
        : empty,
    )
  }, [open, plan, reset])

  // Close on Escape.
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  const pending = create.isPending || update.isPending

  function onSubmit(values: FormValues) {
    const payload: AdminPlanPayload = {
      name: values.name,
      description: values.description || null,
      // Minor units: the column stores cents.
      monthly_amount: values.monthly_amount === '' ? 0 : Math.round(Number(values.monthly_amount) * 100),
      annual_amount: values.annual_amount === '' ? 0 : Math.round(Number(values.annual_amount) * 100),
      currency: values.currency.toUpperCase(),
      trial_days: values.trial_days === '' ? 0 : Number(values.trial_days),
      max_users: limit(values.max_users),
      max_customers: limit(values.max_customers),
      max_storage_mb: limit(values.max_storage_mb),
      // Send null rather than "" so the API stores an absent value, not a blank.
      stripe_monthly_price_id: values.stripe_monthly_price_id || null,
      stripe_annual_price_id: values.stripe_annual_price_id || null,
      features: values.features
        .split('\n')
        .map((f) => f.trim())
        .filter(Boolean),
      is_active: values.is_active,
    }

    if (values.sort_order !== '') payload.sort_order = Number(values.sort_order)
    // Omitted on create so the API derives it from the name.
    if (values.slug !== '') payload.slug = values.slug

    const mutation = plan
      ? update.mutateAsync({ id: plan.id, payload })
      : create.mutateAsync(payload)

    mutation.then(onClose).catch(() => {
      /* toast is raised by the hook */
    })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden />

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="plan-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-2xl overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 id="plan-dialog-title" className="text-lg font-semibold">
            {plan ? `Edit ${plan.name}` : 'New plan'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="p-name" className="mb-1.5 block text-sm font-medium">
                Name
              </label>
              <Input id="p-name" error={errors.name?.message} {...register('name')} />
            </div>

            <div>
              <label htmlFor="p-slug" className="mb-1.5 block text-sm font-medium">
                Slug
              </label>
              <Input
                id="p-slug"
                placeholder={plan ? '' : 'derived from the name'}
                error={errors.slug?.message}
                {...register('slug')}
              />
            </div>
          </div>

          <div>
            <label htmlFor="p-desc" className="mb-1.5 block text-sm font-medium">
              Description
            </label>
            <textarea
              id="p-desc"
              rows={2}
              className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              {...register('description')}
            />
          </div>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Pricing</legend>
            <p className="mb-3 text-xs text-muted-foreground">
              Shown to customers. When an interval has a Stripe price id, its amount is taken from
              Stripe on save and what you type here is ignored — Stripe decides what is charged.
            </p>

            <div className="grid gap-4 sm:grid-cols-3">
              <div>
                <label htmlFor="p-monthly" className="mb-1.5 block text-sm font-medium">
                  Monthly
                </label>
                <Input id="p-monthly" placeholder="49.00" error={errors.monthly_amount?.message} {...register('monthly_amount')} />
              </div>
              <div>
                <label htmlFor="p-annual" className="mb-1.5 block text-sm font-medium">
                  Annual
                </label>
                <Input id="p-annual" placeholder="490.00" error={errors.annual_amount?.message} {...register('annual_amount')} />
              </div>
              <div>
                <label htmlFor="p-currency" className="mb-1.5 block text-sm font-medium">
                  Currency
                </label>
                <Input id="p-currency" maxLength={3} error={errors.currency?.message} {...register('currency')} />
              </div>
            </div>
          </fieldset>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Limits</legend>
            <p className="mb-3 text-xs text-muted-foreground">
              Leave blank for <strong>unlimited</strong>. Enter <strong>0</strong> to allow none —
              the two are not the same.
            </p>

            <div className="grid gap-4 sm:grid-cols-3">
              <div>
                <label htmlFor="p-users" className="mb-1.5 block text-sm font-medium">
                  Users
                </label>
                <Input id="p-users" placeholder="unlimited" error={errors.max_users?.message} {...register('max_users')} />
              </div>
              <div>
                <label htmlFor="p-customers" className="mb-1.5 block text-sm font-medium">
                  Customers
                </label>
                <Input id="p-customers" placeholder="unlimited" error={errors.max_customers?.message} {...register('max_customers')} />
              </div>
              <div>
                <label htmlFor="p-storage" className="mb-1.5 block text-sm font-medium">
                  Storage (MB)
                </label>
                <Input id="p-storage" placeholder="unlimited" error={errors.max_storage_mb?.message} {...register('max_storage_mb')} />
              </div>
            </div>
          </fieldset>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Stripe</legend>
            <p className="mb-3 text-xs text-muted-foreground">
              An interval with no price id cannot be subscribed to — checkout will refuse it.
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="p-sm" className="mb-1.5 block text-sm font-medium">
                  Monthly price id
                </label>
                <Input id="p-sm" placeholder="price_…" error={errors.stripe_monthly_price_id?.message} {...register('stripe_monthly_price_id')} />
              </div>
              <div>
                <label htmlFor="p-sa" className="mb-1.5 block text-sm font-medium">
                  Annual price id
                </label>
                <Input id="p-sa" placeholder="price_…" error={errors.stripe_annual_price_id?.message} {...register('stripe_annual_price_id')} />
              </div>
            </div>
          </fieldset>

          <div>
            <label htmlFor="p-features" className="mb-1.5 block text-sm font-medium">
              Features
            </label>
            <textarea
              id="p-features"
              rows={4}
              placeholder="One per line"
              className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              {...register('features')}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="p-trial" className="mb-1.5 block text-sm font-medium">
                Trial days
              </label>
              <Input id="p-trial" error={errors.trial_days?.message} {...register('trial_days')} />
            </div>
            <div>
              <label htmlFor="p-sort" className="mb-1.5 block text-sm font-medium">
                Sort order
              </label>
              <Input id="p-sort" placeholder={plan ? '' : 'last'} error={errors.sort_order?.message} {...register('sort_order')} />
            </div>
          </div>

          {/*
            On its own row rather than squeezed beside the number fields: this is
            the only control here that decides whether customers can buy the
            plan, and it needs the explanation more than the alignment.
          */}
          <Controller
            name="is_active"
            control={control}
            render={({ field }) => (
              <Toggle
                id="p-active"
                label="Active"
                description="Inactive plans are hidden from the pricing page and cannot be subscribed to. Existing subscribers keep their limits."
                checked={field.value}
                onChange={(e) => field.onChange(e.target.checked)}
                onBlur={field.onBlur}
                ref={field.ref}
              />
            )}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {plan ? 'Save changes' : 'Create plan'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
