<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Accepting an invitation.
 *
 * These routes sit outside the `tenant` middleware on purpose: the invitee is
 * not yet a member, so the membership check that middleware performs would
 * reject them before they could ever join.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    /**
     * Preview an invitation from its token.
     *
     * Public: the recipient clicks the emailed link before signing in, and the
     * SPA needs to show which organization they're being invited to (and to
     * which address) so they can log in or register with the right account.
     *
     * Only non-sensitive fields are returned.
     */
    #[OA\Get(
        path: '/api/v1/invitations/{token}',
        summary: 'Preview an invitation',
        description: 'Public: the recipient opens the emailed link before signing in, so the SPA must show which organization invited them and to which address. Returns no sensitive fields.',
        tags: ['Team'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Invitation details'), new OA\Response(response: 422, description: 'Invalid, expired, or already used')],
    )]
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->resolve($token);

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization_name' => $invitation->tenant->name,
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Accept an invitation as the signed-in user.
     */
    #[OA\Post(
        path: '/api/v1/invitations/{token}/accept',
        summary: 'Accept an invitation',
        description: 'Outside tenant scope on purpose: the invitee is not a member yet, so the tenant middleware would reject them before they could join. Acceptance is bound to the invited address.',
        security: [['sanctum' => []]],
        tags: ['Team'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Joined the organization'), new OA\Response(response: 422, description: 'Invalid token, or issued to a different address')],
    )]
    public function accept(Request $request, string $token): JsonResponse
    {
        $tenant = $this->invitations->accept($token, $request->user());

        return response()->json([
            'message' => "You have joined {$tenant->name}.",
            'data' => new OrganizationResource($tenant),
        ]);
    }
}
