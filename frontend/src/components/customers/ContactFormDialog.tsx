import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Toggle } from '@/components/ui/Toggle'
import { useCreateContact, useUpdateContact } from '@/hooks/useCustomerContacts'
import type { CustomerContact } from '@/types'

const schema = z.object({
  first_name: z.string().min(1, 'First name is required.').max(255),
  last_name: z.string().max(255),
  email: z.string().email('Enter a valid email.').or(z.literal('')),
  phone: z.string().max(50),
  mobile: z.string().max(50),
  department: z.string().max(100),
  job_title: z.string().max(100),
  notes: z.string().max(5000),
  is_primary: z.boolean(),
  is_active: z.boolean(),
})

type FormValues = z.infer<typeof schema>

const empty: FormValues = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  mobile: '',
  department: '',
  job_title: '',
  notes: '',
  is_primary: false,
  is_active: true,
}

/**
 * Create/edit dialog for a contact. One component serves both so the field list
 * and validation cannot drift between them.
 */
export function ContactFormDialog({
  open,
  customerId,
  contact,
  onClose,
}: {
  open: boolean
  customerId: number
  contact: CustomerContact | null
  onClose: () => void
}) {
  const create = useCreateContact(customerId)
  const update = useUpdateContact(customerId)

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty })

  // Repopulate whenever the target changes — the dialog is reused, so values
  // from a previously edited contact would otherwise persist.
  useEffect(() => {
    if (!open) return

    reset(
      contact
        ? {
            first_name: contact.first_name,
            last_name: contact.last_name ?? '',
            email: contact.email ?? '',
            phone: contact.phone ?? '',
            mobile: contact.mobile ?? '',
            department: contact.department ?? '',
            job_title: contact.job_title ?? '',
            notes: contact.notes ?? '',
            is_primary: contact.is_primary,
            is_active: contact.status === 'active',
          }
        : empty,
    )
  }, [open, contact, reset])

  // Close on Escape.
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  const pending = create.isPending || update.isPending
  const isPrimary = watch('is_primary')
  const isActive = watch('is_active')

  function onSubmit(values: FormValues) {
    const payload = {
      first_name: values.first_name,
      // Send null rather than "" so the API stores an absent value, not a blank.
      last_name: values.last_name || null,
      email: values.email || null,
      phone: values.phone || null,
      mobile: values.mobile || null,
      department: values.department || null,
      job_title: values.job_title || null,
      notes: values.notes || null,
      status: values.is_active ? ('active' as const) : ('inactive' as const),
      is_primary: values.is_primary,
    }

    const mutation = contact
      ? update.mutateAsync({ id: contact.id, payload })
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
        aria-labelledby="contact-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-lg overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 id="contact-dialog-title" className="text-lg font-semibold">
            {contact ? `Edit ${contact.full_name}` : 'New contact'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-first" className="mb-1.5 block text-sm font-medium">
                First name
              </label>
              <Input id="c-first" error={errors.first_name?.message} {...register('first_name')} />
            </div>
            <div>
              <label htmlFor="c-last" className="mb-1.5 block text-sm font-medium">
                Last name
              </label>
              <Input id="c-last" error={errors.last_name?.message} {...register('last_name')} />
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-title" className="mb-1.5 block text-sm font-medium">
                Job title
              </label>
              <Input id="c-title" error={errors.job_title?.message} {...register('job_title')} />
            </div>
            <div>
              <label htmlFor="c-dept" className="mb-1.5 block text-sm font-medium">
                Department
              </label>
              <Input id="c-dept" error={errors.department?.message} {...register('department')} />
            </div>
          </div>

          <div>
            <label htmlFor="c-email" className="mb-1.5 block text-sm font-medium">
              Email
            </label>
            <Input id="c-email" type="email" error={errors.email?.message} {...register('email')} />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="c-phone" className="mb-1.5 block text-sm font-medium">
                Phone
              </label>
              <Input id="c-phone" error={errors.phone?.message} {...register('phone')} />
            </div>
            <div>
              <label htmlFor="c-mobile" className="mb-1.5 block text-sm font-medium">
                Mobile
              </label>
              <Input id="c-mobile" error={errors.mobile?.message} {...register('mobile')} />
            </div>
          </div>

          <div>
            <label htmlFor="c-notes" className="mb-1.5 block text-sm font-medium">
              Notes
            </label>
            <textarea
              id="c-notes"
              rows={3}
              className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              {...register('notes')}
            />
          </div>

          <Toggle
            id="c-primary"
            label="Primary contact"
            description="The main person to deal with. Promoting this contact demotes whoever holds it now."
            checked={isPrimary}
            onChange={(e) => setValue('is_primary', e.target.checked, { shouldDirty: true })}
          />

          <Toggle
            id="c-active"
            label="Active"
            description="Inactive contacts stay on record but are filtered out of the working list."
            checked={isActive}
            onChange={(e) => setValue('is_active', e.target.checked, { shouldDirty: true })}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {contact ? 'Save changes' : 'Add contact'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
