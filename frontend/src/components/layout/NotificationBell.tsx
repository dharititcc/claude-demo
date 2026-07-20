import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Check } from 'lucide-react'
import { api } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/utils'

interface NotificationItem {
  id: string
  event: string | null
  payload: Record<string, unknown>
  read: boolean
  created_at: string | null
}

/** Turn "customer.created" into "Customer created". */
function humanize(event: string | null): string {
  if (!event) return 'Notification'
  return event
    .split('.')
    .map((p) => p.replace(/_/g, ' '))
    .join(' — ')
    .replace(/^\w/, (c) => c.toUpperCase())
}

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const queryClient = useQueryClient()

  const notifications = useQuery({
    queryKey: ['notifications', orgSlug],
    queryFn: async () => {
      const { data } = await api.get<{ data: NotificationItem[]; meta: { unread: number } }>(
        '/v1/notifications',
      )
      return data
    },
    enabled: Boolean(orgSlug),
    // Poll so new activity surfaces without a manual refresh. A modest interval
    // keeps this cheap; websockets would be the next step for instant delivery.
    refetchInterval: 60_000,
  })

  const markAllRead = useMutation({
    mutationFn: () => api.post('/v1/notifications/read-all'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications', orgSlug] }),
  })

  const unread = notifications.data?.meta.unread ?? 0
  const items = notifications.data?.data ?? []

  return (
    <div className="relative">
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen((v) => !v)}
        aria-label={`Notifications${unread > 0 ? ` (${unread} unread)` : ''}`}
        className="relative"
      >
        <Bell size={18} />
        {unread > 0 && (
          <span className="absolute right-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-medium text-destructive-foreground">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </Button>

      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
          <div className="absolute right-0 top-full z-20 mt-1 w-80 overflow-hidden rounded-md border bg-card shadow-lg">
            <div className="flex items-center justify-between border-b px-3 py-2">
              <span className="text-sm font-medium">Notifications</span>
              {unread > 0 && (
                <button
                  type="button"
                  className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                  onClick={() => markAllRead.mutate()}
                >
                  <Check size={12} /> Mark all read
                </button>
              )}
            </div>

            <div className="max-h-80 overflow-y-auto">
              {items.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">You're all caught up.</p>
              ) : (
                <ul className="divide-y">
                  {items.map((n) => (
                    <li
                      key={n.id}
                      className={cn('px-3 py-2.5 text-sm', !n.read && 'bg-primary/5')}
                    >
                      <p className="font-medium">{humanize(n.event)}</p>
                      {typeof n.payload.name === 'string' && (
                        <p className="text-xs text-muted-foreground">{n.payload.name}</p>
                      )}
                      {n.created_at && (
                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                          {new Date(n.created_at).toLocaleString()}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
