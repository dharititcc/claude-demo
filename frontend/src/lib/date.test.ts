import { describe, expect, it } from 'vitest'

import { formatDate, formatDateTime } from './date'

describe('formatDate', () => {
  it('renders day-month-year with dashes', () => {
    expect(formatDate(new Date(2026, 6, 20))).toBe('20-07-2026')
  })

  it('zero-pads single-digit days and months', () => {
    expect(formatDate(new Date(2026, 0, 5))).toBe('05-01-2026')
  })

  it('accepts the ISO strings the API returns', () => {
    // Constructed in local time so the assertion holds in any timezone.
    expect(formatDate(new Date(2026, 6, 20, 12).toISOString())).toBe('20-07-2026')
  })

  it('renders an em dash for a missing date', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate(undefined)).toBe('—')
    expect(formatDate('')).toBe('—')
  })

  it('renders an em dash rather than "Invalid Date"', () => {
    expect(formatDate('not a date')).toBe('—')
  })
})

describe('formatDateTime', () => {
  it('appends a 24-hour clock', () => {
    expect(formatDateTime(new Date(2026, 6, 20, 14, 30))).toBe('20-07-2026 14:30')
  })

  it('zero-pads the time', () => {
    expect(formatDateTime(new Date(2026, 6, 20, 9, 5))).toBe('20-07-2026 09:05')
  })

  it('renders an em dash for a missing date', () => {
    expect(formatDateTime(null)).toBe('—')
  })
})
