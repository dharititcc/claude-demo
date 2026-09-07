import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { Card, CardContent, CardHeader } from '@/components/ui/Card'

/*
 * Skeleton primitives.
 *
 * Every block is `aria-hidden`: a grey rectangle carries no meaning for a
 * screen reader. The one thing assistive tech should hear is that a region is
 * loading, which <SkeletonGroup> announces once for the whole composition.
 *
 * Text placeholders are sized as a line box (the height the real text line
 * occupies) containing a shorter bar, so swapping a skeleton for real content
 * does not move anything below it. The sizes mirror Tailwind's text scale:
 * text-sm has a 20px line height, so `size="sm"` renders a 20px box.
 */

export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div aria-hidden className={cn('skeleton rounded-md', className)} {...props} />
}

interface SkeletonGroupProps {
  /** What is loading, for the screen-reader announcement. */
  label?: string
  className?: string
  children: ReactNode
}

/** Wraps a composed skeleton in a single polite "Loading" announcement. */
export function SkeletonGroup({ label = 'Loading', className, children }: SkeletonGroupProps) {
  return (
    <div role="status" aria-busy="true" className={className}>
      {children}
      <span className="sr-only">{label}…</span>
    </div>
  )
}

/* ── Text ────────────────────────────────────────────────────────────── */

export type TextSize = 'xs' | 'sm' | 'base' | 'lg' | 'xl' | '2xl'

// Line box height (matches the text line height) and bar height (roughly the
// x-height plus ascenders, so it reads as a line of text rather than a block).
const lineBox: Record<TextSize, string> = {
  xs: 'h-4',
  sm: 'h-5',
  base: 'h-6',
  lg: 'h-7',
  xl: 'h-7',
  '2xl': 'h-8',
}

const bar: Record<TextSize, string> = {
  xs: 'h-2.5',
  sm: 'h-3.5',
  base: 'h-4',
  lg: 'h-[18px]',
  xl: 'h-5',
  '2xl': 'h-6',
}

// Successive lines of a paragraph, so multi-line text does not look like a
// block of identical bars.
const paragraphWidths = ['w-full', 'w-11/12', 'w-4/6']

interface SkeletonTextProps {
  size?: TextSize
  /** Tailwind width class for a single line, e.g. `w-32`. */
  width?: string
  lines?: number
  /** Per-line widths; overrides `width` when given. */
  widths?: string[]
  className?: string
}

export function SkeletonText({
  size = 'sm',
  width = 'w-full',
  lines = 1,
  widths,
  className,
}: SkeletonTextProps) {
  if (lines <= 1) {
    return (
      <div aria-hidden className={cn('flex items-center', lineBox[size], className)}>
        <Skeleton className={cn('rounded', bar[size], width)} />
      </div>
    )
  }

  return (
    <div aria-hidden className={className}>
      {Array.from({ length: lines }, (_, i) => (
        <div key={i} className={cn('flex items-center', lineBox[size])}>
          <Skeleton
            className={cn(
              'rounded',
              bar[size],
              widths?.[i] ?? (i === lines - 1 ? paragraphWidths[2] : paragraphWidths[i % 2]),
            )}
          />
        </div>
      ))}
    </div>
  )
}

/** A page or card heading. Defaults to the `text-2xl` page title. */
export function SkeletonTitle({
  size = '2xl',
  width = 'w-48',
  className,
}: Pick<SkeletonTextProps, 'size' | 'width' | 'className'>) {
  return <SkeletonText size={size} width={width} className={className} />
}

/* ── Controls ────────────────────────────────────────────────────────── */

const avatarSizes = { sm: 'h-8 w-8', md: 'h-10 w-10', lg: 'h-16 w-16' } as const

export function SkeletonAvatar({
  size = 'md',
  shape = 'circle',
  className,
}: {
  size?: keyof typeof avatarSizes
  shape?: 'circle' | 'square'
  className?: string
}) {
  return (
    <Skeleton
      className={cn('shrink-0', avatarSizes[size], shape === 'circle' ? 'rounded-full' : 'rounded-lg', className)}
    />
  )
}

// Matches ui/Button sizes so a placeholder button occupies the same box.
const buttonSizes = { sm: 'h-8', md: 'h-10', lg: 'h-11', icon: 'h-10 w-10' } as const

export function SkeletonButton({
  size = 'md',
  width = 'w-24',
  className,
}: {
  size?: keyof typeof buttonSizes
  width?: string
  className?: string
}) {
  return <Skeleton className={cn('shrink-0', buttonSizes[size], size !== 'icon' && width, className)} />
}

export function SkeletonInput({ className }: { className?: string }) {
  return <Skeleton className={cn('h-10 w-full', className)} />
}

