import { describe, expect, it } from 'vitest'
import { safeHttpUrl } from './utils'

describe('safeHttpUrl', () => {
  it('passes through an https URL', () => {
    expect(safeHttpUrl('https://example.com/file.pdf')).toBe('https://example.com/file.pdf')
  })

  it('passes through an http URL', () => {
    expect(safeHttpUrl('http://example.com/file.pdf')).toBe('http://example.com/file.pdf')
  })

  it('rejects a javascript: URL', () => {
    expect(safeHttpUrl('javascript:alert(1)')).toBeUndefined()
  })

  it('rejects a data: URL', () => {
    expect(safeHttpUrl('data:text/html,<script>alert(1)</script>')).toBeUndefined()
  })

  it('returns undefined for an empty string', () => {
    expect(safeHttpUrl('')).toBeUndefined()
  })

  it('returns undefined for null', () => {
    expect(safeHttpUrl(null)).toBeUndefined()
  })

  it('returns undefined for undefined', () => {
    expect(safeHttpUrl(undefined)).toBeUndefined()
  })
})
