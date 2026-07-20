import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { api } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { cn } from '@/lib/utils'
import type { EventOccurrence } from '@/types'

/** Local YYYY-MM-DD, avoiding the UTC shift `toISOString()` would introduce. */
function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

/** Days of the visible month grid, padded to whole weeks (Sunday-first). */
function monthGrid(year: number, month: number): Date[] {
  const first = new Date(year, month, 1)
  const start = new Date(first)
  start.setDate(1 - first.getDay()) // back up to the Sunday on/before the 1st

  const days: Date[] = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    days.push(d)
  }
  return days
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export default function CalendarPage() {
  const [cursor, setCursor] = useState(() => new Date())
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)

  const year = cursor.getFullYear()
  const month = cursor.getMonth()
  const grid = useMemo(() => monthGrid(year, month), [year, month])

  const from = ymd(grid[0])
  const to = ymd(grid[grid.length - 1])

  const events = useQuery({
    queryKey: ['events', orgSlug, from, to],
    queryFn: async () => {
      const { data } = await api.get<{ data: EventOccurrence[] }>('/v1/events', {
        params: { from, to },
      })
      return data.data
    },
    enabled: Boolean(orgSlug),
  })

  // Bucket occurrences by day for O(1) lookup while rendering the grid.
  const byDay = useMemo(() => {
    const map = new Map<string, EventOccurrence[]>()
    for (const occ of events.data ?? []) {
      const key = occ.starts_at.slice(0, 10)
      const list = map.get(key) ?? []
      list.push(occ)
      map.set(key, list)
    }
    return map
  }, [events.data])

  const today = ymd(new Date())

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">
          {cursor.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
        </h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" aria-label="Previous month" onClick={() => setCursor(new Date(year, month - 1, 1))}>
            <ChevronLeft size={16} />
          </Button>
          <Button variant="outline" size="sm" onClick={() => setCursor(new Date())}>
            Today
          </Button>
          <Button variant="outline" size="icon" aria-label="Next month" onClick={() => setCursor(new Date(year, month + 1, 1))}>
            <ChevronRight size={16} />
          </Button>
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="grid grid-cols-7 border-b bg-muted/40 text-center text-xs font-medium text-muted-foreground">
          {WEEKDAYS.map((d) => (
            <div key={d} className="py-2">
              {d}
            </div>
          ))}
        </div>

        {events.isLoading ? (
          <div className="flex h-96 items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : (
          <div className="grid grid-cols-7">
            {grid.map((day) => {
              const key = ymd(day)
              const inMonth = day.getMonth() === month
              const dayEvents = byDay.get(key) ?? []

              return (
                <div
                  key={key}
                  className={cn(
                    'min-h-24 border-b border-r p-1.5',
                    !inMonth && 'bg-muted/20 text-muted-foreground',
                  )}
                >
                  <div
                    className={cn(
                      'mb-1 inline-flex h-6 w-6 items-center justify-center rounded-full text-xs',
                      key === today && 'bg-primary font-medium text-primary-foreground',
                    )}
                  >
                    {day.getDate()}
                  </div>

                  <div className="space-y-1">
                    {dayEvents.slice(0, 3).map((occ, i) => (
                      <div
                        key={`${occ.event_id}-${i}`}
                        title={occ.title}
                        className="truncate rounded px-1 py-0.5 text-[11px] font-medium"
                        style={{ background: `${occ.color}22`, color: occ.color }}
                      >
                        {!occ.all_day && (
                          <span className="tabular-nums opacity-70">
                            {new Date(occ.starts_at).toLocaleTimeString(undefined, {
                              hour: 'numeric',
                              minute: '2-digit',
                            })}{' '}
                          </span>
                        )}
                        {occ.title}
                      </div>
                    ))}
                    {dayEvents.length > 3 && (
                      <div className="px-1 text-[10px] text-muted-foreground">
                        +{dayEvents.length - 3} more
                      </div>
                    )}
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </Card>
    </div>
  )
}
