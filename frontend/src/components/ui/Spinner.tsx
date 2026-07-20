import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Spinner({ className }: { className?: string }) {
  return (
    <span role="status" aria-label="Loading">
      <Loader2 className={cn('h-5 w-5 animate-spin text-muted-foreground', className)} />
    </span>
  )
}
