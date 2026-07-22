/**
 * Formatting helpers for the admin screens. Kept out of the component files so
 * fast-refresh keeps working (a module should export only components or only
 * non-components, not both).
 */

// Dates use the application-wide format (20-07-2026); re-exported here so the
// admin screens keep their single formatting import.
export { formatDate, formatDateTime } from './date'

/** A metric that may not have been measured yet — render em dash for null. */
export function metric(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : value.toLocaleString()
}
