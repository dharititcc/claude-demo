import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { useUpdateLimits } from '@/hooks/useAdmin'
import type { AdminLimits, LimitOverrides } from '@/types/admin'

const KEYS: Array<{ key: string; label: string }> = [
  { key: 'users', label: 'Users' },
  { key: 'customers', label: 'Customers' },
  { key: 'storage_mb', label: 'Storage (MB)' },
]

function limitText(value: number | null): string {
  return value === null ? 'Unlimited' : value.toLocaleString()
}

/**
 * Usage against limits, with per-org overrides. Shows the effective ceiling and,
 * when an override is in force, the plan default it replaced. Edit mode lets an
 * admin raise, lower, set unlimited, or clear the override for each limit.
 */
export function LimitsCard({ orgId, limits }: { orgId: string; limits: AdminLimits }) {
  const [editing, setEditing] = useState(false)

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle>Limits & overrides</CardTitle>
        {!editing && (
          <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
            <Pencil size={14} /> Edit
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {editing ? (
          <LimitsEditor orgId={orgId} limits={limits} onDone={() => setEditing(false)} />
        ) : (
          <dl className="space-y-3">
            {KEYS.map(({ key, label }) => {
              const l = limits[key]
              if (!l) return null
              return (
                <div key={key} className="flex items-center justify-between gap-4">
                  <dt className="text-sm">{label}</dt>
                  <dd className="flex items-center gap-2 text-sm tabular-nums">
                    <span className={l.exceeded ? 'text-destructive' : undefined}>
                      {l.used.toLocaleString()} / {limitText(l.effective_limit)}
                    </span>
                    {l.has_override && (
                      <Badge variant="default" title={`Plan default: ${limitText(l.plan_limit)}`}>
                        override
                      </Badge>
                    )}
                  </dd>
                </div>
              )
            })}
          </dl>
        )}
      </CardContent>
    </Card>
  )
}

/**
 * The three-way override control per limit: a "plan" toggle (no override), an
 * "unlimited" toggle, or a number. This maps cleanly onto the API contract where
 * a key is absent (plan), null (unlimited), or an integer.
 */
function LimitsEditor({
  orgId,
  limits,
  onDone,
}: {
  orgId: string
  limits: AdminLimits
  onDone: () => void
}) {
  const update = useUpdateLimits()

  // Per key: mode drives whether we send nothing, null, or a number.
  const [state, setState] = useState<Record<string, { mode: 'plan' | 'unlimited' | 'number'; value: string }>>(
    () =>
      Object.fromEntries(
        KEYS.map(({ key }) => {
          const l = limits[key]
          if (!l?.has_override) return [key, { mode: 'plan' as const, value: '' }]
          if (l.override === null) return [key, { mode: 'unlimited' as const, value: '' }]
          return [key, { mode: 'number' as const, value: String(l.override) }]
        }),
      ),
  )

  function buildOverrides(): LimitOverrides {
    const out: LimitOverrides = {}
    for (const { key } of KEYS) {
      const s = state[key]
      if (s.mode === 'plan') continue // absent = fall back to plan
      if (s.mode === 'unlimited') out[key] = null
      else if (s.value !== '') out[key] = Number(s.value)
    }
    return out
  }

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        update.mutate({ id: orgId, overrides: buildOverrides() }, { onSuccess: onDone })
      }}
    >
      {KEYS.map(({ key, label }) => {
        const s = state[key]
        return (
          <div key={key} className="grid grid-cols-[1fr_auto] items-center gap-3">
            <span className="text-sm">
              {label}
              <span className="ml-2 text-xs text-muted-foreground">
                plan: {limitText(limits[key]?.plan_limit ?? null)}
              </span>
            </span>
            <div className="flex items-center gap-2">
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={s.mode}
                onChange={(e) =>
                  setState((st) => ({ ...st, [key]: { ...st[key], mode: e.target.value as typeof s.mode } }))
                }
                aria-label={`${label} override mode`}
              >
                <option value="plan">Use plan</option>
                <option value="number">Set to…</option>
                <option value="unlimited">Unlimited</option>
              </select>
              {s.mode === 'number' && (
                <Input
                  type="number"
                  min={0}
                  className="h-9 w-24"
                  value={s.value}
                  onChange={(e) =>
                    setState((st) => ({ ...st, [key]: { ...st[key], value: e.target.value } }))
                  }
                  aria-label={`${label} override value`}
                />
              )}
            </div>
          </div>
        )
      })}

      <div className="flex gap-2">
        <Button type="submit" loading={update.isPending}>
          Save limits
        </Button>
        <Button type="button" variant="ghost" onClick={onDone} disabled={update.isPending}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
