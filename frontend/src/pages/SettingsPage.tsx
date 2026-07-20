import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import toast from 'react-hot-toast'
import { Building2, Upload } from 'lucide-react'
import { organizationService } from '@/services/team'
import { useAuthStore } from '@/store/auth'
import { useOrgContext, apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card'
import TwoFactorSettings from '@/components/auth/TwoFactorSettings'

const schema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  timezone: z.string().min(1, 'Timezone is required.'),
  currency: z.string().length(3, 'Use a 3-letter code, e.g. USD.'),
  language: z.string().min(2).max(5),
})

type FormValues = z.infer<typeof schema>

// A short list keeps the control usable; the API validates against PHP's full
// timezone database, so any valid identifier is accepted.
const TIMEZONES = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Madrid',
  'Asia/Kolkata',
  'Asia/Singapore',
  'Asia/Tokyo',
  'Australia/Sydney',
]

const CURRENCIES = ['USD', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'JPY', 'SGD']

export default function SettingsPage() {
  const [logo, setLogo] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()

  const { data: context, isLoading } = useOrgContext()
  const can = useAuthStore((s) => s.can)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const organizations = useAuthStore((s) => s.organizations)

  const readOnly = !can('settings.update')

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isDirty },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  // Populate once the context arrives, and whenever the active org changes.
  useEffect(() => {
    if (!context) return
    reset({
      name: context.organization.name,
      timezone: context.organization.timezone,
      currency: context.organization.currency,
      language: context.organization.language,
    })
  }, [context, reset])

  // Revoke the object URL so repeated picks don't leak blobs.
  useEffect(() => {
    if (!logo) return setPreview(null)
    const url = URL.createObjectURL(logo)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [logo])

  const save = useMutation({
    mutationFn: (values: FormValues) => organizationService.update({ ...values, logo }),
    onSuccess: (organization) => {
      toast.success('Settings saved.')
      setLogo(null)
      // The org appears in the switcher and context; refresh both.
      useAuthStore.setState({
        organizations: organizations.map((o) => (o.slug === organization.slug ? organization : o)),
      })
      queryClient.invalidateQueries({ queryKey: ['context', orgSlug] })
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not save settings.')),
  })

  if (isLoading || !context) {
    return <div className="h-64" />
  }

  const org = context.organization

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Settings</h1>
        <p className="text-sm text-muted-foreground">Configure this organization.</p>
      </div>

      {readOnly && (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">
            You have read-only access to these settings.
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Organization</CardTitle>
          <CardDescription>
            The identifier <code className="rounded bg-muted px-1">{org.slug}</code> cannot be
            changed — it is how the API identifies this organization.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit((v) => save.mutate(v))} className="space-y-5" noValidate>
            <div className="flex items-center gap-4">
              <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                {preview || org.logo ? (
                  <img
                    src={preview ?? `/storage/${org.logo}`}
                    alt=""
                    className="h-full w-full object-cover"
                  />
                ) : (
                  <Building2 size={24} className="text-muted-foreground" />
                )}
              </div>
              <div>
                <input
                  ref={fileInput}
                  type="file"
                  accept="image/*"
                  className="hidden"
                  onChange={(e) => setLogo(e.target.files?.[0] ?? null)}
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={readOnly}
                  onClick={() => fileInput.current?.click()}
                >
                  <Upload size={14} />
                  {logo ? 'Change' : 'Upload logo'}
                </Button>
                <p className="mt-1 text-xs text-muted-foreground">PNG or JPG, up to 2 MB.</p>
              </div>
            </div>

            <div>
              <label htmlFor="org-name" className="mb-1.5 block text-sm font-medium">
                Name
              </label>
              <Input id="org-name" disabled={readOnly} error={errors.name?.message} {...register('name')} />
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <div>
                <label htmlFor="org-tz" className="mb-1.5 block text-sm font-medium">
                  Timezone
                </label>
                <select
                  id="org-tz"
                  disabled={readOnly}
                  className="h-10 w-full rounded-md border bg-background px-3 text-sm disabled:opacity-50"
                  {...register('timezone')}
                >
                  {TIMEZONES.map((tz) => (
                    <option key={tz} value={tz}>
                      {tz}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label htmlFor="org-currency" className="mb-1.5 block text-sm font-medium">
                  Currency
                </label>
                <select
                  id="org-currency"
                  disabled={readOnly}
                  className="h-10 w-full rounded-md border bg-background px-3 text-sm disabled:opacity-50"
                  {...register('currency')}
                >
                  {CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label htmlFor="org-lang" className="mb-1.5 block text-sm font-medium">
                  Language
                </label>
                <select
                  id="org-lang"
                  disabled={readOnly}
                  className="h-10 w-full rounded-md border bg-background px-3 text-sm disabled:opacity-50"
                  {...register('language')}
                >
                  {['en', 'es', 'fr', 'de', 'pt'].map((l) => (
                    <option key={l} value={l}>
                      {l}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {!readOnly && (
              <div className="flex justify-end">
                <Button type="submit" loading={save.isPending} disabled={!isDirty && !logo}>
                  Save changes
                </Button>
              </div>
            )}
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Subscription</CardTitle>
        </CardHeader>
        <CardContent className="flex items-center justify-between">
          <div>
            <p className="text-sm">
              Status <Badge variant={org.on_trial ? 'warning' : 'success'}>{org.status}</Badge>
            </p>
            {org.trial_ends_at && (
              <p className="mt-1 text-xs text-muted-foreground">
                Trial ends {new Date(org.trial_ends_at).toLocaleDateString()}
              </p>
            )}
          </div>
          <Link to="/billing">
            <Button variant="outline" size="sm">
              Manage billing
            </Button>
          </Link>
        </CardContent>
      </Card>

      {/* Account-level, not organization-level: a user's second factor follows
          them across every organization they belong to. */}
      <TwoFactorSettings />
    </div>
  )
}
