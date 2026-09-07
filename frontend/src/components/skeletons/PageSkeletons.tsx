import { Card, CardContent, CardHeader } from '@/components/ui/Card'
import {
  Skeleton,
  SkeletonBadge,
  SkeletonButton,
  SkeletonCard,
  SkeletonChart,
  SkeletonDonut,
  SkeletonField,
  SkeletonGroup,
  SkeletonInput,
  SkeletonList,
  SkeletonPage,
  SkeletonStatCard,
  SkeletonText,
  SkeletonTitle,
} from '@/components/ui/Skeleton'

/*
 * Page-shaped skeletons for the organization app.
 *
 * Each one mirrors the real page's containers, grid and spacing so that the
 * swap to live content moves nothing. Where a page keeps its header and only
 * the data region loads, the page composes primitives inline instead and
 * nothing here is needed.
 */

/** Dashboard: greeting, four KPI tiles, growth chart + status donut, recent list. */
export function DashboardSkeleton() {
  return (
    <SkeletonPage title="w-56" label="Loading dashboard">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SkeletonStatCard />
        <SkeletonStatCard />
        <SkeletonStatCard />
        <SkeletonStatCard hint />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <SkeletonCard title="w-32" className="lg:col-span-2">
          <SkeletonChart />
        </SkeletonCard>
        <SkeletonCard title="w-20">
          <SkeletonDonut />
        </SkeletonCard>
      </div>

      <SkeletonCard title="w-36" action={<SkeletonText width="w-16" />}>
        <SkeletonList rows={5} trailing="badge" itemClassName="py-3" />
      </SkeletonCard>
    </SkeletonPage>
  )
}

/** Customer detail: back link, name + badges, LTV, tab strip, details + notes + attachments. */
export function CustomerDetailSkeleton() {
  return (
    <SkeletonGroup label="Loading customer" className="space-y-6">
      <SkeletonText width="w-24" />

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <SkeletonTitle width="w-56" />
          <div className="mt-2 flex items-center gap-2">
            <SkeletonBadge />
            <SkeletonBadge width="w-12" />
          </div>
        </div>
        <div className="flex flex-col items-end">
          <SkeletonText width="w-24" />
          <SkeletonText size="xl" width="w-28" />
        </div>
      </div>

      {/* Tab strip: five labels on the same 2px border. */}
      <div className="flex gap-1 border-b" aria-hidden>
        {['w-16', 'w-20', 'w-16', 'w-16', 'w-20'].map((w, i) => (
          <div key={i} className="px-3 py-2">
            <SkeletonText width={w} />
          </div>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <SkeletonCard title="w-16" className="lg:col-span-1" contentClassName="space-y-3">
          {Array.from({ length: 4 }, (_, i) => (
            <div key={i} className="flex items-center gap-2">
              <Skeleton className="h-[15px] w-[15px] rounded" />
              <SkeletonText width={i % 2 ? 'w-32' : 'w-44'} />
            </div>
          ))}
        </SkeletonCard>

        <div className="space-y-6 lg:col-span-2">
          <SkeletonCard title="w-14" contentClassName="space-y-4">
            <div className="flex gap-2">
              <Skeleton className="h-[58px] flex-1" />
              <SkeletonButton width="w-16" />
            </div>
            <SkeletonList rows={2} trailing={undefined} itemClassName="py-3" />
          </SkeletonCard>

          <SkeletonCard title="w-28" action={<SkeletonButton size="sm" width="w-24" />}>
            <SkeletonList rows={2} trailing="icons" itemClassName="py-2" />
          </SkeletonCard>
        </div>
      </div>
    </SkeletonGroup>
  )
}

/** One project tile: colour strip, name + status, customer, progress, due date. */
function ProjectCardSkeleton() {
  return (
    <Card className="overflow-hidden">
      <div className="h-1.5 skeleton" />
      <CardContent className="pt-5">
        <div className="flex items-start justify-between gap-2">
          <SkeletonText size="base" width="w-36" />
          <SkeletonBadge />
        </div>
        <SkeletonText width="w-28" className="mt-1" />
        <div className="mt-4">
          <div className="mb-1 flex items-center justify-between">
            <SkeletonText size="xs" width="w-16" />
            <SkeletonText size="xs" width="w-8" />
          </div>
          <Skeleton className="h-1.5 rounded-full" />
        </div>
        <SkeletonText size="xs" width="w-24" className="mt-3" />
      </CardContent>
    </Card>
  )
}

/** The project grid, headers and filters left to the page. */
export function ProjectsGridSkeleton({ count = 6 }: { count?: number }) {
  return (
    <SkeletonGroup label="Loading projects" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: count }, (_, i) => (
        <ProjectCardSkeleton key={i} />
      ))}
    </SkeletonGroup>
  )
}

