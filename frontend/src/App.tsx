import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { GuestRoute, ProtectedRoute } from '@/components/ProtectedRoute'
import { AdminRoute } from '@/components/admin/AdminRoute'
import { AdminLayout } from '@/components/admin/AdminLayout'
import { AppLayout } from '@/components/layout/AppLayout'
import { Spinner } from '@/components/ui/Spinner'

// Route-level code splitting: each page ships as its own chunk, so the initial
// bundle carries only the shell and the auth screens the visitor actually needs.
const LoginPage = lazy(() => import('@/pages/LoginPage'))
const RegisterPage = lazy(() => import('@/pages/RegisterPage'))
const AcceptInvitationPage = lazy(() => import('@/pages/AcceptInvitationPage'))
const DashboardPage = lazy(() => import('@/pages/DashboardPage'))
const CustomersPage = lazy(() => import('@/pages/CustomersPage'))
const CustomerDetailPage = lazy(() => import('@/pages/CustomerDetailPage'))
const ProjectsPage = lazy(() => import('@/pages/ProjectsPage'))
const TasksPage = lazy(() => import('@/pages/TasksPage'))
const CalendarPage = lazy(() => import('@/pages/CalendarPage'))
const FilesPage = lazy(() => import('@/pages/FilesPage'))
const TeamPage = lazy(() => import('@/pages/TeamPage'))
const SettingsPage = lazy(() => import('@/pages/SettingsPage'))
const BillingPage = lazy(() => import('@/pages/BillingPage'))

// Super Admin area — its own chunk, only loaded for platform admins.
const AdminDashboardPage = lazy(() => import('@/pages/admin/AdminDashboardPage'))
const AdminOrganizationsPage = lazy(() => import('@/pages/admin/AdminOrganizationsPage'))
const AdminOrganizationDetailPage = lazy(() => import('@/pages/admin/AdminOrganizationDetailPage'))
const AdminActivityPage = lazy(() => import('@/pages/admin/AdminActivityPage'))

function PageFallback() {
  return (
    <div className="flex min-h-svh items-center justify-center">
      <Spinner className="h-6 w-6" />
    </div>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <BrowserRouter>
        <Suspense fallback={<PageFallback />}>
          <Routes>
            {/* Public */}
            <Route element={<GuestRoute />}>
              <Route path="/login" element={<LoginPage />} />
              <Route path="/register" element={<RegisterPage />} />
            </Route>

            {/*
              Invitation landing is reachable signed-in or out: the recipient
              must see who invited them before deciding to sign in or register.
              The token grants nothing by itself — accepting still requires
              being authenticated as the invited address.
            */}
            <Route path="/invitations/:token" element={<AcceptInvitationPage />} />

            {/* Authenticated */}
            <Route element={<ProtectedRoute />}>
              <Route element={<AppLayout />}>
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/customers/:id" element={<CustomerDetailPage />} />
                <Route path="/projects" element={<ProjectsPage />} />
                <Route path="/tasks" element={<TasksPage />} />
                <Route path="/calendar" element={<CalendarPage />} />
                <Route path="/files" element={<FilesPage />} />
                <Route path="/team" element={<TeamPage />} />
                <Route path="/billing" element={<BillingPage />} />
                <Route path="/settings" element={<SettingsPage />} />
              </Route>
            </Route>

            {/* Super Admin (platform-wide, central context) */}
            <Route element={<AdminRoute />}>
              <Route element={<AdminLayout />}>
                <Route path="/admin" element={<AdminDashboardPage />} />
                <Route path="/admin/organizations" element={<AdminOrganizationsPage />} />
                <Route path="/admin/organizations/:id" element={<AdminOrganizationDetailPage />} />
                <Route path="/admin/activity" element={<AdminActivityPage />} />
              </Route>
            </Route>

            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </ErrorBoundary>
  )
}
