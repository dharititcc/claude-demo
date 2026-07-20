<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MeController extends Controller
{
    /**
     * The authenticated user plus the organizations they can switch between.
     */
    #[OA\Get(
        path: '/api/v1/auth/me',
        summary: 'The authenticated user and the organizations they can switch between',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'The current user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/User'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->load('organizations');

        return response()->json([
            'data' => new UserResource($user),
            // The SPA reads this to show a persistent "you are impersonating"
            // banner and a "stop" button. Present (non-null) only when the
            // current token is an impersonation session — the client cannot infer
            // this from the user object alone, because the user IS the target.
            'impersonation' => $this->impersonationState($request),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function impersonationState(Request $request): ?array
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->isImpersonation()) {
            return null;
        }

        $token->loadMissing('impersonator');

        return [
            'active' => true,
            'impersonator' => $token->impersonator === null ? null : [
                'id' => $token->impersonator->id,
                'name' => $token->impersonator->name,
                'email' => $token->impersonator->email,
            ],
            'organization_id' => $token->impersonated_tenant_id,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }
}
