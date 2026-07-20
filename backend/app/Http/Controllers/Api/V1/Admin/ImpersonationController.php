<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Admin\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Start an impersonation session against an organization.
 *
 * Super-admin only (this sits behind that middleware). The returned token acts
 * as the target user, confined to this one organization and expiring within the
 * hour — see ImpersonationService for the rules.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    #[OA\Post(
        path: '/api/v1/admin/organizations/{organization}/impersonate',
        summary: 'Impersonate a member of an organization',
        description: 'Returns a short-lived token that acts as the target user (an owner by default, or the named member) inside THIS organization only. The token cannot reach the admin API and cannot enter the target user\'s other organizations. A super admin can never be impersonated. Both start and stop are written to the admin audit trail.',
        security: [['sanctum' => []]],
        tags: ['Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'Member to impersonate; defaults to an owner'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Impersonation token issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'token', type: 'string', description: 'Use as the bearer token to act as the user'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 404, description: 'Not a super admin, or unknown organization'),
            new OA\Response(response: 422, description: 'Target is a super admin, not a member, or is yourself', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function start(Request $request, Tenant $organization): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $session = $this->impersonation->start(
            $request->user(),
            $organization,
            $validated['user_id'] ?? null,
        );

        return response()->json([
            'message' => "Impersonating {$session['user']->email}. This session expires at {$session['expires_at']->toIso8601String()}.",
            'data' => [
                'token' => $session['token'],
                'expires_at' => $session['expires_at']->toIso8601String(),
                'user' => [
                    'id' => $session['user']->id,
                    'name' => $session['user']->name,
                    'email' => $session['user']->email,
                ],
                'organization' => ['id' => $organization->id, 'slug' => $organization->slug],
            ],
        ], 201);
    }
}
