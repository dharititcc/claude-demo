import { describe, expect, it } from 'vitest'

import { bucketByDay } from './calendar'
import type { EventOccurrence, Task } from '@/types'

function occurrence(overrides: Partial<EventOccurrence> = {}): EventOccurrence {
  return {
    event_id: 1,
    series_id: null,
    title: 'Standup',
    description: null,
    location: null,
    type: 'meeting',
    color: '#3b82f6',
    all_day: false,
    starts_at: '2026-07-20T09:00:00',
    ends_at: '2026-07-20T09:15:00',
    is_recurring: false,
    is_exception: false,
    ...overrides,
  } as EventOccurrence
}

function task(overrides: Partial<Task> = {}): Task {
  return {
    id: 1,
    title: 'Ship the invoice export',
    due_on: '2026-07-20',
    status: 'in_progress',
    is_overdue: false,
    ...overrides,
  } as Task
}

describe('bucketByDay', () => {
  it('buckets a task deadline onto its due day', () => {
    const map = bucketByDay([], [task({ id: 7, due_on: '2026-07-20' })])

    expect(map.get('2026-07-20')).toEqual([{ kind: 'task', task: expect.objectContaining({ id: 7 }) }])
  })

  it('puts events and tasks that fall on the same day in one bucket', () => {
    const map = bucketByDay([occurrence()], [task()])

    const day = map.get('2026-07-20') ?? []
    expect(day).toHaveLength(2)
    expect(day.map((i) => i.kind)).toEqual(['event', 'task'])
  })

  it('keeps separate days apart', () => {
    const map = bucketByDay(
      [occurrence({ starts_at: '2026-07-21T09:00:00' })],
      [task({ due_on: '2026-07-20' })],
    )

    expect(map.get('2026-07-20')).toHaveLength(1)
    expect(map.get('2026-07-21')).toHaveLength(1)
  })

  it('accepts a full timestamp for due_on as well as a bare date', () => {
    const map = bucketByDay([], [task({ due_on: '2026-07-20T00:00:00.000000Z' })])

    expect(map.get('2026-07-20')).toHaveLength(1)
  })

  it('drops tasks with no due date — they belong to no day', () => {
    const map = bucketByDay([], [task({ due_on: null })])

    expect(map.size).toBe(0)
  })

  it('returns an empty map when both sources are empty', () => {
    expect(bucketByDay([], []).size).toBe(0)
  })
})
