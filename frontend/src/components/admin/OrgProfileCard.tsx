import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { useUpdateOrganization } from '@/hooks/useAdmin'
import { formatDate } from '@/lib/adminFormat'
import type { AdminOrganization } from '@/types/admin'

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-sm">{value || <span className="text-muted-foreground">—</span>}</dd>
    </div>
  )
}

/**
 * The organization's profile, in view or edit mode.
 *
 * The slug is deliberately not editable here — it is the tenant identifier
 * clients send in X-Organization, and the API rejects changes to it anyway.
 */
export function OrgProfileCard({ org }: { org: AdminOrganization }) {
  const [editing, setEditing] = useState(false)

  return editing ? (
    <EditForm org={org} onDone={() => setEditing(false)} />
  ) : (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle>Profile</CardTitle>
        {!org.deleted_at && (
          <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
            <Pencil size={14} /> Edit
          </Button>
        )}
      </CardHeader>
      <CardContent>
        <dl className="grid gap-4 sm:grid-cols-2">
          <Field label="Owner" value={org.owner?.name} />
          <Field label="Owner email" value={org.owner?.email} />
          <Field label="Phone" value={org.phone} />
          <Field label="Timezone" value={org.timezone} />
          <Field label="Currency" value={org.currency} />
          <Field label="Language" value={org.language} />
          <Field label="Registered" value={formatDate(org.registered_at)} />
          {org.deleted_at && <Field label="Deleted" value={formatDate(org.deleted_at)} />}
        </dl>
      </CardContent>
    </Card>
  )
}

function EditForm({ org, onDone }: { org: AdminOrganization; onDone: () => void }) {
  const update = useUpdateOrganization()
  const [form, setForm] = useState({
    name: org.name,
    phone: org.phone ?? '',
    timezone: org.timezone,
    currency: org.currency,
    language: org.language,
  })

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }))
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Edit profile</CardTitle>
      </CardHeader>
      <CardContent>
        {/*
          noValidate: without it the browser's native validation would fire
          before our own handling, showing an unstyled tooltip instead of the
          API's field errors.
        */}
        <form
          noValidate
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault()
            update.mutate(
              { id: org.id, payload: { ...form, phone: form.phone || null } },
              { onSuccess: onDone },
            )
          }}
        >
          <label className="text-sm">
            <span className="mb-1 block text-muted-foreground">Name</span>
            <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-muted-foreground">Phone</span>
            <Input value={form.phone} onChange={(e) => set('phone', e.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-muted-foreground">Timezone</span>
            <Input value={form.timezone} onChange={(e) => set('timezone', e.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-muted-foreground">Currency</span>
            <Input value={form.currency} onChange={(e) => set('currency', e.target.value)} maxLength={3} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-muted-foreground">Language</span>
            <Input value={form.language} onChange={(e) => set('language', e.target.value)} maxLength={5} />
          </label>

          <div className="flex items-end gap-2 sm:col-span-2">
            <Button type="submit" loading={update.isPending}>
              Save changes
            </Button>
            <Button type="button" variant="ghost" onClick={onDone} disabled={update.isPending}>
              Cancel
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