/** A Kanban card: title, a label pill, a meta row. */
function TaskCardSkeleton({ lines }: { lines: 1 | 2 }) {
  return (
    <div className="w-full rounded-md border border-l-4 bg-card p-3 shadow-sm">
      <SkeletonText lines={lines} widths={['w-11/12', 'w-2/3']} />
      <div className="mt-2 flex items-center gap-3">
        <SkeletonText size="xs" width="w-20" />
        <SkeletonText size="xs" width="w-10" />
      </div>
    </div>
  )
}

const boardColumns: Array<Array<1 | 2>> = [
  [2, 1, 2],
  [1, 2],
  [1],
  [2, 1],
]

/** Four Kanban columns with a few cards each; the page header stays real. */
export function TasksBoardSkeleton() {
  return (
    <SkeletonGroup label="Loading tasks" className="flex gap-4 overflow-x-auto pb-4">
      {boardColumns.map((cards, c) => (
        <div key={c} className="flex w-72 shrink-0 flex-col rounded-lg border bg-muted/30">
          <div className="flex items-center justify-between border-b px-3 py-2.5">
            <SkeletonText width="w-20" />
            <Skeleton className="h-5 w-7 rounded-full" />
          </div>
          <div className="flex min-h-24 flex-1 flex-col gap-2 p-2">
            {cards.map((lines, i) => (
              <TaskCardSkeleton key={i} lines={lines} />
            ))}
          </div>
        </div>
      ))}
    </SkeletonGroup>
  )
}

// Which cells carry an event bar, and how many. Fixed so the grid does not
// reshuffle between renders; sparse enough to read as a typical month.
const calendarCells = Array.from({ length: 42 }, (_, i) => (i * 7) % 11 < 3 ? ((i * 7) % 11) + 1 : 0)

/** The 6×7 day grid; the weekday header row above it stays real. */
export function CalendarGridSkeleton() {
  return (
    <SkeletonGroup label="Loading calendar" className="grid grid-cols-7">
      {calendarCells.map((bars, i) => (
        <div key={i} className="min-h-24 border-b border-r p-1.5">
          <Skeleton className="mb-1 h-6 w-6 rounded-full" />
          <div className="space-y-1">
            {Array.from({ length: bars }, (_, b) => (
              <Skeleton key={b} className="h-4 rounded" />
            ))}
          </div>
        </div>
      ))}
    </SkeletonGroup>
  )
}

/** Folder and file rows inside the listing card. */
export function FilesListSkeleton() {
  return (
    <SkeletonGroup label="Loading files">
      <SkeletonList rows={2} leading="icon" lines={1} />
      <SkeletonList rows={4} leading={undefined} trailing="icons" className="border-t" />
    </SkeletonGroup>
  )
}

