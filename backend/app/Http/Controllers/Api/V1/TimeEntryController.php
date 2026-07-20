<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Time tracking against tasks.
 *
 * A user has at most one running timer at a time; starting a new one stops the
 * previous. See TaskService for why that is enforced in the application rather
 * than by a database constraint.
 */
class TimeEntryController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    #[OA\Post(
        path: '/api/v1/tasks/{task}/time/start',
        summary: 'Start a timer on a task',
        description: 'A user has at most one running timer; starting a new one stops any other. MySQL has no partial unique index, so this is enforced in the application.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'description', type: 'string', maxLength: 500)])),
        responses: [new OA\Response(response: 201, description: 'Timer started'), new OA\Response(response: 403, description: 'Lacks tasks.update')],
    )]
    public function start(Request $request, Task $task): JsonResponse
    {
        $this->authorize('trackTime', $task);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $this->service->startTimer($task, $request->user(), $validated['description'] ?? null);

        return response()->json([
            'message' => 'Timer started.',
            'data' => new TimeEntryResource($entry),
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/tasks/{task}/time/stop',
        summary: 'Stop your running timer on a task',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Timer stopped'), new OA\Response(response: 422, description: 'No timer running on this task')],
    )]
    public function stop(Request $request, Task $task): JsonResponse
    {
        $this->authorize('trackTime', $task);

        $entry = $this->service->stopTimer($task, $request->user());

        return response()->json([
            'message' => 'Timer stopped.',
            'data' => new TimeEntryResource($entry),
        ]);
    }

    /**
     * Log time after the fact, without having run a timer.
     */
    #[OA\Post(
        path: '/api/v1/tasks/{task}/time/log',
        summary: 'Log time after the fact',
        description: 'Records a completed entry without running a timer. The start is back-dated so the entry occupies real time on a timeline.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['minutes'], properties: [new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'billable', type: 'boolean')])),
        responses: [new OA\Response(response: 201, description: 'Time logged'), new OA\Response(response: 403, description: 'Lacks tasks.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function log(Request $request, Task $task): JsonResponse
    {
        $this->authorize('trackTime', $task);

        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'], // one day
            'description' => ['nullable', 'string', 'max:500'],
            'billable' => ['sometimes', 'boolean'],
        ]);

        $entry = $this->service->logTime(
            $task,
            $request->user(),
            $validated['minutes'],
            $validated['description'] ?? null,
            $request->boolean('billable', true),
        );

        return response()->json([
            'message' => 'Time logged.',
            'data' => new TimeEntryResource($entry),
        ], 201);
    }

    /**
     * The caller's running timer, if any — drives the persistent timer widget.
     */
    #[OA\Get(
        path: '/api/v1/timer/running',
        summary: 'Your currently running timer, if any',
        description: 'Drives the persistent timer widget. Returns null when nothing is running.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'The running timer, or null')],
    )]
    public function running(Request $request): JsonResponse
    {
        $entry = $this->service->runningTimer($request->user());

        return response()->json([
            'data' => $entry === null ? null : new TimeEntryResource($entry),
        ]);
    }

    /**
     * Entries for a task.
     */
    #[OA\Get(
        path: '/api/v1/tasks/{task}/time',
        summary: 'Time entries for a task',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Entries plus total and billable seconds'), new OA\Response(response: 403, description: 'Lacks tasks.view')],
    )]
    public function index(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $entries = $task->timeEntries()->latest('started_at')->get();

        return response()->json([
            'data' => TimeEntryResource::collection($entries),
            'meta' => [
                'total_seconds' => $task->tracked_seconds,
                'billable_seconds' => (int) $task->timeEntries()->where('billable', true)->sum('seconds'),
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/tasks/{task}/time/{entry}',
        summary: 'Remove a time entry',
        description: 'You may only remove your own time unless you can delete tasks: a timesheet is a billing record, so one person must not quietly rewrite anothers.',
        security: [['sanctum' => []]],
        tags: ['Tasks'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Entry removed'), new OA\Response(response: 403, description: 'Not your entry and you lack tasks.delete'), new OA\Response(response: 404, description: 'Not an entry of this task')],
    )]
    public function destroy(Request $request, Task $task, int $entry): JsonResponse
    {
        $this->authorize('trackTime', $task);

        // Scoped to the task so an id from another record cannot be deleted.
        $record = $task->timeEntries()->whereKey($entry)->firstOrFail();

        // People may only remove their own time, unless they can delete tasks
        // outright — otherwise anyone could quietly rewrite a colleague's
        // timesheet, which is a billing record.
        if ($record->user_id !== $request->user()->id && ! $request->user()->can('tasks.delete')) {
            return response()->json(['message' => 'You can only remove your own time entries.'], 403);
        }

        $record->delete();
        $task->recalculateTrackedTime();

        return response()->json(['message' => 'Time entry removed.']);
    }
}
