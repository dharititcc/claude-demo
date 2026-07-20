<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\RecurrenceExpander;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Calendar events.
 *
 * The calendar view (`index`) returns *expanded occurrences* for a date window,
 * not raw rows: a recurring series is one row but many occurrences. CRUD acts on
 * the underlying rows.
 */
class EventController extends Controller
{
    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * Occurrences within a window, recurring series expanded.
     */
    #[OA\Get(
        path: '/api/v1/events',
        summary: 'Calendar occurrences in a date window',
        description: 'Returns EXPANDED occurrences, not rows: a recurring series is one row but many occurrences. The window is capped at one year so a malformed rule cannot expand unbounded.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'from', in: 'query', description: 'Required. Window start (date)', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'to', in: 'query', description: 'Required. Window end; max one year after from', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'project_id', in: 'query', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Occurrences, sorted by start'), new OA\Response(response: 403, description: 'Lacks calendar.view'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            // Bounded window: expansion is per-window, so an unbounded range
            // would let one request generate an unbounded number of occurrences.
            'to' => ['required', 'date', 'after:from', 'before_or_equal:'.Carbon::parse($request->input('from', 'now'))->addYear()->toDateString()],
            'project_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        $base = Event::query()->seriesAndSingles()->overlapping($from, $to);

        if (! empty($validated['project_id'])) {
            $base->where('project_id', $validated['project_id']);
        }

        $events = $base->get();

        // Exception rows (moved/edited/cancelled occurrences) for the series in play.
        $exceptions = Event::query()
            ->whereNotNull('parent_id')
            ->whereIn('parent_id', $events->pluck('id'))
            ->get();

        $occurrences = $this->expander->expand($events, $exceptions, $from, $to);

        return response()->json([
            'data' => $occurrences,
            'meta' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'count' => count($occurrences)],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/events',
        summary: 'Create an event or recurring series',
        description: 'Recurrence is stored as a rule and expanded on read. Omit recurrence_frequency for a one-off.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title', 'starts_at'], properties: [new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'location', type: 'string', nullable: true), new OA\Property(property: 'type', type: 'string', enum: ['event', 'meeting', 'reminder', 'deadline']), new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'all_day', type: 'boolean'), new OA\Property(property: 'recurrence_frequency', type: 'string', enum: ['daily', 'weekly', 'monthly', 'yearly'], nullable: true), new OA\Property(property: 'recurrence_interval', type: 'integer', description: 'Every N units'), new OA\Property(property: 'recurrence_by_day', type: 'array', items: new OA\Items(type: 'string'), example: ['MO', 'WE']), new OA\Property(property: 'recurrence_until', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'recurrence_count', type: 'integer', nullable: true, description: 'Stop after N occurrences')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 403, description: 'Lacks calendar.manage'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $event = Event::create([
            ...$this->validateEvent($request),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Event created.',
            'data' => new EventResource($event),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/events/{event}',
        summary: 'One event row with attendees and reminders',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The event'), new OA\Response(response: 403, description: 'Lacks calendar.view'), new OA\Response(response: 404, description: 'Not in this organization')],
    )]
    public function show(Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $event->load(['attendees', 'reminders', 'project:id,name,color']);

        return response()->json(['data' => new EventResource($event)]);
    }

    #[OA\Put(
        path: '/api/v1/events/{event}',
        summary: 'Update an event or series',
        description: 'Updates the whole series. To change one occurrence only, use the occurrence endpoint.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks calendar.manage'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $event->update($this->validateEvent($request, updating: true));

        return response()->json([
            'message' => 'Event updated.',
            'data' => new EventResource($event),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/events/{event}',
        summary: 'Delete an event or series',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 403, description: 'Lacks calendar.manage')],
    )]
    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    /**
     * Cancel or move a single occurrence of a recurring series without touching
     * the rest of it.
     *
     * Creates an exception row keyed to the occurrence's original start, which
     * the expander then skips in the generated series.
     */
    #[OA\Put(
        path: '/api/v1/events/{event}/occurrence',
        summary: 'Cancel or move ONE occurrence of a series',
        description: 'Creates an exception row keyed to the occurrence original start; the expander then skips the generated one. The rest of the series is untouched.',
        security: [['sanctum' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['original_starts_at'], properties: [new OA\Property(property: 'original_starts_at', type: 'string', format: 'date-time', description: 'Identifies which occurrence'), new OA\Property(property: 'cancel', type: 'boolean', description: 'True to remove just this occurrence'), new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, description: 'New time, to move it'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'title', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Occurrence updated or cancelled'), new OA\Response(response: 422, description: 'Not a recurring event'), new OA\Response(response: 403, description: 'Lacks calendar.manage')],
    )]
    public function updateOccurrence(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if (! $event->isRecurring()) {
            return response()->json(['message' => 'This event is not part of a series.'], 422);
        }

        $validated = $request->validate([
            'original_starts_at' => ['required', 'date'],
            'cancel' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $original = Carbon::parse($validated['original_starts_at']);

        $exception = Event::updateOrCreate(
            ['parent_id' => $event->id, 'original_starts_at' => $original],
            [
                'title' => $validated['title'] ?? $event->title,
                'type' => $event->type,
                'color' => $event->color,
                'starts_at' => $validated['starts_at'] ?? $original,
                'ends_at' => $validated['ends_at'] ?? null,
                'is_cancelled' => $request->boolean('cancel'),
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'message' => $request->boolean('cancel') ? 'Occurrence cancelled.' : 'Occurrence updated.',
            'data' => new EventResource($exception),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEvent(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Event::TYPES)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'all_day' => ['sometimes', 'boolean'],

            'recurrence_frequency' => ['nullable', Rule::in(Event::FREQUENCIES)],
            'recurrence_interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'recurrence_by_day' => ['nullable', 'array'],
            'recurrence_by_day.*' => [Rule::in(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'])],
            'recurrence_until' => ['nullable', 'date', 'after:starts_at'],
            'recurrence_count' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);
    }
}
