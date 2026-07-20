import axios, {
  type AxiosError,
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from 'axios'

const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

const TOKEN_KEY = 'saas_token'
const ORG_KEY = 'saas_active_org'
// While impersonating, the admin's own token is parked here so "stop" can
// restore the original session rather than force a fresh login.
const RETURN_TOKEN_KEY = 'saas_return_token'
const RETURN_ORG_KEY = 'saas_return_org'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

/**
 * Swap the admin session for an impersonation session, remembering the admin's
 * token so it can be restored on stop. The impersonation token acts as the
 * target user, confined to `orgSlug` server-side.
 */
export function beginImpersonation(impersonationToken: string, orgSlug: string) {
  const currentToken = getToken()
  const currentOrg = getActiveOrg()
  if (currentToken) localStorage.setItem(RETURN_TOKEN_KEY, currentToken)
  if (currentOrg) localStorage.setItem(RETURN_ORG_KEY, currentOrg)
  setToken(impersonationToken)
  setActiveOrg(orgSlug)
}

/**
 * Restore the parked admin session. Returns false when there was nothing to
 * restore (e.g. the tab was reloaded and the parked token was lost) so the
 * caller can fall back to a normal sign-out.
 */
export function endImpersonation(): boolean {
  const returnToken = localStorage.getItem(RETURN_TOKEN_KEY)
  const returnOrg = localStorage.getItem(RETURN_ORG_KEY)
  localStorage.removeItem(RETURN_TOKEN_KEY)
  localStorage.removeItem(RETURN_ORG_KEY)

  if (!returnToken) return false

  setToken(returnToken)
  setActiveOrg(returnOrg)
  return true
}

export function getActiveOrg(): string | null {
  return localStorage.getItem(ORG_KEY)
}

/**
 * The organization every tenant-scoped request acts against. Kept here rather
 * than read from the auth store so this module has no dependency on it (the
 * store already imports from here).
 */
export function setActiveOrg(slug: string | null) {
  if (slug) localStorage.setItem(ORG_KEY, slug)
  else localStorage.removeItem(ORG_KEY)
}

/**
 * Shared Axios instance. All API access goes through this client so auth,
 * base URL, and error normalization live in one place.
 */
export const api: AxiosInstance = axios.create({
  baseURL: `${API_URL}/api`,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  withCredentials: false,
})

// Attach the bearer token (Sanctum) and the active organization on every
// request. The API enforces membership of that organization server-side.
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  const org = getActiveOrg()
  if (org) {
    config.headers['X-Organization'] = org
  }

  return config
})

// Normalize 401 -> clear token and bubble up for the router to redirect.
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      setToken(null)
    }
    return Promise.reject(error)
  },
)