export function SkeletonBadge({ width = 'w-14', className }: { width?: string; className?: string }) {
  return <Skeleton className={cn('h-5 rounded-full', width, className)} />
}

/** A labelled control: label line, then the input. Mirrors the form markup. */
export function SkeletonField({
  labelWidth = 'w-24',
  control,
  className,
}: {
  labelWidth?: string
  /** Something other than a text input, e.g. a textarea-sized block. */
  control?: ReactNode
  className?: string
}) {
  return (
    <div className={className}>
      <SkeletonText width={labelWidth} className="mb-1.5" />
      {control ?? <SkeletonInput />}
    </div>
  )
}

/* ── Compositions ────────────────────────────────────────────────────── */

interface SkeletonCardProps {
  /** Width of the title bar; `false` for a card with no header. */
  title?: string | false
  /** Body lines when no children are given. */
  lines?: number
  /** Something in the header's trailing slot, e.g. a button. */
  action?: ReactNode
  className?: string
  contentClassName?: string
  children?: ReactNode
}

export function SkeletonCard({
  title = 'w-32',
  lines = 3,
  action,
  className,
  contentClassName,
  children,
}: SkeletonCardProps) {
  return (
    <Card className={className}>
      {title !== false && (
        <CardHeader className={cn(action && 'flex-row items-center justify-between')}>
          <SkeletonText size="base" width={title} />
          {action}
        </CardHeader>
      )}
      <CardContent className={cn(title === false && 'pt-6', contentClassName)}>
        {children ?? <SkeletonText lines={lines} />}
      </CardContent>
    </Card>
  )
}

export interface SkeletonColumn {
  /** Width of the bar in the body cells. */
  width?: string
  /** Width of the bar in the header cell. */
  headerWidth?: string
  /** Two-line cells (a name over a sub-line) are common in this app's tables. */
  lines?: 1 | 2
  align?: 'left' | 'right'
  /** Render a pill rather than a text bar (status columns). */
  badge?: boolean
}

const defaultColumn: Required<Pick<SkeletonColumn, 'width' | 'headerWidth' | 'lines' | 'align'>> = {
  width: 'w-24',
  headerWidth: 'w-16',
  lines: 1,
  align: 'left',
}

function normaliseColumns(columns: number | SkeletonColumn[]): SkeletonColumn[] {
  return typeof columns === 'number' ? Array.from({ length: columns }, () => ({})) : columns
}

/**
 * Placeholder rows for an existing <tbody>, so a page keeps its real header
 * (sortable, labelled) and only the rows are stand-ins.
 */
export function SkeletonTableRows({
  columns,
  rows = 5,
  cellClassName = 'px-4 py-3',
}: {
  columns: number | SkeletonColumn[]
  rows?: number
  cellClassName?: string
}) {
  const cols = normaliseColumns(columns)

  return (
    <>
      {Array.from({ length: rows }, (_, r) => (
        <tr key={r} aria-hidden>
          {cols.map((col, c) => {
            const { width, lines, align } = { ...defaultColumn, ...col }
            return (
              <td key={c} className={cellClassName}>
                <div className={cn(align === 'right' && 'flex justify-end')}>
                  {col.badge ? (
                    <SkeletonBadge />
                  ) : lines === 2 ? (
                    <>
                      <SkeletonText width={width} />
                      <SkeletonText size="xs" width="w-20" />
                    </>
                  ) : (
                    <SkeletonText width={width} />
                  )}
                </div>
              </td>
            )
          })}
        </tr>
      ))}
      {/* The screen-reader announcement lives in a row so the markup stays
          valid table content. */}
      <tr>
        <td colSpan={cols.length} className="sr-only" role="status" aria-busy="true">
          Loading…
        </td>
      </tr>
    </>
  )
}

