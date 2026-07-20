import { useState } from 'react'
import { cn } from '@/lib/utils'
import type { BoardColumn, Task, TaskStatus } from '@/types'
import { TaskCard } from './TaskCard'

interface Props {
  label: string
  column: BoardColumn
  canDrag: boolean
  onCardClick: (task: Task) => void
  onDragStart: (task: Task) => void
  onDrop: (status: TaskStatus, beforeId?: number | null) => void
}

/**
 * A Kanban column and its drop target.
 *
 * Uses the native HTML drag-and-drop API rather than a library: the board has
 * one interaction (drop a card onto a column, optionally above another card),
 * and pulling in a drag library for that would be more weight than behaviour.
 */
export function KanbanColumn({ label, column, canDrag, onCardClick, onDragStart, onDrop }: Props) {
  const [isOver, setIsOver] = useState(false)

  return (
    <div
      className="flex w-72 shrink-0 flex-col rounded-lg border bg-muted/30"
      onDragOver={(e) => {
        if (!canDrag) return
        e.preventDefault() // required for the drop to fire
        setIsOver(true)
      }}
      onDragLeave={() => setIsOver(false)}
      onDrop={(e) => {
        e.preventDefault()
        setIsOver(false)
        onDrop(column.status, null) // dropped on empty column space → end
      }}
    >
      <div className="flex items-center justify-between border-b px-3 py-2.5">
        <span className="text-sm font-medium">{label}</span>
        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
          {column.count}
        </span>
      </div>

      <div
        className={cn(
          'flex min-h-24 flex-1 flex-col gap-2 p-2 transition-colors',
          isOver && 'bg-primary/5',
        )}
      >
        {column.tasks.length === 0 ? (
          <p className="py-6 text-center text-xs text-muted-foreground">No tasks</p>
        ) : (
          column.tasks.map((task) => (
            <TaskCard
              key={task.id}
              task={task}
              draggable={canDrag}
              onClick={() => onCardClick(task)}
              onDragStart={() => onDragStart(task)}
              onDropAbove={(e) => {
                // Dropping onto a card places the dragged card above it.
                e.stopPropagation()
                e.preventDefault()
                setIsOver(false)
                onDrop(column.status, task.id)
              }}
            />
          ))
        )}
      </div>
    </div>
  )
}
