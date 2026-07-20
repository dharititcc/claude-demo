<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    /**
     * Flat, paginated list — the table and calendar views.
     *
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Task>>
     */
    #[OA\Get(
        path: '/api/v1/tasks',
        summary: 'List tasks',
        description: 'The flat, paginated view behind the table and calendar. Use /tasks/board for the Kanban shape. Sorting is restricted to an allowlist of columns rather than passed through to the query.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'project_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'status', in: 'query', description: 'todo, in_progress, review, done', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'priority', in: 'query', description: 'low, medium, high, urgent', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'assignee_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'search', in: 'query', description: 'Matches title and description', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'due_before', in: 'query', description: 'ISO date', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'due_after', in: 'query', description: 'ISO date', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'sort', in: 'query', description: 'Allowlisted column; prefix with - to reverse', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated tasks'), new OA\Response(response: 403, description: 'Lacks tasks.view'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:50'],
            'due_before' => ['nullable', 'date'],
            'due_after' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
            'roots_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(Task::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Task::query()
            ->with(['labels', 'project:id,name,color'])
            ->withCount('subtasks')
            ->search($filters['q'] ?? null);

        foreach (['project_id', 'status', 'priority', 'assignee_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (! empty($filters['label'])) {
            $slug = $filters['label'];
            $query->whereHas('labels', fn ($q) => $q->where('slug', $slug));
        }

        if (! empty($filters['due_before'])) {
            $query->whereDate('due_on', '<=', $filters['due_before']);
        }

        if (! empty($filters['due_after'])) {
            $query->whereDate('due_on', '>=', $filters['due_after']);
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_on')->whereDate('due_on', '<', now())->where('status', '!=', 'done');
        }

        // Default to top-level tasks: subtasks are shown nested under a parent
        // and would otherwise appear twice.
        if ($request->boolean('roots_only', true)) {
            $query->roots();
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id', 'desc');

        return TaskResource::collection(
            $query->paginate($filters['per_page'] ?? 25)->withQueryString(),
        );
    }

    /**
     * Tasks grouped into Kanban columns.
     *
     * Returns every column even when empty, so the board renders its full shape
     * rather than collapsing to whatever happens to have cards.
     */
    #[OA\Get(
        path: '/api/v1/tasks/board',
        summary: 'Tasks grouped into Kanban columns',
        description: 'Every column is returned even when empty, so the board renders its full shape. One query for the whole board, grouped in PHP.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'project_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'assignee_id', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Columns in board order: todo, in_progress, review, done'), new OA\Response(response: 403, description: 'Lacks tasks.view')],
    )]
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $query = Task::query()
            ->with(['labels', 'project:id,name,color'])
            ->withCount('subtasks')
            ->roots()
            ->orderBy('position');

        foreach (['project_id', 'assignee_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        // One query for the whole board, grouped in PHP — a query per column
        // would be four round-trips for the same rows.
        $grouped = $query->get()->groupBy('status');

        $columns = [];

        foreach (Task::STATUSES as $status) {
            $tasks = $grouped->get($status, collect());

            $columns[] = [
                'status' => $status,
                'count' => $tasks->count(),
                'tasks' => TaskResource::collection($tasks),
            ];
        }

        return response()->json(['data' => $columns]);
    }

    #[OA\Post(
        path: '/api/v1/tasks',
        summary: 'Create a task',
        description: 'A task with a parent_id is a subtask. Creation counts against the plan task quota, which is why the failure here is 402 rather than 403: the caller is permitted, the plan is not.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title'], properties: [new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'project_id', type: 'integer', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['todo', 'in_progress', 'review', 'done']), new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']), new OA\Property(property: 'assignee_id', type: 'integer', nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true, description: 'Set to make this a subtask'), new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'estimated_hours', type: 'number', nullable: true), new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 402, description: 'Would exceed the plan task quota'), new OA\Response(response: 403, description: 'Lacks tasks.create'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $task = $this->service->create($this->validateTask($request), $request->user());

        return response()->json([
            'message' => 'Task created.',
            'data' => new TaskResource($task),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/tasks/{task}',
        summary: 'One task with subtasks, comments, and time entries',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The task'), new OA\Response(response: 403, description: 'Lacks tasks.view'), new OA\Response(response: 404, description: 'Not in this organization')],
    )]
    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $task->load(['labels', 'project:id,name,color', 'subtasks.labels', 'comments', 'attachments', 'timeEntries']);

        return response()->json(['data' => new TaskResource($task)]);
    }

    #[OA\Put(
        path: '/api/v1/tasks/{task}',
        summary: 'Update a task',
        description: 'Setting status to done stamps completed_at; moving away from done clears it.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['todo', 'in_progress', 'review', 'done']), new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']), new OA\Property(property: 'assignee_id', type: 'integer', nullable: true), new OA\Property(property: 'due_on', type: 'string', format: 'date'), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'labels', type: 'array', items: new OA\Items(type: 'string'))])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks tasks.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $task = $this->service->update($task, $this->validateTask($request, updating: true));

        return response()->json([
            'message' => 'Task updated.',
            'data' => new TaskResource($task),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/tasks/{task}',
        summary: 'Delete a task (soft)',
        description: 'Subtasks are soft-deleted with the parent, so they do not resurface at the root of the board.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks tasks.delete')],
    )]
    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /**
     * Drag-and-drop: move a card to a column, optionally above a given card.
     */
    #[OA\Put(
        path: '/api/v1/tasks/{task}/move',
        summary: 'Move a card on the Kanban board',
        description: 'Positions are floats: a card dropped between two others takes their midpoint, so a drag writes one row rather than renumbering the column. Omit before_id to append to the end.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['todo', 'in_progress', 'review', 'done']), new OA\Property(property: 'before_id', type: 'integer', nullable: true, description: 'Task this card is dropped above')])),
        responses: [new OA\Response(response: 200, description: 'Moved'), new OA\Response(response: 403, description: 'Lacks tasks.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function move(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'before_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ]);

        $task = $this->service->move($task, $validated['status'], $validated['before_id'] ?? null);

        return response()->json([
            'message' => 'Task moved.',
            'data' => new TaskResource($task->load('labels')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTask(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $central = config('tenancy.database.central_connection');

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],

            // Users are central; an unqualified rule would query the tenant DB.
            'assignee_id' => ['nullable', 'integer', "exists:{$central}.users,id"],

            'due_on' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'labels' => ['sometimes', 'array', 'max:20'],
            'labels.*' => ['string', 'max:50'],
        ]);
    }
}
