/**
 * Application-wide date formatting: day-month-year separated by dashes
 * (20-07-2026), with the 24-hour clock appended when the time matters.
 *
 * Built from the date parts rather than `toLocaleDateString()` on purpose. The
 * locale-aware call renders the same instant as 7/20/2026 on a US machine and
 * 20/07/2026 on a British one, so the format the product displays would depend
 * on the viewer's browser settings rather than on the product. Dates are read
 * in the viewer's local timezone, which is what the locale calls did too.
 */

const pad = (value: number): string => String(value).padStart(2, '0')

/** Parse to a Date, or null when absent or unparseable. */
function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null

  const date = value instanceof Date ? value : new Date(value)

  return Number.isNaN(date.getTime()) ? null : date
}

/** `20-07-2026`; em dash when there is no date. */
export function formatDate(value: string | Date | null | undefined): string {
  const date = toDate(value)
  if (!date) return '—'

  return `${pad(date.getDate())}-${pad(date.getMonth() + 1)}-${date.getFullYear()}`
}

/** `20-07-2026 14:30`; em dash when there is no date. */
export function formatDateTime(value: string | Date | null | undefined): string {
  const date = toDate(value)
  if (!date) return '—'

  return `${formatDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}
