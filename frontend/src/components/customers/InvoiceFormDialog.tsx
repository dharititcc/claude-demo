import { useEffect } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Plus, Trash2, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useCreateInvoice, useUpdateInvoice } from '@/hooks/useInvoices'
import type { Invoice } from '@/types'

/**
 * Number inputs hand back strings, so amounts are validated as strings and
 * converted at the submit boundary (z.coerce.number() would type the field as
 * `unknown` and break react-hook-form's inference).
 */
const money = (message: string) =>
  z.string().refine((v) => v !== '' && /^\d+(\.\d{1,2})?$/.test(v), message)

const schema = z.object({
  issue_date: z.string().min(1, 'Issue date is required.'),
  due_date: z.string().min(1, 'Due date is required.'),
  notes: z.string().max(5000),
  terms: z.string().max(5000),
  items: z
    .array(
      z.object({
        description: z.string().min(1, 'Describe the line.').max(500),
        quantity: money('Enter a quantity.'),
        unit_price: money('Enter a price.'),
        tax_rate: z
          .string()
          .refine((v) => v === '' || /^\d+(\.\d{1,2})?$/.test(v), 'Enter a percentage.'),
      }),
    )
    .min(1, 'An invoice needs at least one line.'),
})

type FormValues = z.infer<typeof schema>

const emptyLine = { description: '', quantity: '1', unit_price: '', tax_rate: '' }

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function inDays(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * Raise or amend an invoice.
 *
 * The totals shown here are a preview only — the server recomputes them in
 * integer minor units and its figures are authoritative. Displaying a running
 * total the user cannot submit is the point: it stops them discovering the
 * amount only after saving.
 */
export function InvoiceFormDialog({
  open,
  customerId,
  invoice,
  currency,
  onClose,
}: {
  open: boolean
  customerId: number
  invoice: Invoice | null
  currency: string
  onClose: () => void
}) {
  const create = useCreateInvoice(customerId)
  const update = useUpdateInvoice()

  const {
    register,
    control,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { issue_date: today(), due_date: inDays(30), notes: '', terms: '', items: [emptyLine] },
  })

  const { fields, append, remove } = useFieldArray({ control, name: 'items' })

  useEffect(() => {
    if (!open) return

    reset(
      invoice
        ? {
            issue_date: invoice.issue_date,
            due_date: invoice.due_date,
            notes: invoice.notes ?? '',
            terms: invoice.terms ?? '',
            items: (invoice.items ?? []).map((i) => ({
              description: i.description,
              quantity: String(i.quantity),
              unit_price: String(i.unit_price),
              tax_rate: i.tax_rate ? String(i.tax_rate) : '',
            })),
          }
        : { issue_date: today(), due_date: inDays(30), notes: '', terms: '', items: [emptyLine] },
    )
  }, [open, invoice, reset])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const lines = watch('items')

  if (!open) return null

  const pending = create.isPending || update.isPending

  // Preview only — mirrors the server's per-line rounding so the figure shown
  // matches the one stored.
  const preview = (lines ?? []).reduce(
    (acc, line) => {
      const net = Math.round(Number(line.quantity || 0) * Number(line.unit_price || 0) * 100)
      const tax = Math.round((net * Number(line.tax_rate || 0)) / 100)
      return { net: acc.net + net, tax: acc.tax + tax }
    },
    { net: 0, tax: 0 },
  )

  const asMoney = (minor: number) =>
    (minor / 100).toLocaleString(undefined, { style: 'currency', currency: currency || 'USD' })

  function onSubmit(values: FormValues) {
    const payload = {
      issue_date: values.issue_date,
      due_date: values.due_date,
      notes: values.notes || null,
      terms: values.terms || null,
      items: values.items.map((i) => ({
        description: i.description,
        quantity: Number(i.quantity),
        unit_price: Number(i.unit_price),
        tax_rate: i.tax_rate === '' ? 0 : Number(i.tax_rate),
      })),
    }

    const mutation = invoice
      ? update.mutateAsync({ id: invoice.id, payload })
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
        aria-labelledby="invoice-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-3xl overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 id="invoice-dialog-title" className="text-lg font-semibold">
            {invoice ? `Edit ${invoice.number}` : 'New invoice'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        {/* noValidate: our Zod errors are authoritative, not the browser's. */}
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="i-issue" className="mb-1.5 block text-sm font-medium">
                Issue date
              </label>
              <Input id="i-issue" type="date" error={errors.issue_date?.message} {...register('issue_date')} />
            </div>
            <div>
              <label htmlFor="i-due" className="mb-1.5 block text-sm font-medium">
                Due date
              </label>
              <Input id="i-due" type="date" error={errors.due_date?.message} {...register('due_date')} />
            </div>
          </div>

          <fieldset className="rounded-md border p-4">
            <legend className="px-1 text-sm font-medium">Lines</legend>

            <div className="space-y-3">
              {fields.map((field, index) => (
                <div key={field.id} className="grid gap-2 sm:grid-cols-12">
                  <div className="sm:col-span-5">
                    <Input
                      placeholder="Description"
                      aria-label={`Line ${index + 1} description`}
                      error={errors.items?.[index]?.description?.message}
                      {...register(`items.${index}.description`)}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder="Qty"
                      aria-label={`Line ${index + 1} quantity`}
                      error={errors.items?.[index]?.quantity?.message}
                      {...register(`items.${index}.quantity`)}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder="Price"
                      aria-label={`Line ${index + 1} unit price`}
                      error={errors.items?.[index]?.unit_price?.message}
                      {...register(`items.${index}.unit_price`)}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      placeholder="Tax %"
                      aria-label={`Line ${index + 1} tax rate`}
                      error={errors.items?.[index]?.tax_rate?.message}
                      {...register(`items.${index}.tax_rate`)}
                    />
                  </div>
                  <div className="flex items-start sm:col-span-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={`Remove line ${index + 1}`}
                      // An invoice must keep at least one line.
                      disabled={fields.length === 1}
                      onClick={() => remove(index)}
                    >
                      <Trash2 size={15} />
                    </Button>
                  </div>
                </div>
              ))}
            </div>

            {errors.items?.root && (
              <p className="mt-2 text-sm text-destructive">{errors.items.root.message}</p>
            )}

            <Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => append(emptyLine)}>
              <Plus size={14} className="mr-1" /> Add line
            </Button>
          </fieldset>

          <div className="flex justify-end">
            <dl className="w-56 space-y-1 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Subtotal</dt>
                <dd className="tabular-nums">{asMoney(preview.net)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Tax</dt>
                <dd className="tabular-nums">{asMoney(preview.tax)}</dd>
              </div>
              <div className="flex justify-between border-t pt-1 font-semibold">
                <dt>Total</dt>
                <dd className="tabular-nums">{asMoney(preview.net + preview.tax)}</dd>
              </div>
            </dl>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="i-notes" className="mb-1.5 block text-sm font-medium">
                Notes
              </label>
              <textarea
                id="i-notes"
                rows={3}
                className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                {...register('notes')}
              />
            </div>
            <div>
              <label htmlFor="i-terms" className="mb-1.5 block text-sm font-medium">
                Terms
              </label>
              <textarea
                id="i-terms"
                rows={3}
                className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                {...register('terms')}
              />
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" disabled={pending}>
              {invoice ? 'Save changes' : 'Create draft'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
