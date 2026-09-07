import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent as ReactKeyboardEvent,
} from 'react'
import { createPortal } from 'react-dom'
import { Check, ChevronDown, Search } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface SelectOption {
  value: string
  label: string
  /** Secondary line under the label — a hint, a count, a code. */
  description?: string
  disabled?: boolean
}

type Size = 'sm' | 'md'

export interface SelectProps {
  id?: string
  /** Emits a hidden input so the value participates in a plain HTML form. */
  name?: string
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  options: SelectOption[]
  placeholder?: string
  /**
   * Show a search box in the dropdown. Defaults to on once the list is long
   * enough that scanning it beats scrolling it.
   */
  searchable?: boolean
  searchPlaceholder?: string
  emptyMessage?: string
  disabled?: boolean
  error?: string
  size?: Size
  /** Classes for the trigger; widths and heights go here (`w-full`, `h-9`). */
  className?: string
  'aria-label'?: string
}

const SEARCH_THRESHOLD = 8

const triggerSizes: Record<Size, string> = {
  sm: 'h-8 px-2 text-sm',
  md: 'h-10 px-3 text-sm',
}

/**
 * A Select2-style replacement for the native <select>: a styled trigger, a
 * dropdown with an optional search box, keyboard navigation and a selected
 * marker — themed with the app tokens so it matches in dark mode, which native
 * option lists never do.
 *
 * The dropdown is rendered through a portal with fixed positioning. Several
 * call sites live inside `overflow-x-auto` tables and `overflow-y-auto`
 * dialogs, and an absolutely positioned list is clipped by those; a portal
 * escapes them. The z-index therefore has to clear the dialogs' z-50.
 *
 * Controlled only. It works with react-hook-form through <Controller>: pass
 * `field.value`, `field.onChange` and `field.onBlur`. It cannot take
 * `register()` — that API needs a ref to a native form control, and the one
 * thing this component deliberately does not render is a native control.
 */
