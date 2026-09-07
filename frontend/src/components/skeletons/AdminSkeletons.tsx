import { Card, CardContent } from '@/components/ui/Card'
import {
  Skeleton,
  SkeletonBadge,
  SkeletonButton,
  SkeletonCard,
  SkeletonGroup,
  SkeletonPage,
  SkeletonText,
  SkeletonTitle,
} from '@/components/ui/Skeleton'

/*
 * Page-shaped skeletons for the platform-admin area. Its tables replace the
 * whole card while loading (header included), so those use <SkeletonTable>
 * inline; only the multi-section pages need a composition here.
 */

/** The admin KPI tile is padded p-5 with a square icon well, unlike the org one. */
function AdminStatCardSkeleton({ hint }: { hint?: boolean }) {
  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-4 p-5">
        <div className="min-w-0 flex-1">
          <SkeletonText width="w-28" />
          <SkeletonText size="2xl" width="w-16" className="mt-1" />
          {hint && <SkeletonText size="xs" width="w-32" className="mt-1" />}
        </div>
        <Skeleton className="h-10 w-10 shrink-0" />
      </CardContent>
    </Card>
  )
}

/** Platform overview: heading and the nine-tile grid. */
export function AdminDashboardSkeleton() {
  return (
    <SkeletonPage title="w-52" label="Loading platform overview">
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 9 }, (_, i) => (
          <AdminStatCardSkeleton key={i} hint={i >= 6} />
        ))}
      </section>
    </SkeletonPage>
  )
}

/** A <dl> of label-over-value fields, as the detail cards render. */
function FieldsSkeleton({ count, className }: { count: number; className: string }) {
  return (
    <dl className={className} aria-hidden>
      {Array.from({ length: count }, (_, i) => (
        <div key={i}>
          <SkeletonText size="xs" width="w-16" />
          <SkeletonText width={i % 3 === 0 ? 'w-32' : 'w-24'} className="mt-0.5" />
        </div>
      ))}
    </dl>
  )
}

/** Organization detail: back link, name + status, profile / subscription / usage / limits, actions. */
export function AdminOrgDetailSkeleton() {
  return (
    <SkeletonGroup label="Loading organization" className="space-y-6">
      <SkeletonText width="w-32" />

      <div>
        <div className="flex items-center gap-3">
          <SkeletonTitle width="w-56" />
          <SkeletonBadge />
        </div>
        <SkeletonText width="w-28" />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <SkeletonCard title="w-16" action={<SkeletonButton size="sm" width="w-16" />}>
            <FieldsSkeleton count={7} className="grid gap-4 sm:grid-cols-2" />
          </SkeletonCard>

          <SkeletonCard title="w-28">
            <FieldsSkeleton count={5} className="grid gap-4 sm:grid-cols-2" />
          </SkeletonCard>

          <SkeletonCard title="w-16">
            <FieldsSkeleton count={6} className="grid grid-cols-2 gap-4 sm:grid-cols-3" />
            <SkeletonText size="xs" width="w-56" className="mt-4" />
          </SkeletonCard>

          <SkeletonCard title="w-36" action={<SkeletonButton size="sm" width="w-16" />} contentClassName="space-y-3">
            {Array.from({ length: 3 }, (_, i) => (
              <div key={i} className="flex items-center justify-between gap-4">
                <SkeletonText width="w-24" />
                <SkeletonText width="w-20" />
              </div>
            ))}
          </SkeletonCard>
        </div>

        <SkeletonCard title="w-16" className="h-fit" contentClassName="space-y-3">
          <SkeletonButton className="w-full" />
          <SkeletonButton className="w-full" />
          <SkeletonButton className="w-full" />
        </SkeletonCard>
      </div>
    </SkeletonGroup>
  )
}
