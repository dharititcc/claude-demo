<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FileShare;
use App\Models\Tenant;
use App\Services\FileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public access to shared files. No authentication: anyone with the link may
 * fetch it, subject to the share's own limits (expiry, password, download cap).
 *
 * These routes resolve tenancy manually from the organization slug in the path,
 * because a public visitor sends no bearer token and no X-Organization header —
 * the slug is the only thing that says which tenant database holds the share.
 */
class PublicShareController extends Controller
{
    /** Wrong share-password attempts allowed per share+IP before a temporary lockout. */
    private const MAX_PASSWORD_ATTEMPTS = 5;

    /** Lockout duration after too many wrong share passwords, in seconds. */
    private const PASSWORD_LOCKOUT_SECONDS = 900; // 15 minutes

    public function __construct(private readonly FileManagerService $files) {}

    /**
     * Metadata for a share, so the frontend can render the download page (and
     * prompt for a password when required) before fetching the bytes.
     */
    #[OA\Get(
        path: '/api/v1/public/shares/{organization}/{token}',
        summary: 'Share metadata',
        description: 'Public. Lets the download page render (and prompt for a password when required) before fetching the bytes. Tenancy is resolved from the organization slug in the path.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Filename, size, and whether a password is needed'), new OA\Response(response: 404, description: 'Unknown organization'), new OA\Response(response: 422, description: 'Invalid, expired, or download limit reached')],
    )]
    public function show(string $organization, string $token): JsonResponse
    {
        return $this->withinTenant($organization, function () use ($token) {
            $share = $this->files->resolveShare($token);

            return response()->json([
                'data' => [
                    'filename' => $share->file->name,
                    'size' => $share->file->size,
                    'requires_password' => $share->requiresPassword(),
                    'expires_at' => $share->expires_at?->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * Download the shared file.
     */
    #[OA\Post(
        path: '/api/v1/public/shares/{organization}/{token}/download',
        summary: 'Download a shared file',
        description: 'Public. POST rather than GET so a password can be supplied in the body. Increments the download counter.',
        tags: ['Files'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'password', type: 'string', description: 'Required when the share is password-protected')])),
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 422, description: 'Invalid, expired, limit reached, or wrong password')],
    )]
    public function download(Request $request, string $organization, string $token): StreamedResponse
    {
        return $this->withinTenant($organization, function () use ($request, $token) {
            $share = $this->files->resolveShare($token);

            if ($share->requiresPassword()) {
                $this->assertPasswordAttemptsRemain($share, $request);

                $password = (string) $request->input('password');

                if (! Hash::check($password, (string) $share->password_hash)) {
                    // A public share password can be weak; the 30/min IP throttle
                    // alone leaves thousands of guesses per share per hour. Bound
                    // it to a handful of tries per share+IP before locking out.
                    RateLimiter::hit($this->passwordAttemptKey($share, $request), self::PASSWORD_LOCKOUT_SECONDS);

                    throw ValidationException::withMessages([
                        'password' => __('Incorrect password for this share.'),
                    ]);
                }

                RateLimiter::clear($this->passwordAttemptKey($share, $request));
            }

            // Claim a download slot atomically *before* streaming, so concurrent
            // requests cannot all slip past the cap (isValid() only reads it).
            $this->files->claimDownload($share);

            $file = $share->file;

            return Storage::disk($file->disk)->download($file->path, $file->name);
        });
    }

    /**
     * Refuse further password attempts once a share+IP has burned through its
     * budget, so a weak share password cannot be brute-forced within the wider
     * IP throttle.
     *
     * @throws ValidationException
     */
    private function assertPasswordAttemptsRemain(FileShare $share, Request $request): void
    {
        $key = $this->passwordAttemptKey($share, $request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => __('Too many incorrect password attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ])->status(429);
        }
    }

    private function passwordAttemptKey(FileShare $share, Request $request): string
    {
        return "share-pw:{$share->id}:{$request->ip()}";
    }

    /**
     * Run a callback inside the named organization's tenant context.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    private function withinTenant(string $organization, callable $callback)
    {
        $tenant = Tenant::where('slug', $organization)->orWhere('id', $organization)->first();

        if ($tenant === null) {
            abort(404, 'Share not found.');
        }

        return $tenant->run($callback);
    }
}
