import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import {
  Skeleton,
  SkeletonGroup,
  SkeletonList,
  SkeletonPage,
  SkeletonTable,
  SkeletonTableRows,
  SkeletonText,
} from './Skeleton'

describe('Skeleton', () => {
  it('hides bare blocks from assistive tech', () => {
    const { container } = render(<Skeleton className="h-4 w-4" />)

    const block = container.firstElementChild as HTMLElement
    expect(block).toHaveAttribute('aria-hidden', 'true')
    expect(block).toHaveClass('skeleton')
  })

  it('announces a group once as a busy status region', () => {
    render(
      <SkeletonGroup label="Loading dashboard">
        <SkeletonText />
        <SkeletonText />
      </SkeletonGroup>,
    )

    const status = screen.getByRole('status')
    expect(status).toHaveAttribute('aria-busy', 'true')
    expect(status).toHaveTextContent('Loading dashboard…')
    // Only the one announcement; the bars inside are not readable content.
    expect(screen.getAllByRole('status')).toHaveLength(1)
  })

  it('renders one line box per requested text line', () => {
    const { container } = render(<SkeletonText lines={3} />)

    expect(container.querySelectorAll('.skeleton')).toHaveLength(3)
  })

  it('renders the requested number of table rows and cells', () => {
    render(
      <table>
        <tbody>
          <SkeletonTableRows rows={4} columns={[{ lines: 2 }, { badge: true }, { align: 'right' }]} />
        </tbody>
      </table>,
    )

    const rows = screen.getAllByRole('row', { hidden: true })
    // Four placeholder rows plus the screen-reader announcement row.
    expect(rows).toHaveLength(5)
    expect(rows[0].querySelectorAll('td')).toHaveLength(3)
    expect(screen.getByRole('status')).toHaveTextContent('Loading…')
  })

  it('renders a full table with a header row', () => {
    render(<SkeletonTable rows={2} columns={4} />)

    expect(screen.getAllByRole('columnheader', { hidden: true })).toHaveLength(4)
  })

  it('renders list rows with the requested trailing control', () => {
    const { container } = render(<SkeletonList rows={3} trailing="badge" />)

    expect(container.querySelectorAll('li')).toHaveLength(3)
    expect(container.querySelectorAll('.rounded-full')).toHaveLength(3)
  })

  it('composes a page with a header and body region', () => {
    render(<SkeletonPage action label="Loading page" />)

    expect(screen.getByRole('status')).toHaveTextContent('Loading page…')
  })
})
