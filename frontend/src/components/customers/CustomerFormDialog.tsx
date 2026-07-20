import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useCreateCustomer, useUpdateCustomer } from '@/hooks/useCustomers'
import type { Customer } from '@/types'

const schema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  email: z.string().email('Enter a valid email.').or(z.literal('')),
  phone: z.string().max(50).or(z.literal('')),
  company: z.string().max(255).or(z.literal('')),
  website: z.string().url('Enter a full URL (https://…).').or(z.literal('')),
  status: z.enum(['lead', 'active', 'inactive', 'churned']),
  // A number input still hands back a string, so validate it as one and convert
  // at the submit boundary. z.coerce.number() would type the field as `unknown`
  // and break react-hook-form's inference.
  lifetime_value: z
    .string()
    .refine((v) => v === '' || !Number.isNaN(Number(v)), 'Enter a number.')
    .refine((v) => v === '' || Number(v) >= 0, 'Cannot be negative.'),
  tags: z.string(), // comma-separated in the UI, split on submit
})

type FormValues = z.infer<typeof schema>

const empty: FormValues = {
  name: '',
  email: '',
  phone: '',
  company: '',
  website: '',
  status: 'lead',
  lifetime_value: '0',
  tags: '',
}

/**
 * Create/edit dialog. One component serves both so the field list and
 * validation can't drift between them.
 */
export function CustomerFormDialog({
  open,
  customer,
  onClose,
}: {
  open: boolean
  customer: Customer | null
  onClose: () => void
}) {
  const create = useCreateCustomer()
  const update = useUpdateCustomer()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty })

  // Repopulate whenever the target changes — the dialog is reused, so stale
  // values from a previous customer would otherwise persist.
  useEffect(() => {
    if (!open) return

    reset(
      customer
        ? {
            name: customer.name,
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            company: customer.company ?? '',
            website: customer.website ?? '',
            status: customer.status,
            lifetime_value: String(customer.lifetime_value),
            tags: (customer.tags ?? []).map((t) => t.name).join(', '),
          }
        : empty,
    )
  }, [open, customer, reset])

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
    const payload = {
      ...values,
      lifetime_value: values.lifetime_value === '' ? 0 : Number(values.lifetime_value),
      // Send null rather than "" so the API stores an absent value, not a blank.
      email: values.email || null,
      phone: values.phone || null,
      company: values.company || null,
      website: values.website || null,
      tags: values.tags
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean),
    }

    const mutation = customer
      ? update.mutateAsync({ id: customer.id, payload })
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
        aria-labelledby="customer-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-lg overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 id="customer-dialog-title" className="text-lg font-semibold">
            {customer ? 'Edit customer' : 'New customer'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div>
            <label htmlFor="c-name" className="mb-1.5 block text-sm font-medium">
              Name *
            </label>
            <Input id="c-name" autoFocus error={errors.name?.message} {...register('name')} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-email" className="mb-1.5 block text-sm font-medium">
                Email
              </label>
              <Input id="c-email" type="email" error={errors.email?.message} {...register('email')} />
            </div>
            <div>
              <label htmlFor="c-phone" className="mb-1.5 block text-sm font-medium">
                Phone
              </label>
              <Input id="c-phone" error={errors.phone?.message} {...register('phone')} />
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-company" className="mb-1.5 block text-sm font-medium">
                Company
              </label>
              <Input id="c-company" error={errors.company?.message} {...register('company')} />
            </div>
            <div>
              <label htmlFor="c-website" className="mb-1.5 block text-sm font-medium">
                Website
              </label>
              <Input
                id="c-website"
                placeholder="https://example.com"
                error={errors.website?.message}
                {...register('website')}
              />
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-status" className="mb-1.5 block text-sm font-medium">
                Status
              </label>
              <select
                id="c-status"
                className="h-10 w-full rounded-md border bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                {...register('status')}
              >
                <option value="lead">Lead</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="churned">Churned</option>
              </select>
            </div>
            <div>
              <label htmlFor="c-ltv" className="mb-1.5 block text-sm font-medium">
                Lifetime value
              </label>
              <Input
                id="c-ltv"
                type="number"
                step="0.01"
                min="0"
                error={errors.lifetime_value?.message}
                {...register('lifetime_value')}
              />
            </div>
          </div>

          <div>
            <label htmlFor="c-tags" className="mb-1.5 block text-sm font-medium">
              Tags
            </label>
            <Input
              id="c-tags"
              placeholder="vip, enterprise"
              error={errors.tags?.message}
              {...register('tags')}
            />
            <p className="mt-1 text-xs text-muted-foreground">Separate with commas.</p>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {customer ? 'Save changes' : 'Create customer'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
