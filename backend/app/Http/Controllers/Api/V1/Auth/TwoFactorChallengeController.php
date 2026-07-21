<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The second half of a two-factor sign-in.
 *
 * Login stops at a 202 and a challenge handle when the account has 2FA; this is
 * where that handle plus a code becomes a real API token. Public by necessity —
 * the caller has proven their password but holds no token yet, which is exactly
 * the state this endpoint exists to resolve.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/2fa/challenge',
        summary: 'Complete a two-factor sign-in',
        description: 'Exchanges the challenge_token from a 202 login, plus either a TOTP code or a recovery code, for an API token. Send one of code or recovery_code. A wrong code does not burn the challenge, but five wrong codes destroy it and sign-in restarts from the password. A TOTP code works only once: its time step is recorded and re-presenting it is refused, so observing a code is not enough to reuse it inside its 30-second window.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_token'],
            properties: [
                new OA\Property(property: 'challenge_token', type: 'string', description: 'From the 202 login response'),
                new OA\Property(property: 'code', type: 'string', description: 'Six digits from the authenticator'),
                new OA\Property(property: 'recovery_code', type: 'string', description: 'A single-use recovery code; destroyed once spent'),
                new OA\Property(property: 'device_name', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'organizations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Organization')),
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'recovery_codes_remaining', type: 'integer', description: 'Present when a recovery code was used, to prompt regeneration'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 422, description: 'Bad code, or an expired or already-spent challenge', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
            new OA\Response(response: 429, description: 'Too many attempts from this address'),
        ],
    )]
    public function __invoke(TwoFactorChallengeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $challenge = $validated['challenge_token'];
        $user = $this->auth->resolveTwoFactorChallenge($challenge);

        $usedRecoveryCode = false;

        if (! empty($validated['code'])) {
            $passed = $this->twoFactor->verify($user, $validated['code']);
        } else {
            $passed = $this->twoFactor->useRecoveryCode($user, (string) $validated['recovery_code']);
            $usedRecoveryCode = $passed;
        }

        if (! $passed) {
            $this->auth->recordFailedTwoFactorAttempt($challenge);

            throw ValidationException::withMessages([
                'code' => __('That code is not valid.'),
            ]);
        }

        // Spend the handle only now, so a mistyped digit costs an attempt rather
        // than the whole sign-in.
        $this->auth->completeTwoFactorChallenge($challenge);

        $user->load('organizations');

        $data = [
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations),
            'token' => $this->auth->issueToken(
                $user,
                $validated['device_name'] ?? 'api',
            ),
        ];

        // Surface the dwindling count: someone signing in on recovery codes has
        // lost their authenticator and needs to know how many are left.
        if ($usedRecoveryCode) {
            $data['recovery_codes_remaining'] = count($user->two_factor_recovery_codes ?? []);
        }

        return response()->json(['message' => 'Login successful.', 'data' => $data]);
    }
}
