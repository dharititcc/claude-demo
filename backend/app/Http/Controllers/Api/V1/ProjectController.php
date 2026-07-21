<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\IndexProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use OpenApi\Attributes as OA;

/**
 * Projects in the active organization.
 *
 * Records are scoped by database, not by a where-clause, so nothing here filters
 * by organization.
 */
class ProjectController extends Controller
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Project>>
     */
    #[OA\Get(
        path: '/api/v1/projects',
        summary: 'List projects',
        description: 'Includes task counts and a progress percentage, loaded with withCount so a list does not fire a query per project.',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'overdue', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated projects'), new OA\Response(response: 403, description: 'Lacks projects.view'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function index(IndexProjectRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validated();

        $query = Project::query()
            // withCount rather than loading tasks: progress needs two numbers,
            // not the rows, and loading them would be a query per project.
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn (Builder $q) => $q->where('status', 'done'),
            ])
            ->with('customer:id,name')
            ->search($filters['q'] ?? null);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_on')
                ->whereDate('due_on', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Deterministic tiebreak — without it, rows sharing a sort value can
        // reorder between pages and appear duplicated or missing.
        $query->orderBy($sort, $direction)->orderBy('id', 'desc');

        return ProjectResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
        );
    }

    #[OA\Post(
        path: '/api/v1/projects',
        summary: 'Create a project',
        description: 'Ownership defaults to the creator. Setting status to completed stamps completed_at automatically.',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', enum: ['planning', 'active', 'on_hold', 'completed', 'cancelled']), new OA\Property(property: 'color', type: 'string', example: '#6366f1'), new OA\Property(property: 'customer_id', type: 'integer', nullable: true), new OA\Property(property: 'starts_on', type: 'string', format: 'date'), new OA\Property(property: 'due_on', type: 'string', format: 'date'), new OA\Property(property: 'budget', type: 'number', format: 'float')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 403, description: 'Lacks projects.create'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validated();

        $project = Project::create([
            ...$validated,
            'owner_id' => $validated['owner_id'] ?? $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Project created.',
            'data' => new ProjectResource($project->loadCount('tasks')),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/projects/{project}',
        summary: 'One project with members and attachments',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The project'), new OA\Response(response: 403, description: 'Lacks projects.view'), new OA\Response(response: 404, description: 'Not in this organization')],
    )]
    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->loadCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $q) => $q->where('status', 'done'),
        ])->load(['customer:id,name', 'members', 'attachments']);

        return response()->json(['data' => new ProjectResource($project)]);
    }

    #[OA\Put(
        path: '/api/v1/projects/{project}',
        summary: 'Update a project',
        description: 'status and completed_at are kept in step: marking complete stamps the date, reopening clears it.',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'status', type: 'string', enum: ['planning', 'active', 'on_hold', 'completed', 'cancelled']), new OA\Property(property: 'due_on', type: 'string', format: 'date'), new OA\Property(property: 'budget', type: 'number', format: 'float')])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks projects.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return response()->json([
            'message' => 'Project updated.',
            'data' => new ProjectResource($project->loadCount('tasks')),
        ]);
    }

    /**
     * Soft delete. Tasks are left intact: the project can be restored, and
     * cascading a soft delete would orphan their time entries.
     */
    #[OA\Delete(
        path: '/api/v1/projects/{project}',
        summary: 'Delete a project (soft)',
        description: 'Tasks are left intact so the project can be restored; cascading would orphan their time entries.',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'project', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks projects.delete')],
    )]
    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    #[OA\Post(
        path: '/api/v1/projects/{id}/restore',
        summary: 'Restore a soft-deleted project',
        security: [['sanctum' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Restored'), new OA\Response(response: 403, description: 'Lacks projects.delete'), new OA\Response(response: 404, description: 'No such deleted project')],
    )]
    public function restore(int $id): JsonResponse
    {
        $project = Project::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $project);

        $project->restore();

        return response()->json([
            'message' => 'Project restored.',
            'data' => new ProjectResource($project),
        ]);
    }
}
