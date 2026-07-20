import { Suspense, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import {
  ArrowLeft,
  Building2,
  LayoutDashboard,
  Menu,
  Moon,
  ScrollText,
  Shield,
  Sun,
  X,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { useThemeStore } from '@/store/theme'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'

const navigation = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/admin/organizations', label: 'Organizations', icon: Building2, end: false },
  { to: '/admin/activity', label: 'Audit Log', icon: ScrollText, end: false },
]

/**
 * The Super Admin shell. Distinct from AppLayout because the admin area is
 * platform-wide, not org-scoped — there is no org switcher, and a red accent
 * makes it visually obvious you are in the cross-organization control panel.
 */
export function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const { theme, toggle } = useThemeStore()
  const user = useAuthStore((s) => s.user)

  return (
    <div className="min-h-svh bg-background">
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={() => setSidebarOpen(false)}
          aria-hidden
        />
      )}

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r bg-card transition-transform lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex h-16 items-center justify-between border-b px-4">
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-md bg-destructive text-destructive-foreground">
              <Shield size={18} />
            </div>
            <span className="font-semibold">Platform Admin</span>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setSidebarOpen(false)}
            aria-label="Close menu"
          >
            <X size={18} />
          </Button>
        </div>

        <nav className="flex-1 space-y-1 p-3">
          {navigation.map(({ to, label, icon: Icon, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              onClick={() => setSidebarOpen(false)}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-destructive text-destructive-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )
              }
            >
              <Icon size={18} />
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="border-t p-3">
          <NavLink
            to="/dashboard"
            className="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <ArrowLeft size={18} />
            Back to app
          </NavLink>
          <div className="mt-2 px-3 py-2">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          </div>
        </div>
      </aside>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-background/95 px-4 backdrop-blur">
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
          >
            <Menu size={18} />
          </Button>
          <div className="ml-auto">
            <Button variant="ghost" size="icon" onClick={toggle} aria-label="Toggle theme">
              {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
            </Button>
          </div>
        </header>

        <main className="p-4 lg:p-8">
          <Suspense
            fallback={
              <div className="flex min-h-[50vh] items-center justify-center">
                <Spinner className="h-6 w-6" />
              </div>
            }
          >
            <Outlet />
          </Suspense>
        </main>
      </div>
    </div>
  )
}
