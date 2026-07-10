import { Moon, Sun, Building2 } from 'lucide-react'
import { useThemeStore } from '@/store/theme'
import { cn } from '@/lib/utils'

/**
 * Placeholder application shell.
 *
 * The full router (protected routes, layouts, lazy-loaded pages) lands in
 * Phase 3. This shell verifies the stack end-to-end: Tailwind theme tokens,
 * dark-mode store, and the shared providers wired in `main.tsx`.
 */
export default function App() {
  const { theme, toggle } = useThemeStore()

  return (
    <div className="min-h-svh flex flex-col items-center justify-center gap-6 p-8 text-center">
      <button
        type="button"
        onClick={toggle}
        aria-label="Toggle theme"
        className={cn(
          'absolute top-4 right-4 inline-flex h-10 w-10 items-center justify-center',
          'rounded-md border bg-card text-foreground transition-colors hover:bg-accent',
        )}
      >
        {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
      </button>

      <div className="inline-flex h-14 w-14 items-center justify-center rounded-xl bg-primary text-primary-foreground">
        <Building2 size={28} />
      </div>

      <div>
        <h1 className="text-3xl font-semibold text-foreground">SaaS Platform</h1>
        <p className="mt-2 max-w-md text-muted-foreground">
          Multi-tenant SaaS foundation — React 19, TypeScript, TanStack Query,
          Tailwind v4, Zustand. Connected to the Laravel 12 API.
        </p>
      </div>

      <span className="rounded-full border bg-secondary px-3 py-1 text-sm text-secondary-foreground">
        Phase 1 · Foundation ready
      </span>
    </div>
  )
}
