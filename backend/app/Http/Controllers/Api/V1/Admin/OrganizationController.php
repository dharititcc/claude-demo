<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexOrganizationRequest;
use App\Http\Requests\Admin\SetLimitsOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\Admin\AdminOrganizationResource;
use App\Models\Tenant;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\OrganizationAdminService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Super Admin organization management.
 *
 * Every route here is central-context — no `X-Organization` header, not behind
 * the `tenant` middleware — because it reads across all organizations rather
 * than booting into one. The `super-admin` middleware is the gate; individual
 * actions do not re-check it.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationAdminService $service,
        private readonly AdminAudit $audit,
        private readonly UsageService $usage,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/organizations',
        summary: 'List every organization',
        description: 'Super-admin only. Central-context: reads across all organizations without entering any tenant database. Searchable by organization name, phone, or owner name/email; filterable by status, plan, and registration date; sortable on an allowlist. Cross-tenant metrics (projects, storage) are null until the Phase 2 stats rollup lands.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', description: 'Organization name, phone, or owner name/email', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['trial', 'active', 'suspended', 'cancelled'])),
            new OA\Parameter(name: 'plan', in: 'query', description: 'Plan id or slug', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', description: 'Registered on/after (ISO date)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', description: 'Registered on/before (ISO date)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'name|status|created_at|members_count; prefix - to reverse', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated organizations'),
            new OA\Response(response: 404, description: 'Not a super admin (the admin surface is not advertised)'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function index(IndexOrganizationRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $page = $this->service->paginate($filters);

        return AdminOrganizationResource::collection($page->getCollection())
            ->additional([
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ])
            ->response();
    }

    #[OA\Get(
        path: '/api/v1/admin/organizations/stats',
        summary: 'Platform-wide dashboard counters',
        description: 'Super-admin only. Organization counts by lifecycle state plus distinct users across the platform. Cross-tenant totals (projects, storage) are null pending the Phase 2 rollup — null means not-yet-measured, distinct from a real zero.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        responses: [new OA\Response(response: 200, description: 'Counters'), new OA\Response(response: 404, description: 'Not a super admin')],
    )]
    public function stats(): JsonResponse
    {
        return response()->json(['data' => $this->service->stats()]);
    }

    #[OA\Get(
        path: '/api/v1/admin/organizations/{organization}',
        summary: 'One organization in full',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Organization detail'), new OA\Response(response: 404, description: 'Not a super admin, or unknown organization')],
    )]
    public function show(Tenant $organization): JsonResponse
    {
        return response()->json([
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
            // Usage vs plan vs override, for the detail screen. Computed here
            // rather than in the resource so the LIST endpoint (many orgs) never
            // pays this per-org tenant-database round-trip.
            'limits' => $this->usage->adminLimits($organization),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/admin/organizations/{organization}/limits',
        summary: 'Override an organization\'s plan limits',
        description: 'Give one organization different ceilings than its plan without inventing a bespoke plan. The body replaces the whole override map: a key present overrides the plan (an integer, or null for unlimited); a key absent falls back to the plan. Send an empty object to clear all overrides. All quota enforcement — uploads, record creation — honours these immediately.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'overrides', type: 'object', properties: [
                new OA\Property(property: 'users', type: 'integer', nullable: true, description: 'null = unlimited'),
                new OA\Property(property: 'customers', type: 'integer', nullable: true),
                new OA\Property(property: 'storage_mb', type: 'integer', nullable: true),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Overrides applied'),
            new OA\Response(response: 404, description: 'Not a super admin, or unknown organization'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function limits(SetLimitsOrganizationRequest $request, Tenant $organization): JsonResponse
    {
        $validated = $request->validated();

        // An empty overrides object clears everything — but Laravel's validated()
        // omits the key entirely when the array is empty, so default it.
        $organization = $this->service->setLimits($organization, $validated['overrides'] ?? []);

        $this->audit->organization(
            $request->user(),
            'organization.limits.updated',
            $organization,
            'Limit overrides changed.',
            ['overrides' => $organization->limit_overrides],
        );

        return response()->json([
            'message' => 'Limits updated.',
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
            'limits' => $this->usage->adminLimits($organization),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/admin/organizations/{organization}',
        summary: 'Edit an organization profile',
        description: 'The slug is intentionally not editable — it is the identifier clients send in X-Organization, and changing it would break every integration. Status changes go through the suspend/activate endpoints, not this one.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'timezone', type: 'string'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'language', type: 'string'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 404, description: 'Not a super admin, or unknown organization'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(UpdateOrganizationRequest $request, Tenant $organization): JsonResponse
    {
        $validated = $request->validated();

        $organization = $this->service->update($organization, $validated);

        $this->audit->organization(
            $request->user(),
            'organization.updated',
            $organization,
            'Profile edited.',
            // Record only the fields that were actually submitted — the diff, not
            // the whole model.
            ['changed' => array_keys($validated)],
        );

        return response()->json([
            'message' => 'Organization updated.',
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/organizations/{organization}/suspend',
        summary: 'Suspend an organization',
        description: 'Cuts access immediately: the tenant middleware refuses a suspended organization on its next request, so no token needs to expire. Reversible via activate.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Suspended'), new OA\Response(response: 404, description: 'Not a super admin, or unknown organization')],
    )]
    public function suspend(Request $request, Tenant $organization): JsonResponse
    {
        $organization = $this->service->suspend($organization);

        $this->audit->organization($request->user(), 'organization.suspended', $organization);

        return response()->json([
            'message' => 'Organization suspended.',
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/organizations/{organization}/activate',
        summary: 'Reactivate a suspended organization',
        description: 'Restores to active, never to trial — reviving a spent trial would grant a second free run.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Activated'), new OA\Response(response: 404, description: 'Not a super admin, or unknown organization')],
    )]
    public function activate(Request $request, Tenant $organization): JsonResponse
    {
        $organization = $this->service->activate($organization);

        $this->audit->organization($request->user(), 'organization.activated', $organization);

        return response()->json([
            'message' => 'Organization activated.',
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/admin/organizations/{organization}',
        summary: 'Soft-delete an organization',
        description: 'Reversible. The central row is trashed — which cuts member access immediately — but the physical tenant database is KEPT, so the org can be restored. The database is only ever dropped later, by the tenants:purge command, after a retention window. A "soft delete" here never destroys data.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Soft-deleted'), new OA\Response(response: 404, description: 'Not a super admin, or unknown organization')],
    )]
    public function destroy(Request $request, Tenant $organization): JsonResponse
    {
        $this->service->softDelete($organization);

        $this->audit->organization($request->user(), 'organization.deleted', $organization, 'Soft-deleted; database retained pending purge.');

        return response()->json(['message' => 'Organization deleted. It can be restored until it is purged.']);
    }

    #[OA\Post(
        path: '/api/v1/admin/organizations/{organization}/restore',
        summary: 'Restore a soft-deleted organization',
        description: 'Brings a trashed organization and its members back online. Because the database was never dropped, nothing is re-provisioned — the data is exactly as it was left.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Restored'), new OA\Response(response: 404, description: 'Not a super admin, or no such trashed organization')],
    )]
    public function restore(Request $request, Tenant $organization): JsonResponse
    {
        // Nothing to do if it was not actually trashed — restore is idempotent.
        $organization = $this->service->restore($organization);

        $this->audit->organization($request->user(), 'organization.restored', $organization);

        return response()->json([
            'message' => 'Organization restored.',
            'data' => new AdminOrganizationResource($this->service->detail($organization)),
        ]);
    }
}
