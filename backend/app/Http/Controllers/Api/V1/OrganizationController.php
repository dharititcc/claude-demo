<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * Organizations the caller belongs to — this drives the SPA's org switcher.
     */
    #[OA\Get(
        path: '/api/v1/organizations',
        summary: 'Organizations the caller belongs to',
        description: 'Drives the organization switcher. Needs no X-Organization header.',
        security: [['sanctum' => []]],
        tags: ['Organizations'],
        responses: [new OA\Response(response: 200, description: 'Organizations', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Organization')),
        ]))],
    )]
    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()->organizations()->get();

        return response()->json([
            'data' => OrganizationResource::collection($organizations),
        ]);
    }

    /**
     * Create an additional organization. The caller becomes its owner and a
     * dedicated database is provisioned for it.
     */
    #[OA\Post(
        path: '/api/v1/organizations',
        summary: 'Create an additional organization',
        description: 'Provisions a dedicated database, seeds its roles, and makes the caller its owner.',
        security: [['sanctum' => []]],
        tags: ['Organizations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Second Venture'),
                new OA\Property(property: 'timezone', type: 'string', example: 'Europe/London'),
                new OA\Property(property: 'currency', type: 'string', example: 'GBP'),
                new OA\Property(property: 'language', type: 'string', example: 'en'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Organization'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tenant = $this->organizations->create($request->user(), $validated);

        return response()->json([
            'message' => 'Organization created.',
            'data' => new OrganizationResource($tenant),
        ], 201);
    }

    /**
     * Update the active organization's settings.
     *
     * The slug is deliberately immutable: it is the tenant identifier clients
     * send in X-Organization, so changing it would break every stored reference
     * and any bookmarked link.
     */
    #[OA\Post(
        path: '/api/v1/organization',
        summary: 'Update the active organization settings',
        description: 'POST rather than PUT because the logo is a multipart upload, which PHP does not parse for PUT. The slug is immutable: it is the tenant identifier clients send in X-Organization.',
        security: [['sanctum' => []]],
        tags: ['Organizations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'currency', type: 'string'), new OA\Property(property: 'language', type: 'string'), new OA\Property(property: 'logo', type: 'string', format: 'binary', description: 'Image, max 2 MB')]))),
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 403, description: 'Lacks settings.update'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $this->authorize('update', tenant());

        $validated = $request->validated();

        $tenant = tenant();

        if ($request->hasFile('logo')) {
            // Stored on the tenant-suffixed public disk, so one organization's
            // uploads are never served from another's directory.
            if ($tenant->logo) {
                Storage::disk('public')->delete($tenant->logo);
            }

            $validated['logo'] = $request->file('logo')->store('logos', 'public');
        }

        if (isset($validated['currency'])) {
            $validated['currency'] = mb_strtoupper($validated['currency']);
        }

        $tenant->update($validated);

        return response()->json([
            'message' => 'Organization updated.',
            'data' => new OrganizationResource($tenant->fresh()),
        ]);
    }

    /**
     * The caller's context within the active organization: their role and the
     * permissions the SPA should use to show or hide UI.
     *
     * Runs inside tenant context (see the `tenant` middleware).
     */
    #[OA\Get(
        path: '/api/v1/context',
        summary: 'Your role and permissions in the active organization',
        description: 'The SPA uses the permission list to show or hide controls. The API re-authorizes every request regardless, so this is never the security boundary.',
        security: [['sanctum' => []]],
        tags: ['Organizations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Active context'), new OA\Response(response: 400, description: 'Missing X-Organization header'), new OA\Response(response: 403, description: 'Not a member')],
    )]
    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unsetRelation('roles');

        return response()->json([
            'data' => [
                'organization' => new OrganizationResource(tenant()),
                'role' => $user->roles->first()?->name,
                'permissions' => $user->is_super_admin
                    ? Permission::values()
                    : $user->getAllPermissions()->pluck('name')->values(),
                'is_super_admin' => $user->is_super_admin,
            ],
        ]);
    }
}
