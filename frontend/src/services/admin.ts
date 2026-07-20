import { api } from './api'
import type {
  AdminActivity,
  AdminActivityFilters,
  AdminLimits,
  AdminOrganization,
  AdminOrgFilters,
  AdminPaginated,
  AdminStats,
  ImpersonationSession,
  LimitOverrides,
} from '@/types/admin'

/** The detail endpoint returns the org plus its usage/limit breakdown. */
export interface AdminOrganizationDetail {
  organization: AdminOrganization
  limits: AdminLimits
}

/**
 * Super Admin API. These endpoints are central-context — they read across all
 * organizations and do NOT take an X-Organization header. The request
 * interceptor still attaches one if an org is active, but the admin routes
 * ignore it.
 */
export const adminService = {
  async listOrganizations(filters: AdminOrgFilters): Promise<AdminPaginated<AdminOrganization>> {
    const { data } = await api.get<AdminPaginated<AdminOrganization>>('/v1/admin/organizations', {
      params: clean(filters),
    })
    return data
  },

  async getOrganization(id: string): Promise<AdminOrganizationDetail> {
    const { data } = await api.get<{ data: AdminOrganization; limits: AdminLimits }>(
      `/v1/admin/organizations/${id}`,
    )
    return { organization: data.data, limits: data.limits }
  },

  async updateLimits(id: string, overrides: LimitOverrides): Promise<AdminOrganizationDetail> {
    const { data } = await api.put<{ data: AdminOrganization; limits: AdminLimits }>(
      `/v1/admin/organizations/${id}/limits`,
      { overrides },
    )
    return { organization: data.data, limits: data.limits }
  },

  async stats(): Promise<AdminStats> {
    const { data } = await api.get<{ data: AdminStats }>('/v1/admin/organizations/stats')
    return data.data
  },

  async updateOrganization(
    id: string,
    payload: Partial<Pick<AdminOrganization, 'name' | 'phone' | 'timezone' | 'currency' | 'language'>>,
  ): Promise<AdminOrganization> {
    const { data } = await api.put<{ data: AdminOrganization }>(`/v1/admin/organizations/${id}`, payload)
    return data.data
  },

  async suspend(id: string): Promise<AdminOrganization> {
    const { data } = await api.post<{ data: AdminOrganization }>(`/v1/admin/organizations/${id}/suspend`)
    return data.data
  },

  async activate(id: string): Promise<AdminOrganization> {
    const { data } = await api.post<{ data: AdminOrganization }>(`/v1/admin/organizations/${id}/activate`)
    return data.data
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/v1/admin/organizations/${id}`)
  },

  async restore(id: string): Promise<AdminOrganization> {
    const { data } = await api.post<{ data: AdminOrganization }>(`/v1/admin/organizations/${id}/restore`)
    return data.data
  },

  async listActivity(filters: AdminActivityFilters): Promise<AdminPaginated<AdminActivity>> {
    const { data } = await api.get<AdminPaginated<AdminActivity>>('/v1/admin/activity', {
      params: clean(filters),
    })
    return data
  },

  async impersonate(orgId: string, userId?: number): Promise<ImpersonationSession> {
    const { data } = await api.post<{ data: ImpersonationSession }>(
      `/v1/admin/organizations/${orgId}/impersonate`,
      userId ? { user_id: userId } : {},
    )
    return data.data
  },

  /** Called with the impersonation token active; revokes it server-side. */
  async stopImpersonation(): Promise<void> {
    await api.post('/v1/impersonation/stop')
  },
}

/** Drop empty params so the URL stays clean and the API sees no blank filters. */
function clean(filters: object): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined && v !== null),
  )
}
