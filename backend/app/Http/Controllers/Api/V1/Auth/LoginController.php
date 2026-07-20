<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Exchange credentials for a Sanctum token.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Sign in',
        description: 'Returns a token, or 202 with a challenge when the account has two-factor enabled. Five failed attempts per email+IP trigger a 15-minute lockout (429).',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'device_name', type: 'string', description: 'Labels the session so it can be reviewed and revoked.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Signed in', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'organizations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Organization')),
                    new OA\Property(property: 'token', type: 'string'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 202, description: 'Two-factor challenge required'),
            new OA\Response(response: 422, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 429, description: 'Locked out after repeated failures'),
        ],
    )]
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attempt(
            $request,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        // Users with 2FA must complete the challenge before a token is issued.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication required.',
                'data' => [
                    'two_factor_required' => true,
                    'challenge_token' => $this->auth->issueTwoFactorChallenge($user),
                ],
            ], 202);
        }

        $user->load('organizations');

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
                'organizations' => OrganizationResource::collection($user->organizations),
                'token' => $this->auth->issueToken($user, $request->deviceName()),
            ],
        ]);
    }

    /**
     * Revoke only the token used for this request, leaving other devices
     * signed in.
     */
    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Sign out of this device',
        description: 'Revokes only the token that made this request, so other devices stay signed in. Use the sessions endpoints to revoke elsewhere.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Logged out'), new OA\Response(response: 401, description: 'Not authenticated')],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
