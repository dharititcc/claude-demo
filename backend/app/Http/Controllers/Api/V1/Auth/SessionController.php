<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginHistoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SessionController extends Controller
{
    /**
     * List active sessions (issued tokens) so a user can spot unfamiliar
     * devices and revoke them.
     */
    #[OA\Get(
        path: '/api/v1/auth/sessions',
        summary: 'Active sessions (issued tokens)',
        description: 'Lets a user spot unfamiliar devices and revoke them. The current session is flagged.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Sessions, most recently used first')],
    )]
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()->tokens()
            ->latest('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'current' => $token->id === $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    /**
     * Revoke a single session. Scoped to the caller's own tokens so one user
     * cannot sign another out.
     */
    #[OA\Delete(
        path: '/api/v1/auth/sessions/{id}',
        summary: 'Revoke one session',
        description: 'Scoped to your own tokens, so one user cannot sign another out.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Session revoked'),
            new OA\Response(response: 404, description: 'Not one of your sessions'),
        ],
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        $token->delete();

        return response()->json(['message' => 'Session revoked.']);
    }

    /**
     * Revoke every session except the current one.
     */
    #[OA\Delete(
        path: '/api/v1/auth/sessions/others',
        summary: 'Revoke every session except the current one',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Other sessions revoked')],
    )]
    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;
        $count = $request->user()->tokens()->whereKeyNot($currentId)->delete();

        return response()->json(['message' => "Revoked {$count} other session(s)."]);
    }

    /**
     * Recent authentication attempts for the signed-in user.
     */
    #[OA\Get(
        path: '/api/v1/auth/login-history',
        summary: 'Recent authentication attempts',
        description: 'Successes and failures for the signed-in user. Paginated.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Paginated login history')],
    )]
    public function loginHistory(Request $request): JsonResponse
    {
        $history = $request->user()->loginHistories()
            ->latest('attempted_at')
            ->paginate(20);

        return LoginHistoryResource::collection($history)->response();
    }
}
