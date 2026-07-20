<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PasswordController extends Controller
{
    /**
     * Change the signed-in user's password.
     *
     * Every other token is revoked so a password change actually evicts an
     * attacker who already holds one; the current device stays signed in.
     */
    #[OA\Put(
        path: '/api/v1/auth/password',
        summary: 'Change your password',
        description: 'Requires the current password. Every other session is revoked on success, so a password change actually evicts an attacker who already holds a token; the calling device stays signed in.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string'),
                new OA\Property(property: 'password', type: 'string'),
                new OA\Property(property: 'password_confirmation', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password updated; other devices signed out'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        $currentTokenId = $user->currentAccessToken()->id;
        $user->tokens()->whereKeyNot($currentTokenId)->delete();

        return response()->json([
            'message' => 'Password updated. Other devices have been signed out.',
        ]);
    }
}
