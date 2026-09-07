import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { Select, type SelectOption } from './Select'

const STATUSES: SelectOption[] = [
  { value: 'lead', label: 'Lead' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive', disabled: true },
  { value: 'churned', label: 'Churned' },
]

const TIMEZONES: SelectOption[] = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Paris',
  'Asia/Kolkata',
  'Asia/Tokyo',
  'Australia/Sydney',
].map((tz) => ({ value: tz, label: tz }))

function Harness({
  options,
  initial = '',
  onChange = () => {},
  ...rest
}: {
  options: SelectOption[]
  initial?: string
  onChange?: (v: string) => void
  searchable?: boolean
  disabled?: boolean
}) {
  const [value, setValue] = useState(initial)
  return (
    <>
      <label htmlFor="status">Status</label>
      <Select
        id="status"
        options={options}
        value={value}
        onChange={(v) => {
          setValue(v)
          onChange(v)
        }}
        {...rest}
      />
    </>
  )
}

describe('Select', () => {
  it('shows the selected label and opens a listbox with every option', async () => {
    const user = userEvent.setup()
    render(<Harness options={STATUSES} initial="active" />)

    const trigger = screen.getByLabelText('Status')
    expect(trigger).toHaveTextContent('Active')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')

    await user.click(trigger)

    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getAllByRole('option')).toHaveLength(4)
    expect(screen.getByRole('option', { name: 'Active' })).toHaveAttribute('aria-selected', 'true')
    // Short lists do not get a search box.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('emits the chosen value and closes on click', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness options={STATUSES} initial="lead" onChange={onChange} />)

    await user.click(screen.getByLabelText('Status'))
    await user.click(screen.getByRole('option', { name: 'Churned' }))

    expect(onChange).toHaveBeenCalledWith('churned')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Status')).toHaveTextContent('Churned')
  })

  it('navigates with the keyboard, skipping disabled options', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness options={STATUSES} initial="active" onChange={onChange} />)

    screen.getByLabelText('Status').focus()
    await user.keyboard('{ArrowDown}')
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    // Starts on the current value; ArrowDown hops over the disabled row.
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith('churned')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Status')).toHaveFocus()
  })

  it('closes on Escape without changing the value', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness options={STATUSES} initial="lead" onChange={onChange} />)

    await user.click(screen.getByLabelText('Status'))
    await user.keyboard('{ArrowDown}{Escape}')

    expect(onChange).not.toHaveBeenCalled()
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('filters a long list through the search box', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness options={TIMEZONES} initial="UTC" onChange={onChange} />)

    await user.click(screen.getByLabelText('Status'))

    const search = screen.getByRole('textbox', { name: 'Search options' })
    expect(search).toHaveFocus()

    await user.type(search, 'europe')
    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Europe/London',
      'Europe/Paris',
    ])

    await user.keyboard('{ArrowDown}{Enter}')
    expect(onChange).toHaveBeenCalledWith('Europe/Paris')
  })

  it('tells the user when nothing matches', async () => {
    const user = userEvent.setup()
    render(<Harness options={TIMEZONES} />)

    await user.click(screen.getByLabelText('Status'))
    await user.type(screen.getByRole('textbox', { name: 'Search options' }), 'zzz')

    expect(screen.queryAllByRole('option')).toHaveLength(0)
    expect(screen.getByText('No results')).toBeInTheDocument()
  })

  it('closes when clicking elsewhere', async () => {
    const user = userEvent.setup()
    render(
      <>
        <Harness options={STATUSES} />
        <button type="button">Elsewhere</button>
      </>,
    )

    await user.click(screen.getByLabelText('Status'))
    expect(screen.getByRole('listbox')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Elsewhere' }))
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('does not open when disabled', async () => {
    const user = userEvent.setup()
    render(<Harness options={STATUSES} disabled />)

    await user.click(screen.getByLabelText('Status'))
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
})
