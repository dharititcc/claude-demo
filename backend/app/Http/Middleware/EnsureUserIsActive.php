<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended accounts from using an otherwise-valid token. Without this,
 * revoking access would only take effect once every issued token expired.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is not active. Contact your administrator.',
            ], 403);
        }

        return $next($request);
    }
}
