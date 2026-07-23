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

  // Company details. Optional strings — the API accepts null for each, and
  // the empty string is converted at the submit boundary.
  mobile: z.string().max(50),
  trading_name: z.string().max(255),
  tax_number: z.string().max(64),
  registration_number: z.string().max(64),
  industry: z.string().max(100),
  currency: z.string().refine((v) => v === '' || /^[A-Za-z]{3}$/.test(v), 'Use a 3-letter code.'),

  address_line1: z.string().max(255),
  city: z.string().max(255),
  state: z.string().max(255),
  postal_code: z.string().max(20),
  country: z.string().refine((v) => v === '' || /^[A-Za-z]{2}$/.test(v), 'Use a 2-letter code.'),

  shipping_address_line1: z.string().max(255),
  shipping_city: z.string().max(255),
  shipping_state: z.string().max(255),
  shipping_postal_code: z.string().max(20),
  shipping_country: z.string().refine((v) => v === '' || /^[A-Za-z]{2}$/.test(v), 'Use a 2-letter code.'),
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
  mobile: '',
  trading_name: '',
  tax_number: '',
  registration_number: '',
  industry: '',
  currency: '',
  address_line1: '',
  city: '',
  state: '',
  postal_code: '',
  country: '',
  shipping_address_line1: '',
  shipping_city: '',
  shipping_state: '',
  shipping_postal_code: '',
  shipping_country: '',
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
            mobile: customer.mobile ?? '',
            trading_name: customer.trading_name ?? '',
            tax_number: customer.tax_number ?? '',
            registration_number: customer.registration_number ?? '',
            industry: customer.industry ?? '',
            currency: customer.currency ?? '',
            address_line1: customer.address?.line1 ?? '',
            city: customer.address?.city ?? '',
            state: customer.address?.state ?? '',
            postal_code: customer.address?.postal_code ?? '',
            country: customer.address?.country ?? '',
            shipping_address_line1: customer.shipping_address?.line1 ?? '',
            shipping_city: customer.shipping_address?.city ?? '',
            shipping_state: customer.shipping_address?.state ?? '',
            shipping_postal_code: customer.shipping_address?.postal_code ?? '',
            shipping_country: customer.shipping_address?.country ?? '',
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
      mobile: values.mobile || null,
      trading_name: values.trading_name || null,
      tax_number: values.tax_number || null,
      registration_number: values.registration_number || null,
      industry: values.industry || null,
      currency: values.currency ? values.currency.toUpperCase() : null,
      address_line1: values.address_line1 || null,
      city: values.city || null,
      state: values.state || null,
      postal_code: values.postal_code || null,
      country: values.country ? values.country.toUpperCase() : null,
      shipping_address_line1: values.shipping_address_line1 || null,
      shipping_city: values.shipping_city || null,
      shipping_state: values.shipping_state || null,
      shipping_postal_code: values.shipping_postal_code || null,
      shipping_country: values.shipping_country ? values.shipping_country.toUpperCase() : null,
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
          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Company</legend>

            <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-trading" className="mb-1.5 block text-sm font-medium">
                Trading name
              </label>
              <Input id="c-trading" error={errors.trading_name?.message} {...register('trading_name')} />
            </div>
            <div>
              <label htmlFor="c-industry" className="mb-1.5 block text-sm font-medium">
                Industry
              </label>
              <Input id="c-industry" error={errors.industry?.message} {...register('industry')} />
            </div>
            <div>
              <label htmlFor="c-tax" className="mb-1.5 block text-sm font-medium">
                Tax number
              </label>
              <Input id="c-tax" error={errors.tax_number?.message} {...register('tax_number')} />
            </div>
            <div>
              <label htmlFor="c-reg" className="mb-1.5 block text-sm font-medium">
                Registration number
              </label>
              <Input id="c-reg" error={errors.registration_number?.message} {...register('registration_number')} />
            </div>
            <div>
              <label htmlFor="c-mobile" className="mb-1.5 block text-sm font-medium">
                Mobile
              </label>
              <Input id="c-mobile" error={errors.mobile?.message} {...register('mobile')} />
            </div>
            <div>
              <label htmlFor="c-currency" className="mb-1.5 block text-sm font-medium">
                Currency
              </label>
              <Input id="c-currency" maxLength={3} placeholder="USD" error={errors.currency?.message} {...register('currency')} />
            </div>
            </div>
          </fieldset>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Billing address</legend>

            <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-addr1" className="mb-1.5 block text-sm font-medium">
                Address
              </label>
              <Input id="c-addr1" error={errors.address_line1?.message} {...register('address_line1')} />
            </div>
            <div>
              <label htmlFor="c-city" className="mb-1.5 block text-sm font-medium">
                City
              </label>
              <Input id="c-city" error={errors.city?.message} {...register('city')} />
            </div>
            <div>
              <label htmlFor="c-state" className="mb-1.5 block text-sm font-medium">
                State
              </label>
              <Input id="c-state" error={errors.state?.message} {...register('state')} />
            </div>
            <div>
              <label htmlFor="c-postal" className="mb-1.5 block text-sm font-medium">
                Postal code
              </label>
              <Input id="c-postal" error={errors.postal_code?.message} {...register('postal_code')} />
            </div>
            <div>
              <label htmlFor="c-country" className="mb-1.5 block text-sm font-medium">
                Country
              </label>
              <Input id="c-country" maxLength={2} placeholder="GB" error={errors.country?.message} {...register('country')} />
            </div>
            </div>
          </fieldset>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Shipping address</legend>
            <p className="mb-3 text-xs text-muted-foreground">
              Leave blank if goods go to the billing address.
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-saddr1" className="mb-1.5 block text-sm font-medium">
                Address
              </label>
              <Input id="c-saddr1" error={errors.shipping_address_line1?.message} {...register('shipping_address_line1')} />
            </div>
            <div>
              <label htmlFor="c-scity" className="mb-1.5 block text-sm font-medium">
                City
              </label>
              <Input id="c-scity" error={errors.shipping_city?.message} {...register('shipping_city')} />
            </div>
            <div>
              <label htmlFor="c-sstate" className="mb-1.5 block text-sm font-medium">
                State
              </label>
              <Input id="c-sstate" error={errors.shipping_state?.message} {...register('shipping_state')} />
            </div>
            <div>
              <label htmlFor="c-spostal" className="mb-1.5 block text-sm font-medium">
                Postal code
              </label>
              <Input id="c-spostal" error={errors.shipping_postal_code?.message} {...register('shipping_postal_code')} />
            </div>
            <div>
              <label htmlFor="c-scountry" className="mb-1.5 block text-sm font-medium">
                Country
              </label>
              <Input id="c-scountry" maxLength={2} placeholder="GB" error={errors.shipping_country?.message} {...register('shipping_country')} />
            </div>
            </div>
          </fieldset>


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
