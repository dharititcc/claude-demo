<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\UsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a create action once the organization has hit its plan limit.
 *
 * Applied per-route (`limit:customers`) rather than globally, because only
 * creation consumes quota — reads, updates, and deletes must keep working when
 * a plan is full, or an organization that hits its ceiling could not even delete
 * records to get back under it.
 *
 * Returns 402 Payment Required rather than 403: this is not an authorization
 * failure, and clients should surface an upgrade prompt, not "access denied".
 */
class EnforcePlanLimit
{
    public function __construct(private readonly UsageService $usage) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $tenant = tenant();

        // Platform staff are not subject to a customer's plan.
        if ($tenant === null || $request->user()?->is_super_admin) {
            return $next($request);
        }

        if (! $this->usage->allows($tenant, $key)) {
            $report = $this->usage->report($tenant)[$key] ?? null;

            return response()->json([
                'message' => "You have reached your plan's {$key} limit. Upgrade to add more.",
                'errors' => [
                    'limit' => [
                        "Limit reached: {$report['used']} of {$report['limit']} {$key} used.",
                    ],
                ],
                'meta' => [
                    'limit_reached' => true,
                    'resource' => $key,
                    'used' => $report['used'] ?? null,
                    'limit' => $report['limit'] ?? null,
                ],
            ], 402);
        }

        return $next($request);
    }
}
