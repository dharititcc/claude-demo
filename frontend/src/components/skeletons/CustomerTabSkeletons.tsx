import { Card, CardContent } from '@/components/ui/Card'
import {
  Skeleton,
  SkeletonBadge,
  SkeletonButton,
  SkeletonGroup,
  SkeletonText,
} from '@/components/ui/Skeleton'

/*
 * Skeletons for the customer detail tabs. The projects and invoices tabs are
 * plain tables and use <SkeletonTable> inline; these two have their own shape.
 */

/** A contact tile: name + title, email line, phone line, action row. */
function ContactCardSkeleton() {
  return (
    <Card>
      <CardContent className="space-y-2 pt-5">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <SkeletonText size="base" width="w-36" />
            <SkeletonText size="xs" width="w-28" />
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Skeleton className="h-3.5 w-3.5 rounded" />
          <SkeletonText width="w-44" />
        </div>
        <div className="flex items-center gap-2">
          <Skeleton className="h-3.5 w-3.5 rounded" />
          <SkeletonText width="w-32" />
        </div>
        <div className="flex gap-1 pt-1">
          <SkeletonButton size="sm" width="w-16" />
          <SkeletonButton size="sm" width="w-20" />
        </div>
      </CardContent>
    </Card>
  )
}

export function ContactsGridSkeleton({ count = 4 }: { count?: number }) {
  return (
    <SkeletonGroup label="Loading contacts" className="grid gap-3 sm:grid-cols-2">
      {Array.from({ length: count }, (_, i) => (
        <ContactCardSkeleton key={i} />
      ))}
    </SkeletonGroup>
  )
}

/** Document rows: file icon, name + meta, category pill, action icons. */
export function DocumentsListSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <Card className="overflow-hidden">
      <SkeletonGroup label="Loading documents">
        <ul className="divide-y" aria-hidden>
          {Array.from({ length: rows }, (_, i) => (
            <li key={i} className="p-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <Skeleton className="h-[18px] w-[18px] shrink-0 rounded" />
                  <div className="min-w-0">
                    <SkeletonText size="base" width={i % 2 ? 'w-40' : 'w-56'} />
                    <SkeletonText size="xs" width="w-40" />
                  </div>
                  <SkeletonBadge />
                </div>
                <div className="flex items-center gap-1">
                  <SkeletonButton size="icon" />
                  <SkeletonButton size="icon" />
                  <SkeletonButton size="icon" />
                </div>
              </div>
            </li>
          ))}
        </ul>
      </SkeletonGroup>
    </Card>
  )
}
