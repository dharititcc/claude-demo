import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { Organization, User } from '@/types'
import type { ImpersonationState } from '@/types/admin'
import { setActiveOrg, setToken } from '@/services/api'

interface AuthState {
  user: User | null
  organizations: Organization[]
  /** Slug of the organization the user is currently acting in. */
  activeOrgSlug: string | null
  /** Effective permissions within the active organization. */
  permissions: string[]
  isAuthenticated: boolean
  /** Non-null while this session is an impersonation (drives the banner). */
  impersonation: ImpersonationState | null

  setSession: (user: User, organizations: Organization[]) => void
  setUser: (user: User) => void
  setActiveOrg: (slug: string) => void
  setPermissions: (permissions: string[]) => void
  setImpersonation: (impersonation: ImpersonationState | null) => void
  clear: () => void
  can: (permission: string) => boolean
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      organizations: [],
      activeOrgSlug: null,
      permissions: [],
      isAuthenticated: false,
      impersonation: null,

      setSession: (user, organizations) => {
        // Default to the first organization until the user picks one.
        const slug = get().activeOrgSlug ?? organizations[0]?.slug ?? null
        setActiveOrg(slug)
        set({ user, organizations, isAuthenticated: true, activeOrgSlug: slug })
      },

      setUser: (user) => set({ user }),

      setActiveOrg: (slug) => {
        setActiveOrg(slug)
        // Permissions are org-specific, so drop them until the new context loads.
        set({ activeOrgSlug: slug, permissions: [] })
      },

      setPermissions: (permissions) => set({ permissions }),

      setImpersonation: (impersonation) => set({ impersonation }),

      clear: () => {
        setToken(null)
        setActiveOrg(null)
        set({
          user: null,
          organizations: [],
          activeOrgSlug: null,
          permissions: [],
          isAuthenticated: false,
          impersonation: null,
        })
      },

      /**
       * UI-level check only. The API re-authorizes every request; this exists
       * to hide controls the user cannot use, never as the security boundary.
       */
      can: (permission) => {
        const { user, permissions } = get()
        if (user?.is_super_admin) return true
        return permissions.includes(permission)
      },
    }),
    {
      name: 'saas-auth',
      // The token lives in its own storage key (services/api), and permissions
      // are refetched per organization, so neither is persisted here.
      partialize: (state) => ({
        user: state.user,
        organizations: state.organizations,
        activeOrgSlug: state.activeOrgSlug,
        isAuthenticated: state.isAuthenticated,
      }),
    },
  ),
)
