import { useEffect, useState } from 'react'

/**
 * Delays propagating a fast-changing value (e.g. a search box) so dependent
 * effects and queries don't fire on every keystroke.
 */
export function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    // Cancel on change so only the final value in a burst is applied.
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
