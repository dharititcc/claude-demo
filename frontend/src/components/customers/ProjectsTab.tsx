import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { projectService } from '@/services/projects'
import { useAuthStore } from '@/store/auth'
import { Badge } from '@/components/ui/Badge'
import { Card, CardContent } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { formatDate } from '@/lib/date'
import type { ProjectStatus } from '@/types'

/**
 * The customer's projects.
 *
 * Read-only and entirely reused: projects.customer_id already existed, the API
 * already filtered on it, and Project already carries progress and task counts.
 * Nothing about the Projects module is restated here — editing a project still
 * happens in the Projects module.
 */
const statusVariant: Record<ProjectStatus, 'default' | 'success' | 'warning' | 'danger' | 'muted'> = {
  planning: 'muted',
  active: 'success',
  on_hold: 'warning',
  completed: 'default',
  cancelled: 'danger',
}

export function ProjectsTab({ customerId }: { customerId: number }) {
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const can = useAuthStore((s) => s.can)

  const projects = useQuery({
    queryKey: ['projects', orgSlug, 'by-customer', customerId],
    queryFn: () => projectService.list({ customer_id: customerId, per_page: 50 }),
    enabled: Boolean(orgSlug && customerId && can('projects.view')),
  })

  // A member without projects.view sees why the tab is empty rather than an
  // empty state that implies there is no work.
  if (!can('projects.view')) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-sm text-muted-foreground">
          You do not have permission to view projects.
        </CardContent>
      </Card>
    )
  }

  if (projects.isLoading) {
    return (
      <div className="flex h-40 items-center justify-center">
        <Spinner className="h-6 w-6" />
      </div>
    )
  }

  if (projects.isError) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-sm text-destructive">
          Could not load the projects.
        </CardContent>
      </Card>
    )
  }

  const rows = projects.data?.data ?? []

  if (rows.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6 text-center text-sm text-muted-foreground">
          No projects for this customer yet.
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground">
            <tr>
              <th className="px-4 py-3">Project</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Progress</th>
              <th className="px-4 py-3">Starts</th>
              <th className="px-4 py-3">Due</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((project) => {
              const progress = project.progress ?? 0

              return (
                <tr key={project.id} className="border-b last:border-0">
                  <td className="px-4 py-3">
                    <Link
                      to={`/projects?q=${encodeURIComponent(project.name)}`}
                      className="flex items-center gap-2 font-medium hover:underline"
                    >
                      <span
                        aria-hidden
                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                        style={{ background: project.color }}
                      />
                      {project.name}
                    </Link>
                    {project.tasks_count !== undefined && (
                      <p className="text-xs text-muted-foreground">
                        {project.completed_tasks_count ?? 0} of {project.tasks_count} tasks done
                      </p>
                    )}
                  </td>

                  <td className="px-4 py-3">
                    <Badge variant={statusVariant[project.status]}>
                      {project.status.replace('_', ' ')}
                    </Badge>
                  </td>

                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <div
                        className="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuenow={progress}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={`${project.name} progress`}
                      >
                        <div className="h-full rounded-full bg-primary" style={{ width: `${progress}%` }} />
                      </div>
                      <span className="tabular-nums text-xs text-muted-foreground">{progress}%</span>
                    </div>
                  </td>

                  <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                    {project.starts_on ? formatDate(project.starts_on) : '—'}
                  </td>

                  <td className="px-4 py-3 whitespace-nowrap">
                    <span className={project.is_overdue ? 'text-destructive' : 'text-muted-foreground'}>
                      {project.due_on ? formatDate(project.due_on) : '—'}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Card>
  )
}
