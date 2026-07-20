<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Manage the signed-in user's second factor.
 *
 * Enrolment is two steps on purpose (see TwoFactorService): enable() hands out a
 * secret, and the account is only actually protected once confirm() proves the
 * authenticator produces matching codes.
 *
 * Turning 2FA off or rolling the recovery codes re-checks the password. These
 * endpoints sit behind a bearer token, and a stolen token must not be enough to
 * strip the factor that exists to survive a stolen password.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    #[OA\Get(
        path: '/api/v1/auth/2fa',
        summary: 'Two-factor status',
        description: 'Reports whether enrolment is unstarted, pending confirmation, or active, and how many recovery codes remain. The secret itself is never returned here — only the enable step ever discloses it.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [new OA\Response(response: 200, description: 'Current two-factor state')],
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                // A secret with no confirmation means enrolment was started and
                // abandoned; the account is not protected yet.
                'pending_confirmation' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/enable',
        summary: 'Begin two-factor enrolment',
        description: 'Returns a secret and an otpauth:// URI to render as a QR code. This does NOT protect the account yet — call confirm with a code from the authenticator to finish. Calling this again discards any half-finished enrolment and issues a fresh secret, so an abandoned QR code cannot be confirmed later.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Secret and provisioning URI', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'secret', type: 'string', description: 'Base32 secret, for manual entry'),
                    new OA\Property(property: 'otpauth_uri', type: 'string', description: 'Render as a QR code'),
                ], type: 'object'),
            ])),
            new OA\Response(response: 409, description: 'Already enabled; disable it first'),
        ],
    )]
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Re-enrolling in place would swap the secret out from under a working
        // authenticator while the account stays protected by the old one.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled. Disable it before enrolling again.',
            ], 409);
        }

        return response()->json(['data' => $this->twoFactor->enable($user)]);
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/confirm',
        summary: 'Finish enrolment and get recovery codes',
        description: 'Proves the authenticator works, and only then does 2FA start guarding sign-in. Recovery codes are returned here and are shown in full exactly once at this point; they are the way back in when the device is lost.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [
            new OA\Property(property: 'code', type: 'string', description: 'Six digits from the authenticator'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Enabled; recovery codes returned'),
            new OA\Response(response: 422, description: 'Wrong code, or enrolment was never started', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $codes = $this->twoFactor->confirm($request->user(), $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check your authenticator and try again.'),
            ]);
        }

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'data' => ['recovery_codes' => $codes],
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/auth/2fa',
        summary: 'Disable two-factor authentication',
        description: 'Requires the account password: a stolen bearer token must not be enough to remove the factor that exists to survive a stolen password. Clears the secret and every recovery code.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['password'], properties: [
            new OA\Property(property: 'password', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Disabled'),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $this->requirePassword($request);

        $this->twoFactor->disable($request->user());

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    #[OA\Get(
        path: '/api/v1/auth/2fa/recovery-codes',
        summary: 'View remaining recovery codes',
        description: 'Codes are stored encrypted rather than hashed precisely so they can be shown again here — a user who lost the list but still has their authenticator would otherwise have no way to recover one.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Unused recovery codes'),
            new OA\Response(response: 409, description: 'Two-factor is not enabled'),
        ],
    )]
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled.',
            ], 409);
        }

        return response()->json([
            'data' => ['recovery_codes' => $user->two_factor_recovery_codes ?? []],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/2fa/recovery-codes',
        summary: 'Replace the recovery codes',
        description: 'Issues a fresh set and invalidates every previous code — the correct move once a list has been printed, shared, or partially spent. Requires the password.',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['password'], properties: [
            new OA\Property(property: 'password', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'New codes issued; old ones are dead'),
            new OA\Response(response: 409, description: 'Two-factor is not enabled'),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled.',
            ], 409);
        }

        $this->requirePassword($request);

        return response()->json([
            'message' => 'Recovery codes regenerated.',
            'data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function requirePassword(Request $request): void
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $this->twoFactor->confirmPassword($request->user(), $validated['password'])) {
            throw ValidationException::withMessages([
                'password' => __('That password is incorrect.'),
            ]);
        }
    }
}
