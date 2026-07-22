<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Http\Resources\Admin\AdminPlanResource;
use App\Models\Plan;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\PlanAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Super Admin plan master — the subscription catalogue.
 *
 * Central-context like the rest of the admin surface: plans are platform-wide,
 * so no `X-Organization` header and no tenant database is involved. The
 * `super-admin` middleware is the gate; individual actions do not re-check it.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly PlanAdminService $service,
        private readonly AdminAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/plans',
        summary: 'The whole plan catalogue',
        description: 'Super-admin only. Unlike GET /billing/plans, this includes inactive plans and exposes the Stripe price ids, because an administrator maintains the catalogue rather than shopping from it. Each plan carries organizations_count so the UI can tell which plans are in use — a plan in use cannot be deleted.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        responses: [
            new OA\Response(response: 200, description: 'Plans ordered by sort_order'),
            new OA\Response(response: 404, description: 'Not a super admin (the admin surface is not advertised)'),
        ],
    )]
    public function index(): JsonResponse
    {
        $plans = $this->service->catalogue();

        return response()->json([
            'data' => AdminPlanResource::collectionWithCounts($plans, $this->service->subscriberCounts()),
            'meta' => ['count' => $plans->count()],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/admin/plans/{plan}',
        summary: 'One plan',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'plan', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The plan'),
            new OA\Response(response: 404, description: 'Unknown plan, or not a super admin'),
        ],
    )]
    public function show(Plan $plan): JsonResponse
    {
        return response()->json([
            'data' => (new AdminPlanResource($plan))
                ->withOrganizationsCount($this->service->subscriberCounts()[$plan->id] ?? 0),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/plans',
        summary: 'Add a plan to the catalogue',
        description: 'The slug is derived from the name when omitted, and is the handle billing/subscribe takes. Stripe price ids are optional so a plan can be drafted before its Stripe prices exist — but an interval with no price id cannot be subscribed to, which the response reports as stripe.monthly_ready / stripe.annual_ready. A price id that IS supplied is verified against Stripe (exists, active, recurring, right interval, right currency) and the display amount is then taken from Stripe rather than the submitted value, because Stripe is the source of truth for money. Verification is skipped when no Stripe secret is configured.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'currency'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'Derived from name when omitted'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'stripe_monthly_price_id', type: 'string', nullable: true),
            new OA\Property(property: 'stripe_annual_price_id', type: 'string', nullable: true),
            new OA\Property(property: 'monthly_amount', type: 'integer', description: 'Minor units, display only'),
            new OA\Property(property: 'annual_amount', type: 'integer', description: 'Minor units, display only'),
            new OA\Property(property: 'currency', type: 'string', example: 'USD'),
            new OA\Property(property: 'trial_days', type: 'integer'),
            new OA\Property(property: 'max_users', type: 'integer', nullable: true, description: 'null = unlimited, 0 = none allowed'),
            new OA\Property(property: 'max_customers', type: 'integer', nullable: true),
            new OA\Property(property: 'max_storage_mb', type: 'integer', nullable: true),
            new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'sort_order', type: 'integer'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 404, description: 'Not a super admin'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = $this->service->create($request->validated());

        $this->audit->plan($request->user(), 'plan.created', $plan, "Plan '{$plan->name}' added to the catalogue.");

        return response()->json([
            'message' => 'Plan created.',
            'data' => new AdminPlanResource($plan),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/admin/plans/{plan}',
        summary: 'Edit a plan',
        description: 'A partial edit: an absent key is left untouched, while a key sent as null clears it. That distinction matters for the limits, where null means unlimited and 0 means none allowed. Limits take effect on the next request — UsageService reads them per request rather than caching them onto the organization.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'plan', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Unknown plan, or not a super admin'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $validated = $request->validated();

        $plan = $this->service->update($plan, $validated);

        $this->audit->plan(
            $request->user(),
            'plan.updated',
            $plan,
            'Plan edited.',
            // The diff, not the whole model.
            ['changed' => array_keys($validated)],
        );

        return response()->json([
            'message' => 'Plan updated.',
            'data' => (new AdminPlanResource($plan))
                ->withOrganizationsCount($this->service->subscriberCounts()[$plan->id] ?? 0),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/admin/plans/{plan}',
        summary: 'Remove a plan from the catalogue',
        description: 'Refused with 422 while any organization is on the plan. tenants.plan_id carries no foreign key, so deleting would leave those rows pointing at a plan that no longer exists, and UsageService would silently fall back to the cheapest active plan — re-quotaing them without telling anyone. Deactivate instead: the plan stops being offered while current subscribers keep their limits.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'plan', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Unknown plan, or not a super admin'),
            new OA\Response(response: 422, description: 'The plan is still in use', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        // Snapshot before the row goes: the audit entry needs the name.
        $name = $plan->name;

        $this->service->delete($plan);

        $this->audit->plan($request->user(), 'plan.deleted', $plan, "Plan '{$name}' removed from the catalogue.");

        return response()->json(['message' => 'Plan deleted.']);
    }
}