export function Select({
  id,
  name,
  value,
  onChange,
  onBlur,
  options,
  placeholder = 'Select…',
  searchable,
  searchPlaceholder = 'Search…',
  emptyMessage = 'No results',
  disabled,
  error,
  size = 'md',
  className,
  'aria-label': ariaLabel,
}: SelectProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [highlighted, setHighlighted] = useState(0)
  const [position, setPosition] = useState<CSSProperties>()

  const triggerRef = useRef<HTMLButtonElement>(null)
  const popoverRef = useRef<HTMLDivElement>(null)
  const listRef = useRef<HTMLUListElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)

  const listId = useId()
  const hasSearch = searchable ?? options.length > SEARCH_THRESHOLD
  const errorId = error && id ? `${id}-error` : undefined

  const selected = options.find((o) => o.value === value)

  const visible = useMemo(() => filterOptions(options, query), [options, query])

  const close = useCallback(
    (refocus: boolean) => {
      setOpen(false)
      setQuery('')
      onBlur?.()
      if (refocus) triggerRef.current?.focus()
    },
    [onBlur],
  )

  function openList() {
    if (disabled) return
    setQuery('')
    const idx = options.findIndex((o) => o.value === value)
    setHighlighted(idx >= 0 ? idx : firstEnabled(options, 0, 1))
    setOpen(true)
  }

  function choose(option: SelectOption) {
    if (option.disabled) return
    if (option.value !== value) onChange(option.value)
    close(true)
  }

  // When the query changes the highlighted row must point at something that
  // is still on screen, or Enter would pick a hidden option.
  function onQueryChange(next: string) {
    setQuery(next)
    setHighlighted(firstEnabled(filterOptions(options, next), 0, 1))
  }

  // Position relative to the viewport (the popover is fixed) and flip upward
  // when the trigger sits near the bottom edge. Recomputed on scroll and
  // resize so the list tracks the trigger instead of floating away from it.
  useLayoutEffect(() => {
    if (!open) return

    function place() {
      const rect = triggerRef.current?.getBoundingClientRect()
      if (!rect) return
      const spaceBelow = window.innerHeight - rect.bottom
      const flip = spaceBelow < 280 && rect.top > spaceBelow
      setPosition({
        position: 'fixed',
        left: rect.left,
        minWidth: rect.width,
        ...(flip
          ? { bottom: window.innerHeight - rect.top + 4 }
          : { top: rect.bottom + 4 }),
      })
    }

    place()
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
    return () => {
      window.removeEventListener('scroll', place, true)
      window.removeEventListener('resize', place)
    }
  }, [open])

  // Move focus into the dropdown once it is on screen so the arrow keys work
  // immediately; jsdom and real browsers both need the node to exist first.
  useEffect(() => {
    if (!open) return
    if (hasSearch) searchRef.current?.focus()
    else listRef.current?.focus()
  }, [open, hasSearch])

  // Click-away. A document listener rather than an overlay div so the click
  // that closes the list still lands on whatever it was aimed at.
  useEffect(() => {
    if (!open) return
    function onPointerDown(e: MouseEvent | TouchEvent) {
      const target = e.target as Node
      if (triggerRef.current?.contains(target) || popoverRef.current?.contains(target)) return
      close(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('touchstart', onPointerDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('touchstart', onPointerDown)
    }
  }, [open, close])

  // Keep the highlighted row visible while arrowing through a long list.
  useEffect(() => {
    if (!open) return
    const el = listRef.current?.querySelector<HTMLElement>(`[data-index="${highlighted}"]`)
    el?.scrollIntoView?.({ block: 'nearest' })
  }, [highlighted, open])

  function onTriggerKeyDown(e: ReactKeyboardEvent<HTMLButtonElement>) {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
      e.preventDefault()
      openList()
    }
  }

  function onPopoverKeyDown(e: ReactKeyboardEvent<HTMLDivElement>) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        setHighlighted((i) => firstEnabled(visible, i + 1, 1))
        break
      case 'ArrowUp':
        e.preventDefault()
        setHighlighted((i) => firstEnabled(visible, i - 1, -1))
        break
      case 'Home':
        e.preventDefault()
        setHighlighted(firstEnabled(visible, 0, 1))
        break
      case 'End':
        e.preventDefault()
        setHighlighted(firstEnabled(visible, visible.length - 1, -1))
        break
      case 'Enter': {
        e.preventDefault()
        const option = visible[highlighted]
        if (option) choose(option)
        break
      }
      case 'Escape':
        e.preventDefault()
        close(true)
        break
      case 'Tab':
        close(false)
        break
    }
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        id={id}
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-label={ariaLabel}
        aria-invalid={error ? true : undefined}
        aria-describedby={errorId}
        disabled={disabled}
        onClick={() => (open ? close(false) : openList())}
        onKeyDown={onTriggerKeyDown}
        className={cn(
          'inline-flex items-center justify-between gap-2 rounded-md border bg-background text-left',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          'disabled:cursor-not-allowed disabled:opacity-50',
          error && 'border-destructive focus-visible:ring-destructive',
          triggerSizes[size],
          className,
        )}
      >
        <span className={cn('truncate', !selected && 'text-muted-foreground')}>
          {selected ? selected.label : placeholder}
        </span>
        <ChevronDown
          size={16}
          aria-hidden
          className={cn('shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')}
        />
      </button>

      {name && <input type="hidden" name={name} value={value} />}

      {error && (
        <p id={errorId} className="mt-1 text-sm text-destructive">
          {error}
        </p>
      )}

      {open &&
        createPortal(
          <div
            ref={popoverRef}
            style={position}
            onKeyDown={onPopoverKeyDown}
            className="z-[60] flex max-h-72 min-w-40 flex-col overflow-hidden rounded-md border bg-card text-card-foreground shadow-lg"
          >
            {hasSearch && (
              <div className="relative border-b p-1.5">
                <Search
                  size={14}
                  aria-hidden
                  className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted-foreground"
                />
                <input
                  ref={searchRef}
                  type="text"
                  value={query}
                  onChange={(e) => onQueryChange(e.target.value)}
                  placeholder={searchPlaceholder}
                  aria-label="Search options"
                  aria-autocomplete="list"
                  aria-controls={listId}
                  autoComplete="off"
                  className="h-8 w-full rounded bg-background pl-8 pr-2 text-sm placeholder:text-muted-foreground focus:outline-none"
                />
              </div>
            )}

            <ul
              ref={listRef}
              id={listId}
              role="listbox"
              tabIndex={-1}
              aria-activedescendant={visible[highlighted] ? `${listId}-${highlighted}` : undefined}
              className="overflow-y-auto p-1 focus:outline-none"
            >
              {visible.length === 0 && (
                <li className="px-2 py-2 text-sm text-muted-foreground">{emptyMessage}</li>
              )}
              {visible.map((option, index) => {
                const isSelected = option.value === value
                return (
                  <li
                    key={option.value}
                    id={`${listId}-${index}`}
                    data-index={index}
                    role="option"
                    aria-selected={isSelected}
                    aria-disabled={option.disabled || undefined}
                    onMouseEnter={() => !option.disabled && setHighlighted(index)}
                    // mousedown, not click: click fires after the button has
                    // taken focus and the trigger's blur would race the choice.
                    onMouseDown={(e) => {
                      e.preventDefault()
                      choose(option)
                    }}
                    className={cn(
                      'flex cursor-pointer items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-sm',
                      index === highlighted && 'bg-accent text-accent-foreground',
                      option.disabled && 'cursor-not-allowed opacity-50',
                    )}
                  >
                    <span className="min-w-0">
                      <span className="block truncate">{option.label}</span>
                      {option.description && (
                        <span className="block truncate text-xs text-muted-foreground">
                          {option.description}
                        </span>
                      )}
                    </span>
                    {isSelected && <Check size={14} aria-hidden className="shrink-0 text-primary" />}
                  </li>
                )
              })}
            </ul>
          </div>,
          document.body,
        )}
    </>
  )
}

function filterOptions(options: SelectOption[], query: string): SelectOption[] {
  const q = query.trim().toLowerCase()
  if (!q) return options
  return options.filter(
    (o) => o.label.toLowerCase().includes(q) || o.description?.toLowerCase().includes(q),
  )
}

/**
 * Index of the first enabled option at or after `from`, stepping by `dir`,
 * wrapping around the list. Returns `from` clamped when nothing is enabled so
 * the caller always gets a valid index.
 */
function firstEnabled(options: SelectOption[], from: number, dir: 1 | -1): number {
  const n = options.length
  if (n === 0) return 0
  let i = ((from % n) + n) % n
  for (let step = 0; step < n; step++) {
    if (!options[i].disabled) return i
    i = (i + dir + n) % n
  }
  return Math.min(Math.max(from, 0), n - 1)
}
