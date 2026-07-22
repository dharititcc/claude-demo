import { useEffect } from 'react'

/**
 * Sets the browser tab title for the current page.
 *
 * The document title is owned by whichever page is mounted rather than by the
 * router, so a page whose title depends on loaded data (a customer's name, an
 * organization's name) can pass it once it arrives. Pass null/undefined while
 * that data is still loading and the bare app name is shown instead of a
 * half-rendered "undefined".
 *
 * There is no cleanup on unmount: the next page sets its own title as it
 * mounts, and restoring the old one in between would flash the previous
 * page's title during the route transition.
 */
const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'SaaS Platform'

export function usePageTitle(title?: string | null): void {
  useEffect(() => {
    document.title = title ? `${title} · ${APP_NAME}` : APP_NAME
  }, [title])
}
