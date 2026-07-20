import { beforeEach, describe, expect, it } from 'vitest'
import { useAuthStore } from './auth'
import { getActiveOrg } from '@/services/api'
import type { Organization, User } from '@/types'

const user = (overrides: Partial<User> = {}): User => ({
  id: 1,
  name: 'Ada Lovelace',
  email: 'ada@example.test',
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
  ...overrides,
})

const org = (slug: string): Organization => ({
  id: `id-${slug}`,
  name: slug,
  slug,
  logo: null,
  timezone: 'UTC',
  currency: 'USD',
  language: 'en',
  status: 'active',
  on_trial: false,
  trial_ends_at: null,
  created_at: null,
})

describe('auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    useAuthStore.setState({
      user: null,
      organizations: [],
      activeOrgSlug: null,
      permissions: [],
      isAuthenticated: false,
    })
  })

  it('defaults the active organization to the first one', () => {
    useAuthStore.getState().setSession(user(), [org('acme'), org('globex')])

    expect(useAuthStore.getState().activeOrgSlug).toBe('acme')
    expect(useAuthStore.getState().isAuthenticated).toBe(true)
  })

  it('persists the active org where the API client can read it', () => {
    useAuthStore.getState().setSession(user(), [org('acme')])

    // The request interceptor reads this to set X-Organization.
    expect(getActiveOrg()).toBe('acme')
  })

  it('drops permissions when switching organization', () => {
    useAuthStore.getState().setSession(user(), [org('acme'), org('globex')])
    useAuthStore.getState().setPermissions(['customers.delete'])

    useAuthStore.getState().setActiveOrg('globex')

    // Permissions are org-specific; keeping them would briefly show controls
    // the user may not have in the new organization.
    expect(useAuthStore.getState().permissions).toEqual([])
    expect(getActiveOrg()).toBe('globex')
  })

  it('grants a permission only when it was returned for the active org', () => {
    useAuthStore.getState().setSession(user(), [org('acme')])
    useAuthStore.getState().setPermissions(['customers.view'])

    expect(useAuthStore.getState().can('customers.view')).toBe(true)
    expect(useAuthStore.getState().can('customers.delete')).toBe(false)
  })

  it('lets a super admin do anything', () => {
    useAuthStore.getState().setSession(user({ is_super_admin: true }), [org('acme')])

    expect(useAuthStore.getState().can('customers.delete')).toBe(true)
  })

  it('clears the session and the stored org on sign out', () => {
    useAuthStore.getState().setSession(user(), [org('acme')])
    useAuthStore.getState().clear()

    const state = useAuthStore.getState()
    expect(state.user).toBeNull()
    expect(state.isAuthenticated).toBe(false)
    expect(state.activeOrgSlug).toBeNull()
    expect(getActiveOrg()).toBeNull()
  })
})
