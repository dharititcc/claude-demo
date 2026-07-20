import { beforeEach, describe, expect, it } from 'vitest'
import {
  beginImpersonation,
  endImpersonation,
  getActiveOrg,
  getToken,
  setActiveOrg,
  setToken,
} from './api'

/**
 * The impersonation token-swap is security-sensitive: get it wrong and the admin
 * either can't get back to their own session, or the impersonation token lingers
 * as the active one. These lock the parking/restore behaviour.
 */
describe('impersonation token swap', () => {
  beforeEach(() => localStorage.clear())

  it('parks the admin session and switches to the impersonation token', () => {
    setToken('admin-token')
    setActiveOrg('admin-org')

    beginImpersonation('imp-token', 'target-org')

    // The active session is now the impersonation one...
    expect(getToken()).toBe('imp-token')
    expect(getActiveOrg()).toBe('target-org')
    // ...and the admin session is parked for later.
    expect(localStorage.getItem('saas_return_token')).toBe('admin-token')
    expect(localStorage.getItem('saas_return_org')).toBe('admin-org')
  })

  it('restores the parked admin session on stop', () => {
    setToken('admin-token')
    setActiveOrg('admin-org')
    beginImpersonation('imp-token', 'target-org')

    const restored = endImpersonation()

    expect(restored).toBe(true)
    expect(getToken()).toBe('admin-token')
    expect(getActiveOrg()).toBe('admin-org')
    // The parked copy is cleared so a second stop cannot re-restore a stale one.
    expect(localStorage.getItem('saas_return_token')).toBeNull()
  })

  it('reports failure when there is nothing parked to restore', () => {
    // e.g. a hard reload wiped the in-flight parked token.
    setToken('imp-token')

    expect(endImpersonation()).toBe(false)
  })
})
