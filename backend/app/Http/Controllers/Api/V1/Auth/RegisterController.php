<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RegisterController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Register a user together with their first organization, and return an
     * API token so the SPA can proceed straight to the dashboard.
     */
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a user and create their first organization',
        description: 'Creates the user, provisions a dedicated database for the organization, seeds its roles, makes the caller its owner, and returns an API token. Rate limited to 6/minute.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation', 'organization_name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ada Lovelace'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', description: 'Min 12 chars with mixed case, a number, and a symbol.'),
                    new OA\Property(property: 'password_confirmation', type: 'string'),
                    new OA\Property(property: 'organization_name', type: 'string', example: 'Acme Inc'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'UTC'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Registered', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'organization', ref: '#/components/schemas/Organization'),
                    new OA\Property(property: 'token', type: 'string'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        ['user' => $user, 'tenant' => $tenant] = $this->auth->register($request->validated());

        $token = $this->auth->issueToken(
            $user,
            $request->input('device_name', 'registration'),
        );

        return response()->json([
            'message' => 'Registration successful. Please verify your email address.',
            'data' => [
                'user' => new UserResource($user),
                'organization' => new OrganizationResource($tenant),
                'token' => $token,
            ],
        ], 201);
    }
}
