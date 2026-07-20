<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization for a tenant-scoped API request and boots
 * tenancy for it.
 *
 * The organization is taken from the `X-Organization` header (slug or UUID).
 * Membership is enforced here — this is the boundary that stops a user from
 * reading another organization's database by simply changing the header.
 *
 * Runs *after* `auth:sanctum` so the bearer token is still resolved against the
 * central database before the connection is swapped to the tenant.
 */
class InitializeTenancyForUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $request->header('X-Organization');

        if (blank($identifier)) {
            return response()->json([
                'message' => 'Organization context required. Send an X-Organization header.',
            ], 400);
        }

        $tenant = Tenant::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if ($tenant === null) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $user = $request->user();

        // An impersonation token is confined to the single organization it was
        // issued for, even though its target user may belong to others. This is
        // checked before membership so an impersonation cannot slip sideways into
        // another of the target's orgs by changing the header.
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->isImpersonation()
            && $token->impersonated_tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'This impersonation session is limited to a different organization.',
            ], 403);
        }

        // Super admins may act across organizations; everyone else must be a member.
        if (! $user->is_super_admin && ! $user->belongsToOrganization($tenant->id)) {
            return response()->json(['message' => 'You do not belong to this organization.'], 403);
        }

        if ($tenant->status === 'suspended') {
            return response()->json(['message' => 'This organization is suspended.'], 403);
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }

    /**
     * Revert to the central context once the response is sent.
     *
     * Without this the swapped connection outlives the request. That is
     * invisible on a traditional worker (the process dies), but under Octane —
     * and in the test harness, which reuses the container the same way — the
     * next request would begin with a previous organization's database still the
     * default. Ending tenancy here makes each request start clean.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
