<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EmailVerificationController extends Controller
{
    /**
     * Verify an email address from a signed link.
     *
     * The route is signed rather than authenticated: the user clicks the link
     * from their mail client, where no bearer token is present. The signature
     * plus the hash of the current address is what proves ownership.
     */
    #[OA\Get(
        path: '/api/v1/auth/verify-email/{id}/{hash}',
        summary: 'Verify an email address from a signed link',
        description: 'Signed rather than authenticated: the link is clicked from a mail client, where no bearer token exists. The signature plus a hash of the current address proves ownership.',
        tags: ['Auth'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'hash', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Verified (or already verified)'),
            new OA\Response(response: 403, description: 'Invalid link or signature'),
        ],
    )]
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Re-send the verification email to the signed-in user.
     */
    #[OA\Post(
        path: '/api/v1/auth/email/resend',
        summary: 'Re-send the verification email',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Verification link sent'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}
