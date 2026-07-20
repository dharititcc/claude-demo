import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { X } from 'lucide-react'
import { taskService } from '@/services/projects'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import type { Task, TaskPayload } from '@/types'

const schema = z.object({
  title: z.string().min(1, 'A title is required.').max(255),
  description: z.string().max(10000).or(z.literal('')),
  status: z.enum(['todo', 'in_progress', 'review', 'done']),
  priority: z.enum(['low', 'medium', 'high', 'urgent']),
  due_on: z.string(),
  estimated_minutes: z
    .string()
    .refine((v) => v === '' || Number(v) > 0, 'Must be greater than zero.'),
  labels: z.string(),
})

type FormValues = z.infer<typeof schema>

const empty: FormValues = {
  title: '',
  description: '',
  status: 'todo',
  priority: 'medium',
  due_on: '',
  estimated_minutes: '',
  labels: '',
}

export function TaskFormDialog({
  open,
  task,
  onClose,
  onSaved,
}: {
  open: boolean
  task: Task | null
  onClose: () => void
  onSaved: () => void
}) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty })

  useEffect(() => {
    if (!open) return
    reset(
      task
        ? {
            title: task.title,
            description: task.description ?? '',
            status: task.status,
            priority: task.priority,
            due_on: task.due_on ?? '',
            estimated_minutes: task.estimated_minutes ? String(task.estimated_minutes) : '',
            labels: (task.labels ?? []).map((l) => l.name).join(', '),
          }
        : empty,
    )
  }, [open, task, reset])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const save = useMutation({
    mutationFn: (payload: TaskPayload) =>
      task ? taskService.update(task.id, payload) : taskService.create(payload),
    onSuccess: () => {
      toast.success(task ? 'Task updated.' : 'Task created.')
      onSaved()
      onClose()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not save the task.')),
  })

  const remove = useMutation({
    mutationFn: () => taskService.remove(task!.id),
    onSuccess: () => {
      toast.success('Task deleted.')
      onSaved()
      onClose()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not delete the task.')),
  })

  if (!open) return null

  function onSubmit(values: FormValues) {
    save.mutate({
      title: values.title,
      description: values.description || null,
      status: values.status,
      priority: values.priority,
      due_on: values.due_on || null,
      estimated_minutes: values.estimated_minutes ? Number(values.estimated_minutes) : null,
      labels: values.labels
        .split(',')
        .map((l) => l.trim())
        .filter(Boolean),
    })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden />

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="task-dialog-title"
        className="relative z-10 max-h-[90svh] w-full max-w-lg overflow-y-auto rounded-lg border bg-card p-6 shadow-xl"
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 id="task-dialog-title" className="text-lg font-semibold">
            {task ? 'Edit task' : 'New task'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X size={18} />
          </Button>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div>
            <label htmlFor="t-title" className="mb-1.5 block text-sm font-medium">
              Title
            </label>
            <Input id="t-title" autoFocus error={errors.title?.message} {...register('title')} />
          </div>

          <div>
            <label htmlFor="t-desc" className="mb-1.5 block text-sm font-medium">
              Description
            </label>
            <textarea
              id="t-desc"
              rows={3}
              className="w-full rounded-md border bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              {...register('description')}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="t-status" className="mb-1.5 block text-sm font-medium">
                Status
              </label>
              <select
                id="t-status"
                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                {...register('status')}
              >
                <option value="todo">To do</option>
                <option value="in_progress">In progress</option>
                <option value="review">Review</option>
                <option value="done">Done</option>
              </select>
            </div>
            <div>
              <label htmlFor="t-priority" className="mb-1.5 block text-sm font-medium">
                Priority
              </label>
              <select
                id="t-priority"
                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                {...register('priority')}
              >
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="t-due" className="mb-1.5 block text-sm font-medium">
                Due date
              </label>
              <Input id="t-due" type="date" {...register('due_on')} />
            </div>
            <div>
              <label htmlFor="t-est" className="mb-1.5 block text-sm font-medium">
                Estimate (min)
              </label>
              <Input
                id="t-est"
                type="number"
                min="1"
                error={errors.estimated_minutes?.message}
                {...register('estimated_minutes')}
              />
            </div>
          </div>

          <div>
            <label htmlFor="t-labels" className="mb-1.5 block text-sm font-medium">
              Labels
            </label>
            <Input id="t-labels" placeholder="bug, backend" {...register('labels')} />
            <p className="mt-1 text-xs text-muted-foreground">Separate with commas.</p>
          </div>

          <div className="flex items-center justify-between pt-2">
            {task ? (
              <Button
                type="button"
                variant="ghost"
                className="text-destructive"
                loading={remove.isPending}
                onClick={() => {
                  if (window.confirm('Delete this task?')) remove.mutate()
                }}
              >
                Delete
              </Button>
            ) : (
              <span />
            )}
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={onClose}>
                Cancel
              </Button>
              <Button type="submit" loading={save.isPending}>
                {task ? 'Save' : 'Create'}
              </Button>
            </div>
          </div>
        </form>
      </div>
    </div>
  )
}
