import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Check, ChevronsUpDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/store/auth'
import { Badge } from '@/components/ui/Badge'

/**
 * Switches the organization every subsequent API call acts against.
 *
 * Changing org changes the backing database, so the React Query cache is
 * cleared — keeping it would show the previous organization's rows until each
 * query refetched.
 */
export function OrgSwitcher() {
  const [open, setOpen] = useState(false)
  const organizations = useAuthStore((s) => s.organizations)
  const activeOrgSlug = useAuthStore((s) => s.activeOrgSlug)
  const setActiveOrg = useAuthStore((s) => s.setActiveOrg)
  const queryClient = useQueryClient()

  const active = organizations.find((o) => o.slug === activeOrgSlug) ?? organizations[0]

  if (!active) return null

  function select(slug: string) {
    if (slug !== activeOrgSlug) {
      setActiveOrg(slug)
      queryClient.clear()
    }
    setOpen(false)
  }

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm hover:bg-accent"
      >
        <span className="flex min-w-0 items-center gap-2">
          <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-primary text-xs font-semibold text-primary-foreground">
            {active.name.charAt(0).toUpperCase()}
          </span>
          <span className="truncate font-medium">{active.name}</span>
        </span>
        <ChevronsUpDown size={14} className="shrink-0 text-muted-foreground" />
      </button>

      {open && (
        <>
          {/* Click-away layer */}
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />
          <ul
            role="listbox"
            className="absolute left-0 right-0 top-full z-20 mt-1 overflow-hidden rounded-md border bg-card shadow-lg"
          >
            {organizations.map((org) => (
              <li key={org.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={org.slug === activeOrgSlug}
                  onClick={() => select(org.slug)}
                  className={cn(
                    'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent',
                    org.slug === activeOrgSlug && 'bg-accent',
                  )}
                >
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate">{org.name}</span>
                    {org.on_trial && (
                      <Badge variant="warning" className="mt-0.5 w-fit">
                        Trial
                      </Badge>
                    )}
                  </span>
                  {org.slug === activeOrgSlug && <Check size={14} className="shrink-0" />}
                </button>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}
