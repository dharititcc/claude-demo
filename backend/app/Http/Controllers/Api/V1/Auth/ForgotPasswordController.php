<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use OpenApi\Attributes as OA;

class ForgotPasswordController extends Controller
{
    /**
     * Send a password-reset link.
     *
     * The response is deliberately identical whether or not the address exists,
     * so this endpoint cannot be used to enumerate registered users.
     */
    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        summary: 'Request a password-reset link',
        description: 'Always returns the same message whether or not the address is registered, so this cannot be used to enumerate users. Rate limited to 6/minute.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Link sent if the address exists'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email address is registered, a reset link is on its way.',
        ]);
    }
}
