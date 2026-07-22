import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import toast from 'react-hot-toast'
import { Plus, X } from 'lucide-react'
import { projectService } from '@/services/projects'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate } from '@/lib/date'
import type { Project, ProjectStatus } from '@/types'

const statusVariant: Record<ProjectStatus, 'success' | 'default' | 'warning' | 'danger' | 'muted'> = {
  active: 'success',
  planning: 'default',
  on_hold: 'warning',
  completed: 'muted',
  cancelled: 'danger',
}

const createSchema = z.object({
  name: z.string().min(1, 'A name is required.').max(255),
  status: z.enum(['planning', 'active', 'on_hold', 'completed', 'cancelled']),
  due_on: z.string(),
})
type CreateValues = z.infer<typeof createSchema>

export default function ProjectsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const [creating, setCreating] = useState(false)

  const debounced = useDebounced(search, 300)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const can = useAuthStore((s) => s.can)
  const queryClient = useQueryClient()

  const filters = useMemo(() => ({ q: debounced, status: status as ProjectStatus | '' }), [debounced, status])

  const projects = useQuery({
    queryKey: ['projects', orgSlug, filters],
    queryFn: () => projectService.list(filters),
    enabled: Boolean(orgSlug),
  })

  const create = useMutation({
    mutationFn: (values: CreateValues) =>
      projectService.create({ name: values.name, status: values.status, due_on: values.due_on || null }),
    onSuccess: () => {
      toast.success('Project created.')
      queryClient.invalidateQueries({ queryKey: ['projects', orgSlug] })
      setCreating(false)
      reset()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not create the project.')),
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { name: '', status: 'active', due_on: '' },
  })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Projects</h1>
          <p className="text-sm text-muted-foreground">
            {projects.data ? `${projects.data.meta.total} total` : 'Loading…'}
          </p>
        </div>
        {can('projects.create') && !creating && (
          <Button onClick={() => setCreating(true)}>
            <Plus size={16} /> New project
          </Button>
        )}
      </div>

      {creating && (
        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <CardTitle>New project</CardTitle>
            <Button variant="ghost" size="icon" onClick={() => setCreating(false)} aria-label="Cancel">
              <X size={16} />
            </Button>
          </CardHeader>
          <form
            onSubmit={handleSubmit((v) => create.mutate(v))}
            className="flex flex-wrap items-start gap-3 p-6 pt-0"
            noValidate
          >
            <div className="min-w-56 flex-1">
              <Input placeholder="Project name" error={errors.name?.message} {...register('name')} />
            </div>
            <select
              className="h-10 rounded-md border bg-background px-3 text-sm"
              {...register('status')}
            >
              <option value="planning">Planning</option>
              <option value="active">Active</option>
              <option value="on_hold">On hold</option>
            </select>
            <Input type="date" className="w-40" {...register('due_on')} />
            <Button type="submit" loading={create.isPending}>
              Create
            </Button>
          </form>
        </Card>
      )}

      <div className="flex flex-wrap gap-3">
        <Input
          className="min-w-56 flex-1"
          placeholder="Search projects…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          aria-label="Search projects"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="h-10 rounded-md border bg-background px-3 text-sm"
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          {['planning', 'active', 'on_hold', 'completed', 'cancelled'].map((s) => (
            <option key={s} value={s}>
              {s.replace('_', ' ')}
            </option>
          ))}
        </select>
      </div>

      {projects.isLoading ? (
        <div className="flex h-48 items-center justify-center">
          <Spinner className="h-6 w-6" />
        </div>
      ) : projects.data?.data.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-muted-foreground">
            No projects match those filters.
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {projects.data?.data.map((project) => (
            <ProjectCard key={project.id} project={project} statusVariant={statusVariant} />
          ))}
        </div>
      )}
    </div>
  )
}

function ProjectCard({
  project,
  statusVariant,
}: {
  project: Project
  statusVariant: Record<ProjectStatus, 'success' | 'default' | 'warning' | 'danger' | 'muted'>
}) {
  return (
    <Card className="overflow-hidden">
      <div className="h-1.5" style={{ background: project.color }} />
      <CardContent className="pt-5">
        <div className="flex items-start justify-between gap-2">
          <h3 className="font-medium leading-snug">{project.name}</h3>
          <Badge variant={statusVariant[project.status]}>{project.status.replace('_', ' ')}</Badge>
        </div>

        {project.customer && (
          <p className="mt-1 text-sm text-muted-foreground">{project.customer.name}</p>
        )}

        {project.progress !== undefined && (
          <div className="mt-4">
            <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
              <span>
                {project.completed_tasks_count ?? 0} / {project.tasks_count ?? 0} tasks
              </span>
              <span>{project.progress}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
              <div className="h-full rounded-full bg-primary" style={{ width: `${project.progress}%` }} />
            </div>
          </div>
        )}

        {project.due_on && (
          <p className={`mt-3 text-xs ${project.is_overdue ? 'font-medium text-destructive' : 'text-muted-foreground'}`}>
            Due {formatDate(project.due_on)}
            {project.is_overdue && ' · overdue'}
          </p>
        )}
      </CardContent>
    </Card>
  )
}
