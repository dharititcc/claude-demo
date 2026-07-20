<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Manage the organization's outbound webhook endpoints.
 *
 * Configuring integrations is an administrative action, so it is gated on the
 * settings permission rather than a per-module one.
 */
class WebhookController extends Controller
{
    /** Events an endpoint may subscribe to. '*' means all. */
    public const EVENTS = [
        '*',
        'customer.created', 'customer.updated', 'customer.deleted',
        'project.created', 'project.updated', 'project.deleted',
        'task.created', 'task.updated', 'task.completed',
    ];

    #[OA\Get(
        path: '/api/v1/webhooks',
        summary: 'List webhook endpoints',
        description: 'Secrets are never returned here: like an API key, the secret is shown once at creation only.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Endpoints plus the list of subscribable events'), new OA\Response(response: 403, description: 'Lacks settings.update')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('update', tenant());

        $endpoints = WebhookEndpoint::latest()->get();

        return response()->json([
            'data' => $endpoints->map(fn (WebhookEndpoint $e) => $this->present($e)),
            'meta' => ['available_events' => self::EVENTS],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/webhooks',
        summary: 'Create a webhook endpoint',
        description: 'Returns the signing secret ONCE. Deliveries are signed with HMAC-SHA256 over the exact body in an X-Signature header, so a receiver can prove the payload is ours.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['url', 'events'], properties: [new OA\Property(property: 'url', type: 'string', format: 'uri'), new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string'), example: ['customer.created'], description: 'Use * for all events')])),
        responses: [new OA\Response(response: 201, description: 'Created; the response includes the secret'), new OA\Response(response: 403, description: 'Lacks settings.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('update', tenant());

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', self::EVENTS)],
        ]);

        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'events' => $validated['events'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Webhook endpoint created.',
            // The secret is returned once, on creation only — like an API key, it
            // is not retrievable afterwards.
            'data' => [...$this->present($endpoint), 'secret' => $endpoint->secret],
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/webhooks/{webhook}',
        summary: 'Update a webhook endpoint',
        description: 'Re-enabling an endpoint that was auto-paused resets its failure count, so it does not pause again on the next failure regardless of the fix.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'webhook', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'url', type: 'string', format: 'uri'), new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks settings.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorize('update', tenant());

        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', self::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Re-enabling a circuit-broken endpoint resets its failure count, or it
        // would pause again on the next failure regardless of the fix.
        if (($validated['is_active'] ?? false) === true && ! $webhook->is_active) {
            $validated['consecutive_failures'] = 0;
        }

        $webhook->update($validated);

        return response()->json([
            'message' => 'Webhook endpoint updated.',
            'data' => $this->present($webhook->refresh()),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/webhooks/{webhook}',
        summary: 'Remove a webhook endpoint',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'webhook', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Removed'), new OA\Response(response: 403, description: 'Lacks settings.update')],
    )]
    public function destroy(WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorize('update', tenant());

        $webhook->delete();

        return response()->json(['message' => 'Webhook endpoint removed.']);
    }

    /**
     * Recent delivery attempts for one endpoint — the debugging view.
     */
    #[OA\Get(
        path: '/api/v1/webhooks/{webhook}/deliveries',
        summary: 'Recent delivery attempts',
        description: 'The debugging view: status code, error, and attempt number for the last 50 deliveries.',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'webhook', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Recent deliveries'), new OA\Response(response: 403, description: 'Lacks settings.update')],
    )]
    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorize('update', tenant());

        $deliveries = $webhook->deliveries()->latest()->limit(50)->get();

        return response()->json([
            'data' => $deliveries->map(fn ($d) => [
                'id' => $d->id,
                'event' => $d->event,
                'status_code' => $d->status_code,
                'success' => $d->success,
                'error' => $d->error,
                'attempt' => $d->attempt,
                'delivered_at' => $d->delivered_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'last_success_at' => $endpoint->last_success_at?->toIso8601String(),
            'last_failure_at' => $endpoint->last_failure_at?->toIso8601String(),
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }
}
