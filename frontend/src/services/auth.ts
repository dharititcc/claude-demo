import { api, setToken } from './api'
import type { ImpersonationState } from '@/types/admin'
import type {
  LoginCredentials,
  LoginResponse,
  LoginResult,
  OrganizationContext,
  Organization,
  RegisterPayload,
  RegisterResponse,
  Session,
  TwoFactorChallengePayload,
  TwoFactorChallengeResponse,
  TwoFactorEnrolment,
  TwoFactorStatus,
  User,
} from '@/types'

/**
 * Auth endpoints. Every call goes through the shared Axios client, so the
 * bearer token and error normalisation are applied consistently.
 */
export const authService = {
  async register(payload: RegisterPayload): Promise<RegisterResponse['data']> {
    const { data } = await api.post<RegisterResponse>('/v1/auth/register', payload)
    setToken(data.data.token)
    return data.data
  },

  /**
   * Sign in with a password.
   *
   * Returns a discriminated result rather than a session, because a password is
   * not always enough: an account with 2FA answers 202 and owes a code. Axios
   * treats 202 as success, so that case has to be detected explicitly — reading
   * `data.token` off it would store `undefined` and leave the app looking signed
   * in while every request 401s.
   */
  async login(credentials: LoginCredentials): Promise<LoginResult> {
    const { data, status } = await api.post<LoginResponse | TwoFactorChallengeResponse>(
      '/v1/auth/login',
      { device_name: navigator.userAgent.slice(0, 255), ...credentials },
    )

    if (status === 202) {
      return {
        status: 'two_factor_required',
        challengeToken: (data as TwoFactorChallengeResponse).data.challenge_token,
      }
    }

    const session = (data as LoginResponse).data
    setToken(session.token)

    return { status: 'authenticated', session }
  },

  /**
   * Exchange a login challenge plus a code for a real token.
   *
   * Send exactly one of `code` (from the authenticator) or `recoveryCode`.
   */
  async completeTwoFactorChallenge(payload: TwoFactorChallengePayload): Promise<LoginResponse['data']> {
    const { data } = await api.post<LoginResponse>('/v1/auth/2fa/challenge', {
      challenge_token: payload.challengeToken,
      code: payload.code,
      recovery_code: payload.recoveryCode,
      device_name: navigator.userAgent.slice(0, 255),
    })
    setToken(data.data.token)
    return data.data
  },

  // ─── Two-factor ───

  async twoFactorStatus(): Promise<TwoFactorStatus> {
    const { data } = await api.get<{ data: TwoFactorStatus }>('/v1/auth/2fa')
    return data.data
  },

  /**
   * Start enrolment. This does NOT protect the account yet — the secret is
   * pending until confirmTwoFactor() proves the authenticator works.
   */
  async enableTwoFactor(): Promise<TwoFactorEnrolment> {
    const { data } = await api.post<{ data: TwoFactorEnrolment }>('/v1/auth/2fa/enable')
    return data.data
  },

  /** Finish enrolment. The returned recovery codes are shown in full only here. */
  async confirmTwoFactor(code: string): Promise<string[]> {
    const { data } = await api.post<{ data: { recovery_codes: string[] } }>(
      '/v1/auth/2fa/confirm',
      { code: code.replace(/\s+/g, '') },
    )
    return data.data.recovery_codes
  },

  async disableTwoFactor(password: string): Promise<void> {
    // Axios sends no body on DELETE unless it is given one explicitly.
    await api.delete('/v1/auth/2fa', { data: { password } })
  },

  async recoveryCodes(): Promise<string[]> {
    const { data } = await api.get<{ data: { recovery_codes: string[] } }>(
      '/v1/auth/2fa/recovery-codes',
    )
    return data.data.recovery_codes
  },

  async regenerateRecoveryCodes(password: string): Promise<string[]> {
    const { data } = await api.post<{ data: { recovery_codes: string[] } }>(
      '/v1/auth/2fa/recovery-codes',
      { password },
    )
    return data.data.recovery_codes
  },

  async logout(): Promise<void> {
    try {
      await api.post('/v1/auth/logout')
    } finally {
      // Clear the token even if the request fails — the user asked to leave.
      setToken(null)
    }
  },

  async me(): Promise<{ user: User; impersonation: ImpersonationState | null }> {
    const { data } = await api.get<{ data: User; impersonation: ImpersonationState | null }>(
      '/v1/auth/me',
    )
    return { user: data.data, impersonation: data.impersonation ?? null }
  },

  async organizations(): Promise<Organization[]> {
    const { data } = await api.get<{ data: Organization[] }>('/v1/organizations')
    return data.data
  },

  /** Role + effective permissions within the active organization. */
  async context(): Promise<OrganizationContext> {
    const { data } = await api.get<{ data: OrganizationContext }>('/v1/context')
    return data.data
  },

  async sessions(): Promise<Session[]> {
    const { data } = await api.get<{ data: Session[] }>('/v1/auth/sessions')
    return data.data
  },

  async revokeSession(id: number): Promise<void> {
    await api.delete(`/v1/auth/sessions/${id}`)
  },

  async changePassword(payload: {
    current_password: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await api.put('/v1/auth/password', payload)
  },

  async forgotPassword(email: string): Promise<string> {
    const { data } = await api.post<{ message: string }>('/v1/auth/forgot-password', { email })
    return data.message
  },

  async resetPassword(payload: {
    token: string
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await api.post('/v1/auth/reset-password', payload)
  },
}
