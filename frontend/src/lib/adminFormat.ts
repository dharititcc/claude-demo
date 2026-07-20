/**
 * Formatting helpers for the admin screens. Kept out of the component files so
 * fast-refresh keeps working (a module should export only components or only
 * non-components, not both).
 */

/** A metric that may not have been measured yet — render em dash for null. */
export function metric(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : value.toLocaleString()
}

/** Short, locale-aware date; em dash when absent. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

/** Date and time, for the audit log where the minute matters. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(value).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
