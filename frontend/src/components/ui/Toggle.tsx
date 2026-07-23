import { forwardRef, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

interface ToggleProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'checked'> {
  label: string
  /** Why the setting matters. Worth filling in when "on" is not self-evident. */
  description?: string
  checked: boolean
}

/**
 * A switch backed by a real checkbox.
 *
 * The input stays in the DOM and is only visually hidden, so it keeps native
 * keyboard behaviour, form participation and screen-reader semantics — a div
 * with a click handler would lose all three.
 *
 * Controlled rather than CSS-driven: an earlier version drew the track and thumb
 * from the input's own :checked state via Tailwind's `peer` variants, which did
 * not track the state reliably here — the thumb never moved and the track kept
 * its checked colour. Rendering from the `checked` prop is one source of truth,
 * survives react-hook-form's reset() (which sets the DOM property without firing
 * a change event, desyncing anything that listens for one), and is inspectable
 * in the React tree rather than only in computed CSS.
 *
 * Pair it with RHF's <Controller>, since it needs the value rather than just a
 * ref. Focus is surfaced with focus-within on the row, so no peer selector is
 * involved there either.
 */
export const Toggle = forwardRef<HTMLInputElement, ToggleProps>(
  ({ className, label, description, id, checked, disabled, ...props }, ref) => (
    <label
      htmlFor={id}
      className={cn(
        'flex items-start gap-3 rounded-md border bg-background px-3 py-2.5 transition-colors',
        'focus-within:ring-2 focus-within:ring-ring',
        disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-accent/50',
        className,
      )}
    >
      <input
        ref={ref}
        id={id}
        type="checkbox"
        className="sr-only"
        checked={checked}
        disabled={disabled}
        {...props}
      />

      {/* Decorative: the label element already names the control. */}
      <span
        aria-hidden
        className={cn(
          'relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition-colors',
          checked ? 'bg-primary' : 'bg-input',
        )}
      >
        <span
          className={cn(
            'absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow-sm transition-transform',
            checked && 'translate-x-4',
          )}
        />
      </span>

      <span className="min-w-0">
        <span className="block text-sm font-medium">{label}</span>
        {description && (
          <span className="mt-0.5 block text-xs text-muted-foreground">{description}</span>
        )}
      </span>
    </label>
  ),
)

Toggle.displayName = 'Toggle'
