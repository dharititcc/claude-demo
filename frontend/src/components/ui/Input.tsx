import { forwardRef, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  error?: string
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, error, id, ...props }, ref) => {
    const errorId = error && id ? `${id}-error` : undefined

    return (
      <>
        <input
          ref={ref}
          id={id}
          // Point screen readers at the message rather than relying on colour.
          aria-invalid={error ? true : undefined}
          aria-describedby={errorId}
          className={cn(
            'flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm',
            'placeholder:text-muted-foreground',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            'disabled:cursor-not-allowed disabled:opacity-50',
            error && 'border-destructive focus-visible:ring-destructive',
            className,
          )}
          {...props}
        />
        {error && (
          <p id={errorId} className="mt-1 text-sm text-destructive">
            {error}
          </p>
        )}
      </>
    )
  },
)

Input.displayName = 'Input'
