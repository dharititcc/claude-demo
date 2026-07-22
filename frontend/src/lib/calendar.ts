import type { EventOccurrence, Task } from '@/types'

/**
 * One entry in a calendar day cell. Events and task deadlines are different
 * shapes from different endpoints, so they are tagged rather than flattened —
 * the cell renders each kind differently.
 */
export type CalendarItem =
  | { kind: 'event'; occurrence: EventOccurrence }
  | { kind: 'task'; task: Task }

/**
 * Bucket events and task deadlines by calendar day (YYYY-MM-DD) for O(1)
 * lookup while rendering the month grid.
 *
 * Both sources are sliced to their date part rather than parsed: the API sends
 * local-time strings, and `new Date(...)` here would reintroduce the UTC shift
 * the rest of this page is careful to avoid (see `ymd`).
 *
 * Events keep the API's chronological order and come first in a day; tasks
 * follow, because a task is due *on* a day rather than at a time. Tasks with
 * no due date belong to no day and are dropped.
 */
export function bucketByDay(
  occurrences: readonly EventOccurrence[],
  tasks: readonly Task[],
): Map<string, CalendarItem[]> {
  const map = new Map<string, CalendarItem[]>()

  const push = (key: string, item: CalendarItem) => {
    const list = map.get(key)
    if (list) list.push(item)
    else map.set(key, [item])
  }

  for (const occurrence of occurrences) {
    push(occurrence.starts_at.slice(0, 10), { kind: 'event', occurrence })
  }

  for (const task of tasks) {
    if (!task.due_on) continue
    push(task.due_on.slice(0, 10), { kind: 'task', task })
  }

  return map
}
