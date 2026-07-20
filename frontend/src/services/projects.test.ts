import { describe, expect, it, vi, beforeEach } from 'vitest'
import { taskService, projectService } from './projects'
import { api } from './api'

vi.mock('./api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

describe('taskService', () => {
  beforeEach(() => vi.clearAllMocks())

  it('sends before_id when moving a card above another', async () => {
    vi.mocked(api.put).mockResolvedValue({ data: { data: { id: 1 } } })

    await taskService.move(1, 'in_progress', 42)

    expect(api.put).toHaveBeenCalledWith('/v1/tasks/1/move', {
      status: 'in_progress',
      before_id: 42,
    })
  })

  it('sends null before_id when dropping at the end of a column', async () => {
    vi.mocked(api.put).mockResolvedValue({ data: { data: { id: 1 } } })

    await taskService.move(1, 'done')

    expect(api.put).toHaveBeenCalledWith('/v1/tasks/1/move', {
      status: 'done',
      before_id: null,
    })
  })

  it('strips empty and null filters from the board request', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    await taskService.board({ project_id: 3, assignee_id: undefined })

    // undefined must not be sent — an empty assignee filter would otherwise
    // become `?assignee_id=` and be treated as a real (invalid) filter.
    expect(api.get).toHaveBeenCalledWith('/v1/tasks/board', { params: { project_id: 3 } })
  })
})

describe('projectService', () => {
  beforeEach(() => vi.clearAllMocks())

  it('drops empty filter values from the query', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [], meta: {}, links: {} } })

    await projectService.list({ q: '', status: 'active' })

    expect(api.get).toHaveBeenCalledWith('/v1/projects', { params: { status: 'active' } })
  })
})
