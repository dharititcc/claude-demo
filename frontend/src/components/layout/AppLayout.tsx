import { Suspense, useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  Building2,
  CalendarDays,
  CreditCard,
  FolderKanban,
  FolderOpen,
  LayoutDashboard,
  ListTodo,
  LogOut,
  Menu,
  Moon,
  Settings,
  Shield,
  Sun,
  Users,
  UsersRound,
  X,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { useThemeStore } from '@/store/theme'
import { useAuthStore } from '@/store/auth'
import { useLogout, useOrgContext } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { OrgSwitcher } from './OrgSwitcher'
import { NotificationBell } from './NotificationBell'
import { ImpersonationBanner } from '@/components/admin/ImpersonationBanner'

const navigation = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/customers', label: 'Customers', icon: Users, permission: 'customers.view' },
  { to: '/projects', label: 'Projects', icon: FolderKanban, permission: 'projects.view' },
  { to: '/tasks', label: 'Tasks', icon: ListTodo, permission: 'tasks.view' },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays, permission: 'calendar.view' },
  { to: '/files', label: 'Files', icon: FolderOpen, permission: 'files.view' },
  { to: '/team', label: 'Team', icon: UsersRound, permission: 'team.view' },
  { to: '/billing', label: 'Billing', icon: CreditCard, permission: 'billing.view' },
  { to: '/settings', label: 'Settings', icon: Settings, permission: 'settings.view' },
]

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const { theme, toggle } = useThemeStore()
  const user = useAuthStore((s) => s.user)
  const can = useAuthStore((s) => s.can)
  const activeOrgSlug = useAuthStore((s) => s.activeOrgSlug)
  const location = useLocation()
  const logout = useLogout()

  // Loads role + permissions for the active organization; the store is
  // populated as a side effect so `can()` works across the app.
  useOrgContext()

  // Every page in this shell is organization-scoped. A super admin belongs to no
  // organization, so if they navigate into a tenant page (Team, Customers, …)
  // there is no context for it — and its actions (like sending an invite) would
  // fire without an X-Organization header and fail. Send any org-less session to
  // the dashboard, whose no-org state points a super admin at the control plane.
  if (!activeOrgSlug && location.pathname !== '/dashboard') {
    return <Navigate to="/dashboard" replace />
  }

  return (
    <div className="min-h-svh bg-background">
      {/* Mobile backdrop */}
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
            <div className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
              <Building2 size={18} />
            </div>
            <span className="font-semibold">SaaS Platform</span>
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

        <div className="border-b p-3">
          <OrgSwitcher />
        </div>

        <nav className="flex-1 space-y-1 p-3">
          {/*
            Hiding a link is presentation only — the API authorizes every request
            independently, and the route itself remains reachable by URL.
          */}
          {/* Tenant nav is meaningless without an active organization (a super
              admin has none), so hide it entirely in that case. */}
          {(activeOrgSlug ? navigation : [])
            .filter(({ permission }) => !permission || can(permission))
            .map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              onClick={() => setSidebarOpen(false)}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )
              }
            >
                <Icon size={18} />
                {label}
              </NavLink>
            ))}

          {/* Platform admin — only a super admin sees this door. */}
          {user?.is_super_admin && (
            <NavLink
              to="/admin"
              onClick={() => setSidebarOpen(false)}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-destructive text-destructive-foreground'
                    : 'text-destructive hover:bg-destructive/10',
                )
              }
            >
              <Shield size={18} />
              Platform Admin
            </NavLink>
          )}
        </nav>

        <div className="border-t p-3">
          <div className="mb-2 px-3 py-2">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          </div>
          <Button
            variant="ghost"
            className="w-full justify-start"
            onClick={() => logout.mutate()}
            loading={logout.isPending}
          >
            <LogOut size={18} />
            Sign out
          </Button>
        </div>
      </aside>

      <div className="lg:pl-64">
        {/* Shown only during an impersonation session; server-authoritative. */}
        <ImpersonationBanner />

        <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-background/80 px-4 backdrop-blur">
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open menu"
          >
            <Menu size={18} />
          </Button>

          <div className="ml-auto flex items-center gap-1">
            <NotificationBell />
            <Button variant="ghost" size="icon" onClick={toggle} aria-label="Toggle theme">
              {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
            </Button>
          </div>
        </header>

        <main className="p-4 lg:p-6">
          {/* Boundary for lazily-loaded route chunks. */}
          <Suspense
            fallback={
              <div className="flex h-64 items-center justify-center">
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
