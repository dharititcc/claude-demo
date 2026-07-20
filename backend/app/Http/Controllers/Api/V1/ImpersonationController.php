<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Services\Admin\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * End an impersonation session.
 *
 * Called by the impersonated session itself (it holds the impersonation token),
 * so this is NOT behind the super-admin gate — the acting user is the target,
 * not the admin. The real admin identity and the org are read off the token.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    #[OA\Post(
        path: '/api/v1/impersonation/stop',
        summary: 'Stop impersonating',
        description: 'Revokes the current impersonation token immediately — before its own expiry would — and records the end of the session. Returns 409 if the current token is an ordinary login rather than an impersonation session.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Impersonation ended; the token is now revoked'),
            new OA\Response(response: 409, description: 'The current session is not an impersonation'),
        ],
    )]
    public function stop(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->isImpersonation()) {
            return response()->json([
                'message' => 'This session is not an impersonation.',
            ], 409);
        }

        $this->impersonation->stop($token);

        return response()->json(['message' => 'Impersonation ended.']);
    }
}
