import type { OrganizationStatus } from '@/types'

/**
 * Super Admin types. Kept separate from the member-facing types because the
 * admin sees fields (owner contact, cross-org metrics, lifecycle state) that a
 * regular member never does.
 */

export interface AdminOrganization {
  id: string
  name: string
  slug: string
  phone: string | null
  logo: string | null
  timezone: string
  currency: string
  language: string
  owner: { id: number; name: string; email: string } | null
  status: OrganizationStatus
  is_trial_expired: boolean
  plan: { id: number; name: string; slug: string } | null
  subscription: {
    status: string | null
    // 'monthly' | 'annual' | null, derived from the Stripe price.
    interval: string | null
    on_trial: boolean
    trial_ends_at: string | null
    ends_at: string | null
  }
  metrics: {
    total_users: number | null
    // null = not yet measured by the rollup, deliberately distinct from 0.
    total_customers: number | null
    total_projects: number | null
    total_tasks: number | null
    total_files: number | null
    storage_mb: number | null
    last_activity_at: string | null
    stats_refreshed_at: string | null
  }
  registered_at: string | null
  deleted_at: string | null
}

export interface AdminLimitDetail {
  used: number
  plan_limit: number | null
  has_override: boolean
  override: number | null
  // null = unlimited.
  effective_limit: number | null
  exceeded: boolean
}

/** Keyed by limit: users, customers, storage_mb. */
export type AdminLimits = Record<string, AdminLimitDetail>

/** Override values to submit; null = unlimited, absent key = clear. */
export type LimitOverrides = Record<string, number | null>

export interface AdminStats {
  total: number
  active: number
  trial: number
  suspended: number
  cancelled: number
  expired: number
  paid: number
  total_users: number
  // null until the first stats rollup has run.
  total_projects: number | null
  total_tasks: number | null
  total_storage_mb: number | null
}

export interface AdminActivity {
  id: number
  action: string
  admin: { id: number; name: string; email: string } | null
  target: { type: string; id: string | null; label: string | null }
  description: string | null
  properties: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export interface AdminOrgFilters {
  search?: string
  status?: OrganizationStatus | ''
  plan?: string
  from?: string
  to?: string
  trashed?: 'with' | 'only' | ''
  sort?: string
  per_page?: number
  page?: number
}

export interface AdminActivityFilters {
  action?: string
  organization?: string
  per_page?: number
  page?: number
}

/** The list endpoints return data + meta, without the paginator links. */
export interface AdminPaginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/** What starting an impersonation returns. */
export interface ImpersonationSession {
  token: string
  expires_at: string
  user: { id: number; name: string; email: string }
  organization: { id: string; slug: string }
}

/** The impersonation block on /auth/me — present only during a session. */
export interface ImpersonationState {
  active: boolean
  impersonator: { id: number; name: string; email: string } | null
  organization_id: string
  expires_at: string | null
}