/** Billing: heading, plan + card, usage bars, plan catalogue, invoices. */
export function BillingSkeleton() {
  return (
    <SkeletonPage title="w-24" label="Loading billing">
      <div className="grid gap-4 lg:grid-cols-3">
        <SkeletonCard title="w-28" className="lg:col-span-2">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <SkeletonText size="xl" width="w-32" />
              <div className="mt-1 flex items-center gap-2">
                <SkeletonBadge />
                <SkeletonBadge width="w-16" />
              </div>
              <SkeletonText size="xs" width="w-28" className="mt-2" />
            </div>
            <SkeletonButton size="sm" width="w-24" />
          </div>
        </SkeletonCard>

        <SkeletonCard title="w-36">
          <SkeletonText width="w-32" />
          <SkeletonText size="xs" width="w-24" className="mt-1" />
        </SkeletonCard>
      </div>

      <Card>
        <CardHeader>
          <SkeletonText size="base" width="w-16" />
          <SkeletonText width="w-56" />
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 3 }, (_, i) => (
            <div key={i}>
              <div className="mb-1 flex items-baseline justify-between">
                <SkeletonText width="w-28" />
                <SkeletonText width="w-16" />
              </div>
              <Skeleton className="h-1.5 rounded-full" />
            </div>
          ))}
        </CardContent>
      </Card>

      <div>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <SkeletonText size="lg" width="w-16" />
          <Skeleton className="h-9 w-44" />
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }, (_, i) => (
            <Card key={i}>
              <CardHeader>
                <SkeletonText size="base" width="w-20" />
                <SkeletonText width="w-40" />
              </CardHeader>
              <CardContent className="space-y-4">
                <SkeletonText size="2xl" width="w-24" />
                <div className="space-y-1.5">
                  <SkeletonText width="w-11/12" />
                  <SkeletonText width="w-4/6" />
                  <SkeletonText width="w-5/6" />
                </div>
                <SkeletonButton className="w-full" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>

      <SkeletonCard title="w-20">
        <SkeletonList rows={3} trailing="button" itemClassName="py-3" />
      </SkeletonCard>
    </SkeletonPage>
  )
}

/** Settings: status badge, organization form in the wide column, subscription + 2FA beside it. */
export function SettingsSkeleton() {
  return (
    <SkeletonGroup label="Loading settings" className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <SkeletonTitle width="w-28" />
          <SkeletonText width="w-48" />
        </div>
        <SkeletonBadge width="w-16" />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <SkeletonText size="base" width="w-28" />
            <SkeletonText lines={2} widths={['w-full', 'w-3/4']} />
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="flex items-center gap-4">
              <Skeleton className="h-16 w-16 rounded-lg" />
              <div>
                <SkeletonButton size="sm" width="w-28" />
                <SkeletonText size="xs" width="w-36" className="mt-1" />
              </div>
            </div>
            <SkeletonField labelWidth="w-12" />
            <div className="grid gap-4 sm:grid-cols-3">
              <SkeletonField labelWidth="w-16" />
              <SkeletonField labelWidth="w-16" />
              <SkeletonField labelWidth="w-16" />
            </div>
            <div className="flex justify-end">
              <SkeletonButton width="w-32" />
            </div>
          </CardContent>
        </Card>

        <div className="space-y-6">
          <SkeletonCard title="w-28">
            <div className="flex items-center justify-between gap-3">
              <div>
                <SkeletonText width="w-28" />
                <SkeletonText size="xs" width="w-32" className="mt-1" />
              </div>
              <SkeletonButton size="sm" width="w-32" />
            </div>
          </SkeletonCard>

          <Card>
            <CardHeader>
              <SkeletonText size="base" width="w-44" />
              <SkeletonText lines={2} widths={['w-full', 'w-2/3']} />
            </CardHeader>
            <CardContent>
              <SkeletonButton width="w-40" />
            </CardContent>
          </Card>
        </div>
      </div>
    </SkeletonGroup>
  )
}

/** The invitation landing card: icon, heading, invite line, role, button. */
export function AcceptInvitationSkeleton() {
  return (
    <div className="flex min-h-svh items-center justify-center p-4">
      <SkeletonGroup label="Loading invitation" className="flex w-full max-w-sm flex-col items-center">
        <Skeleton className="mb-4 h-12 w-12 rounded-xl" />
        <SkeletonTitle width="w-48" />
        <SkeletonText width="w-64" className="mt-2" />
        <SkeletonBadge className="mt-2" />
        <SkeletonButton className="mt-8 w-full" />
        <SkeletonText size="xs" width="w-28" className="mt-6" />
      </SkeletonGroup>
    </div>
  )
}

/** Stripe's PaymentElement in "tabs" layout: method tabs, then card fields. */
export function PaymentFormSkeleton() {
  return (
    <SkeletonGroup label="Loading checkout" className="space-y-4">
      <div className="flex gap-2">
        <Skeleton className="h-14 flex-1" />
        <Skeleton className="h-14 flex-1" />
      </div>
      <SkeletonField labelWidth="w-24" />
      <div className="grid grid-cols-2 gap-3">
        <SkeletonField labelWidth="w-20" />
        <SkeletonField labelWidth="w-12" />
      </div>
      <SkeletonInput />
    </SkeletonGroup>
  )
}
