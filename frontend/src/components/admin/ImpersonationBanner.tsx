import { UserCog } from 'lucide-react'
import { useAuthStore } from '@/store/auth'
import { useStopImpersonation } from '@/hooks/useAdmin'
import { Button } from '@/components/ui/Button'

/**
 * A persistent, hard-to-miss bar shown whenever the current session is an
 * impersonation. Rendered in the app shell (not the admin shell), because
 * impersonation drops the admin INTO the normal app as the target user — the
 * one place they might forget they are not themselves.
 *
 * The state is server-authoritative: it comes from the `impersonation` block on
 * /auth/me, set into the store by useMe, so it cannot show a stale banner after
 * the session actually ended.
 */
export function ImpersonationBanner() {
  const impersonation = useAuthStore((s) => s.impersonation)
  const stop = useStopImpersonation()

  if (!impersonation?.active) return null

  const by = impersonation.impersonator?.email

  return (
    <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950">
      <span className="inline-flex items-center gap-2">
        <UserCog size={16} aria-hidden />
        You are impersonating this organization{by ? ` (started by ${by})` : ''}.
      </span>
      <Button
        size="sm"
        variant="ghost"
        className="h-7 bg-amber-950/10 text-amber-950 hover:bg-amber-950/20"
        loading={stop.isPending}
        onClick={() => stop.mutate()}
      >
        Stop impersonating
      </Button>
    </div>
  )
}
