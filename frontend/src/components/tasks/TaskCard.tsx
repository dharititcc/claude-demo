import type { DragEvent } from 'react'
import { Clock, GitBranch, MessageSquare } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Task, TaskPriority } from '@/types'

const priorityRing: Record<TaskPriority, string> = {
  low: 'border-l-slate-400',
  medium: 'border-l-blue-400',
  high: 'border-l-amber-400',
  urgent: 'border-l-red-500',
}

function formatTracked(seconds: number): string {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (hours > 0) return `${hours}h ${minutes}m`
  return `${minutes}m`
}

interface Props {
  task: Task
  draggable: boolean
  onClick: () => void
  onDragStart: () => void
  onDropAbove: (e: DragEvent) => void
}

export function TaskCard({ task, draggable, onClick, onDragStart, onDropAbove }: Props) {
  return (
    <button
      type="button"
      draggable={draggable}
      onClick={onClick}
      onDragStart={onDragStart}
      onDragOver={(e) => draggable && e.preventDefault()}
      onDrop={onDropAbove}
      className={cn(
        'w-full rounded-md border border-l-4 bg-card p-3 text-left shadow-sm transition-shadow hover:shadow',
        priorityRing[task.priority],
        draggable && 'cursor-grab active:cursor-grabbing',
      )}
    >
      <p className="text-sm font-medium leading-snug">{task.title}</p>

      {task.labels && task.labels.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {task.labels.map((label) => (
            <span
              key={label.id}
              className="rounded px-1.5 py-0.5 text-[10px] font-medium"
              style={{ background: `${label.color}22`, color: label.color }}
            >
              {label.name}
            </span>
          ))}
        </div>
      )}

      <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
        {task.project && (
          <span className="inline-flex items-center gap-1">
            <span className="h-2 w-2 rounded-full" style={{ background: task.project.color }} />
            {task.project.name}
          </span>
        )}
        {task.due_on && (
          <span className={cn(task.is_overdue && 'font-medium text-destructive')}>
            {new Date(task.due_on).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
          </span>
        )}
        {task.subtasks_count ? (
          <span className="inline-flex items-center gap-0.5">
            <GitBranch size={12} /> {task.subtasks_count}
          </span>
        ) : null}
        {task.tracked_seconds > 0 && (
          <span className="inline-flex items-center gap-0.5">
            <Clock size={12} /> {formatTracked(task.tracked_seconds)}
          </span>
        )}
        {task.comments && task.comments.length > 0 && (
          <span className="inline-flex items-center gap-0.5">
            <MessageSquare size={12} /> {task.comments.length}
          </span>
        )}
      </div>
    </button>
  )
}
