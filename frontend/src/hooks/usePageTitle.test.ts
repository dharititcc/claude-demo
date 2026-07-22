import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { usePageTitle } from './usePageTitle'

// Mirrors the fallback in the hook; the test env sets no VITE_APP_NAME.
const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'SaaS Platform'

describe('usePageTitle', () => {
  it('suffixes the page title with the app name', () => {
    renderHook(() => usePageTitle('Customers'))

    expect(document.title).toBe(`Customers · ${APP_NAME}`)
  })

  it('shows the bare app name while a dynamic title is still loading', () => {
    renderHook(() => usePageTitle(undefined))
    expect(document.title).toBe(APP_NAME)

    renderHook(() => usePageTitle(null))
    expect(document.title).toBe(APP_NAME)

    renderHook(() => usePageTitle(''))
    expect(document.title).toBe(APP_NAME)
  })

  it('updates when the title changes, so a detail page can fill in its entity', () => {
    const { rerender } = renderHook(({ title }: { title?: string }) => usePageTitle(title), {
      initialProps: { title: undefined as string | undefined },
    })

    expect(document.title).toBe(APP_NAME)

    rerender({ title: 'Acme Corp' })

    expect(document.title).toBe(`Acme Corp · ${APP_NAME}`)
  })
})
