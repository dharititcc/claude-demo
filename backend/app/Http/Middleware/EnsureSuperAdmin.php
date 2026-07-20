<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to platform super admins.
 *
 * `Gate::before` already grants a super admin every ability, but that only
 * builds the *allow* path — it says nothing about denying everyone else. This
 * middleware is the explicit deny: without it, an admin route would fall through
 * to whatever the individual controller happened to check, and a plain owner
 * could reach the whole platform's data. The gate is the route, stated once,
 * rather than re-derived in every admin action.
 *
 * These routes are also central-context by design: they must NOT sit behind the
 * `tenant` middleware, since they read across every organization rather than
 * booting into one.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_super_admin !== true) {
            // 404, not 403: the existence of a platform admin surface is not
            // something a non-admin needs confirmed. A 403 advertises the route.
            abort(404);
        }

        return $next($request);
    }
}
