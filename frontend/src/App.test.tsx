import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import App from './App'

describe('App', () => {
  it('renders the platform heading', () => {
    render(<App />)
    expect(
      screen.getByRole('heading', { name: /saas platform/i }),
    ).toBeInTheDocument()
  })

  it('renders a theme toggle', () => {
    render(<App />)
    expect(
      screen.getByRole('button', { name: /toggle theme/i }),
    ).toBeInTheDocument()
  })
})
