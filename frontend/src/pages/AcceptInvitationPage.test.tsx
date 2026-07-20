import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AcceptInvitationPage from './AcceptInvitationPage'
import { teamService } from '@/services/team'
import { useAuthStore } from '@/store/auth'
import type { InvitationPreview, User } from '@/types'

vi.mock('@/services/team', () => ({
  teamService: { previewInvitation: vi.fn(), acceptInvitation: vi.fn() },
}))
vi.mock('@/services/auth', () => ({ authService: { me: vi.fn() } }))

const preview: InvitationPreview = {
  email: 'invited@example.test',
  role: 'manager',
  organization_name: 'Acme Inc',
  invited_by: 'Alex Owner',
  expires_at: '2026-12-01T00:00:00+00:00',
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/invitations/tok123']}>
        <Routes>
          <Route path="/invitations/:token" element={<AcceptInvitationPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const user = (email: string): User => ({
  id: 1,
  name: 'Someone',
  email,
  avatar: null,
  phone: null,
  locale: 'en',
  timezone: 'UTC',
  status: 'active',
  is_super_admin: false,
  email_verified: true,
  two_factor_enabled: false,
  last_login_at: null,
  created_at: null,
})

describe('AcceptInvitationPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    useAuthStore.setState({ user: null, isAuthenticated: false })
  })

  it('shows which organization invited you before you sign in', async () => {
    vi.mocked(teamService.previewInvitation).mockResolvedValue(preview)

    renderPage()

    expect(await screen.findByRole('heading', { name: /join acme inc/i })).toBeInTheDocument()
    expect(screen.getByText('invited@example.test')).toBeInTheDocument()
    expect(screen.getByText('manager')).toBeInTheDocument()
    // Signed out: prompted to sign in rather than shown an accept button.
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /accept invitation/i })).not.toBeInTheDocument()
  })

  it('explains an invalid or expired invitation instead of failing silently', async () => {
    vi.mocked(teamService.previewInvitation).mockRejectedValue(new Error('gone'))

    renderPage()

    expect(await screen.findByText(/isn't valid/i)).toBeInTheDocument()
    expect(screen.getByText(/expired, already been used, or been revoked/i)).toBeInTheDocument()
  })

  it('warns when signed in as the wrong account', async () => {
    vi.mocked(teamService.previewInvitation).mockResolvedValue(preview)
    localStorage.setItem('saas_token', 'a-token')
    useAuthStore.setState({ user: user('someone.else@example.test'), isAuthenticated: true })

    renderPage()

    // The invitation is bound to an address, so accepting as another account
    // would fail server-side; say so up front rather than let them try.
    expect(await screen.findByText(/but this invitation is for invited@example.test/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /accept invitation/i })).not.toBeInTheDocument()
  })

  it('offers to accept when signed in as the invited address', async () => {
    vi.mocked(teamService.previewInvitation).mockResolvedValue(preview)
    localStorage.setItem('saas_token', 'a-token')
    useAuthStore.setState({ user: user('invited@example.test'), isAuthenticated: true })

    renderPage()

    expect(await screen.findByRole('button', { name: /accept invitation/i })).toBeInTheDocument()
  })
})
