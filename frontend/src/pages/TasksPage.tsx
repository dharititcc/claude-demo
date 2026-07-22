import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { Plus } from 'lucide-react'
import { taskService } from '@/services/projects'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { KanbanColumn } from '@/components/tasks/KanbanColumn'
import { TaskFormDialog } from '@/components/tasks/TaskFormDialog'
import type { Task, TaskStatus } from '@/types'
import { usePageTitle } from '@/hooks/usePageTitle'

const COLUMN_LABELS: Record<TaskStatus, string> = {
  todo: 'To do',
  in_progress: 'In progress',
  review: 'Review',
  done: 'Done',
}

export default function TasksPage() {
  usePageTitle('Tasks')

  const [editing, setEditing] = useState<Task | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [dragging, setDragging] = useState<Task | null>(null)

  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const can = useAuthStore((s) => s.can)
  const queryClient = useQueryClient()

  const board = useQuery({
    queryKey: ['board', orgSlug],
    queryFn: () => taskService.board(),
    enabled: Boolean(orgSlug),
  })

  const move = useMutation({
    mutationFn: ({ task, status, beforeId }: { task: Task; status: TaskStatus; beforeId?: number | null }) =>
      taskService.move(task.id, status, beforeId),
    // Optimistic: the card should follow the cursor instantly, not after a
    // round-trip. On error we roll back to the snapshot.
    onMutate: async ({ task, status }) => {
      await queryClient.cancelQueries({ queryKey: ['board', orgSlug] })
      const previous = queryClient.getQueryData(['board', orgSlug])

      queryClient.setQueryData(['board', orgSlug], (cols: typeof board.data) =>
        cols?.map((col) => {
          if (col.status === task.status) {
            return { ...col, count: col.count - 1, tasks: col.tasks.filter((t) => t.id !== task.id) }
          }
          if (col.status === status) {
            return { ...col, count: col.count + 1, tasks: [...col.tasks, { ...task, status }] }
          }
          return col
        }),
      )

      return { previous }
    },
    onError: (error, _vars, context) => {
      queryClient.setQueryData(['board', orgSlug], context?.previous)
      toast.error(apiErrorMessage(error, 'Could not move the task.'))
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['board', orgSlug] }),
  })

  function handleDrop(status: TaskStatus, beforeId?: number | null) {
    if (dragging && dragging.status !== status) {
      move.mutate({ task: dragging, status, beforeId })
    } else if (dragging && beforeId) {
      move.mutate({ task: dragging, status, beforeId })
    }
    setDragging(null)
  }

  if (board.isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Tasks</h1>
          <p className="text-sm text-muted-foreground">Drag cards between columns to update status.</p>
        </div>
        {can('tasks.create') && (
          <Button
            onClick={() => {
              setEditing(null)
              setDialogOpen(true)
            }}
          >
            <Plus size={16} /> New task
          </Button>
        )}
      </div>

      <div className="flex gap-4 overflow-x-auto pb-4">
        {board.data?.map((column) => (
          <KanbanColumn
            key={column.status}
            label={COLUMN_LABELS[column.status]}
            column={column}
            canDrag={can('tasks.update')}
            onCardClick={(task) => {
              setEditing(task)
              setDialogOpen(true)
            }}
            onDragStart={setDragging}
            onDrop={handleDrop}
          />
        ))}
      </div>

      <TaskFormDialog
        open={dialogOpen}
        task={editing}
        onClose={() => setDialogOpen(false)}
        onSaved={() => queryClient.invalidateQueries({ queryKey: ['board', orgSlug] })}
      />
    </div>
  )
}