/** A whole table, header included, inside an `overflow-x-auto` wrapper. */
export function SkeletonTable({
  columns,
  rows = 5,
  headerClassName = 'border-b bg-muted/40 text-left',
  className,
}: {
  columns: number | SkeletonColumn[]
  rows?: number
  headerClassName?: string
  className?: string
}) {
  const cols = normaliseColumns(columns)

  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="w-full text-sm">
        <thead className={headerClassName}>
          <tr aria-hidden>
            {cols.map((col, c) => {
              const { headerWidth, align } = { ...defaultColumn, ...col }
              return (
                <th key={c} className="px-4 py-3">
                  <div className={cn(align === 'right' && 'flex justify-end')}>
                    <SkeletonText size="xs" width={headerWidth} />
                  </div>
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody className="divide-y">
          <SkeletonTableRows columns={cols} rows={rows} />
        </tbody>
      </table>
    </div>
  )
}

interface SkeletonListProps {
  rows?: number
  /** A leading icon or avatar, as file lists and member lists have. */
  leading?: 'icon' | 'avatar'
  /** Lines of text per row: a title, or a title over a sub-line. */
  lines?: 1 | 2
  trailing?: 'badge' | 'button' | 'icons'
  itemClassName?: string
  className?: string
}

export function SkeletonList({
  rows = 4,
  leading,
  lines = 2,
  trailing,
  itemClassName = 'px-4 py-3',
  className,
}: SkeletonListProps) {
  return (
    <ul aria-hidden className={cn('divide-y', className)}>
      {Array.from({ length: rows }, (_, i) => (
        <li key={i} className={cn('flex items-center justify-between gap-3', itemClassName)}>
          <div className="flex min-w-0 flex-1 items-center gap-3">
            {leading === 'icon' && <Skeleton className="h-[18px] w-[18px] shrink-0 rounded" />}
            {leading === 'avatar' && <SkeletonAvatar size="sm" />}
            <div className="min-w-0 flex-1">
              <SkeletonText width={i % 2 ? 'w-40' : 'w-52'} />
              {lines === 2 && <SkeletonText size="xs" width="w-24" />}
            </div>
          </div>
          {trailing === 'badge' && <SkeletonBadge />}
          {trailing === 'button' && <SkeletonButton size="sm" width="w-20" />}
          {trailing === 'icons' && (
            <div className="flex shrink-0 gap-1">
              <SkeletonButton size="icon" />
              <SkeletonButton size="icon" />
            </div>
          )}
        </li>
      ))}
    </ul>
  )
}

export function SkeletonForm({
  fields = 4,
  columns = 1,
  submit = true,
  className,
}: {
  fields?: number
  columns?: 1 | 2 | 3
  submit?: boolean
  className?: string
}) {
  const grid = { 1: '', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3' }[columns]

  return (
    <div aria-hidden className={cn('space-y-5', className)}>
      <div className={cn('grid gap-4', grid)}>
        {Array.from({ length: fields }, (_, i) => (
          <SkeletonField key={i} labelWidth={i % 2 ? 'w-20' : 'w-28'} />
        ))}
      </div>
      {submit && (
        <div className="flex justify-end">
          <SkeletonButton width="w-32" />
        </div>
      )}
    </div>
  )
}

/**
 * The page frame every screen shares: a title with a one-line description and
 * an optional action button on the right. Children replace the default body.
 */
export function SkeletonPage({
  title = 'w-48',
  subtitle = true,
  action = false,
  label,
  className,
  children,
}: {
  title?: string
  subtitle?: boolean
  action?: boolean
  label?: string
  className?: string
  children?: ReactNode
}) {
  return (
    <SkeletonGroup label={label} className={cn('space-y-6', className)}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <SkeletonTitle width={title} />
          {subtitle && <SkeletonText width="w-64" />}
        </div>
        {action && <SkeletonButton width="w-32" />}
      </div>
      {children ?? <SkeletonCard lines={4} />}
    </SkeletonGroup>
  )
}

/** A KPI tile: label, big number, icon square. */
export function SkeletonStatCard({ hint = false, className }: { hint?: boolean; className?: string }) {
  return (
    <Card>
      <CardContent className={cn('flex items-center justify-between pt-6', className)}>
        <div className="min-w-0 flex-1">
          <SkeletonText width="w-24" />
          <SkeletonText size="2xl" width="w-16" className="mt-1" />
          {hint && <SkeletonText size="xs" width="w-20" className="mt-1" />}
        </div>
        <Skeleton className="ml-3 h-10 w-10 shrink-0 rounded-lg" />
      </CardContent>
    </Card>
  )
}

// Fixed bar heights: a chart placeholder should look like data, and random
// heights would re-roll on every render.
const chartBars = [45, 70, 55, 85, 60, 95, 40, 75, 65, 90, 50, 80]

/** A bar-chart-shaped block for the dashboard's growth chart. */
export function SkeletonChart({ bars = 12, className }: { bars?: number; className?: string }) {
  return (
    <div aria-hidden className={cn('flex h-64 items-end gap-2 px-2 pb-6', className)}>
      {Array.from({ length: bars }, (_, i) => (
        <Skeleton
          key={i}
          className="flex-1 rounded-t rounded-b-none"
          style={{ height: `${chartBars[i % chartBars.length]}%` }}
        />
      ))}
    </div>
  )
}

/** A donut-shaped block for the status pie. */
export function SkeletonDonut({ className }: { className?: string }) {
  return (
    <div aria-hidden className={cn('flex h-64 items-center justify-center', className)}>
      <div className="skeleton h-40 w-40 rounded-full [mask:radial-gradient(circle,transparent_48px,black_49px)]" />
    </div>
  )
}
