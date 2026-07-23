import { api } from './api'
import type {
  BoardColumn,
  Paginated,
  Project,
  ProjectStatus,
  Task,
  TaskPayload,
  TaskStatus,
  TimeEntry,
} from '@/types'

function clean(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null),
  )
}

export const projectService = {
  async list(params: {
    q?: string
    status?: ProjectStatus | ''
    // Already supported by the API; surfaced here for the customer detail tab,
    // which lists a customer's projects without duplicating the Projects module.
    customer_id?: number
    overdue?: boolean
    page?: number
    per_page?: number
  }): Promise<Paginated<Project>> {
    const { data } = await api.get<Paginated<Project>>('/v1/projects', { params: clean(params) })
    return data
  },

  async get(id: number): Promise<Project> {
    const { data } = await api.get<{ data: Project }>(`/v1/projects/${id}`)
    return data.data
  },

  async create(payload: Partial<Project> & { name: string }): Promise<Project> {
    const { data } = await api.post<{ data: Project }>('/v1/projects', payload)
    return data.data
  },

  async update(id: number, payload: Partial<Project>): Promise<Project> {
    const { data } = await api.put<{ data: Project }>(`/v1/projects/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/v1/projects/${id}`)
  },
}

export const taskService = {
  async list(params: {
    q?: string
    project_id?: number
    status?: TaskStatus | ''
    assignee_id?: number
    page?: number
    per_page?: number
  }): Promise<Paginated<Task>> {
    const { data } = await api.get<Paginated<Task>>('/v1/tasks', { params: clean(params) })
    return data
  },

  async board(params: { project_id?: number; assignee_id?: number } = {}): Promise<BoardColumn[]> {
    const { data } = await api.get<{ data: BoardColumn[] }>('/v1/tasks/board', {
      params: clean(params),
    })
    return data.data
  },

  async get(id: number): Promise<Task> {
    const { data } = await api.get<{ data: Task }>(`/v1/tasks/${id}`)
    return data.data
  },

  async create(payload: TaskPayload): Promise<Task> {
    const { data } = await api.post<{ data: Task }>('/v1/tasks', payload)
    return data.data
  },

  async update(id: number, payload: Partial<TaskPayload>): Promise<Task> {
    const { data } = await api.put<{ data: Task }>(`/v1/tasks/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/v1/tasks/${id}`)
  },

  /**
   * Move a card. `beforeId` is the task it is dropped above; omit for the end
   * of the column.
   */
  async move(id: number, status: TaskStatus, beforeId?: number | null): Promise<Task> {
    const { data } = await api.put<{ data: Task }>(`/v1/tasks/${id}/move`, {
      status,
      before_id: beforeId ?? null,
    })
    return data.data
  },
}

export const timeService = {
  async running(): Promise<TimeEntry | null> {
    const { data } = await api.get<{ data: TimeEntry | null }>('/v1/timer/running')
    return data.data
  },

  async start(taskId: number, description?: string): Promise<TimeEntry> {
    const { data } = await api.post<{ data: TimeEntry }>(`/v1/tasks/${taskId}/time/start`, {
      description,
    })
    return data.data
  },

  async stop(taskId: number): Promise<TimeEntry> {
    const { data } = await api.post<{ data: TimeEntry }>(`/v1/tasks/${taskId}/time/stop`)
    return data.data
  },

  async log(taskId: number, minutes: number, description?: string): Promise<TimeEntry> {
    const { data } = await api.post<{ data: TimeEntry }>(`/v1/tasks/${taskId}/time/log`, {
      minutes,
      description,
    })
    return data.data
  },
}
