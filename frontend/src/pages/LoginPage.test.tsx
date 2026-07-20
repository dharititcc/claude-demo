import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import LoginPage from './LoginPage'
import { authService } from '@/services/auth'

vi.mock('@/services/auth', () => ({
  authService: { login: vi.fn(), completeTwoFactorChallenge: vi.fn() },
}))

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false }, queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the sign-in form', () => {
    renderPage()

    expect(screen.getByRole('heading', { name: /welcome back/i })).toBeInTheDocument()
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument()
  })

  it('validates before calling the API', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText(/email is required/i)).toBeInTheDocument()
    // A blocked submit must not reach the network.
    expect(authService.login).not.toHaveBeenCalled()
  })

  it('rejects a malformed email client-side', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(screen.getByLabelText(/email/i), 'not-an-email')
    await user.type(screen.getByLabelText(/password/i), 'whatever')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText(/valid email address/i)).toBeInTheDocument()
    expect(authService.login).not.toHaveBeenCalled()
  })

  it('submits valid credentials', async () => {
    const user = userEvent.setup()
    vi.mocked(authService.login).mockResolvedValue({
      status: 'authenticated',
      session: {
        user: { name: 'Ada' },
        organizations: [],
        token: 't',
      },
    } as never)

    renderPage()

    await user.type(screen.getByLabelText(/email/i), 'ada@example.test')
    await user.type(screen.getByLabelText(/password/i), 'Str0ng!Passw0rd#2026')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    expect(authService.login).toHaveBeenCalledWith({
      email: 'ada@example.test',
      password: 'Str0ng!Passw0rd#2026',
    })
  })

  it('asks for a second factor instead of signing in when the API challenges', async () => {
    const user = userEvent.setup()
    // A 202 means the password was right but the account owes a code. Axios
    // treats 202 as success, so the danger is sailing past it into a session
    // with an undefined token.
    vi.mocked(authService.login).mockResolvedValue({
      status: 'two_factor_required',
      challengeToken: 'challenge-abc',
    } as never)

    renderPage()

    await user.type(screen.getByLabelText(/email/i), 'ada@example.test')
    await user.type(screen.getByLabelText(/password/i), 'Str0ng!Passw0rd#2026')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    expect(
      await screen.findByRole('heading', { name: /two-factor authentication/i }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText(/authentication code/i)).toBeInTheDocument()
  })

  it('lets a user fall back to a recovery code', async () => {
    const user = userEvent.setup()
    vi.mocked(authService.login).mockResolvedValue({
      status: 'two_factor_required',
      challengeToken: 'challenge-abc',
    } as never)

    renderPage()

    await user.type(screen.getByLabelText(/email/i), 'ada@example.test')
    await user.type(screen.getByLabelText(/password/i), 'Str0ng!Passw0rd#2026')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    await user.click(await screen.findByRole('button', { name: /lost your device/i }))

    expect(screen.getByLabelText(/recovery code/i)).toBeInTheDocument()
  })

  it('sends the challenge token with the code', async () => {
    const user = userEvent.setup()
    vi.mocked(authService.login).mockResolvedValue({
      status: 'two_factor_required',
      challengeToken: 'challenge-abc',
    } as never)

    renderPage()

    await user.type(screen.getByLabelText(/email/i), 'ada@example.test')
    await user.type(screen.getByLabelText(/password/i), 'Str0ng!Passw0rd#2026')
    await user.click(screen.getByRole('button', { name: /sign in/i }))

    await user.type(await screen.findByLabelText(/authentication code/i), '123456')
    await user.click(screen.getByRole('button', { name: /verify/i }))

    expect(authService.completeTwoFactorChallenge).toHaveBeenCalledWith({
      challengeToken: 'challenge-abc',
      code: '123456',
    })
  })
})
