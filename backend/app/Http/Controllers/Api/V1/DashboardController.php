<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

/**
 * Dashboard metrics for the active organization.
 *
 * All figures come from the tenant database, so no cross-organization leakage
 * is possible. Results are cached briefly — the cache store is tenant-tagged by
 * stancl's CacheTenancyBootstrapper, so one org can never read another's entry.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly CustomerRepository $customers) {}

    #[OA\Get(
        path: '/api/v1/dashboard',
        summary: 'Aggregate metrics for the active organization',
        description: 'Customer totals and status breakdown, active-customer lifetime value, a zero-filled six-month growth series, and the five most recent customers. Cached for 5 minutes per organization.',
        security: [['sanctum' => []]],
        tags: ['Dashboard'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard metrics'),
            new OA\Response(response: 400, description: 'Missing X-Organization header'),
            new OA\Response(response: 403, description: 'Not a member, or lacks customers.view'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $stats = Cache::remember('dashboard:stats', now()->addMinutes(5), function () {
            $byStatus = $this->customers->countsByStatus();

            return [
                'customers' => [
                    'total' => array_sum($byStatus),
                    'by_status' => $byStatus,
                    'new_this_month' => Customer::where('created_at', '>=', now()->startOfMonth())->count(),
                ],
                'revenue' => [
                    // Lifetime value of customers currently marked active.
                    'lifetime_value' => (float) Customer::where('status', 'active')->sum('lifetime_value'),
                    'currency' => tenant()->currency,
                ],
                'growth' => $this->growthSeries(),
            ];
        });

        $recent = Customer::with('tags')->latest()->limit(5)->get();

        return response()->json([
            'data' => [
                ...$stats,
                'organization' => [
                    'name' => tenant()->name,
                    'status' => tenant()->status,
                    'on_trial' => tenant()->isOnTrial(),
                    'trial_ends_at' => tenant()->trial_ends_at?->toIso8601String(),
                ],
                'recent_customers' => CustomerResource::collection($recent),
            ],
        ]);
    }

    /**
     * New customers per month for the last 6 months, zero-filled so the chart
     * renders a continuous axis even for months with no signups.
     *
     * @return array<int, array{month: string, count: int}>
     */
    private function growthSeries(): array
    {
        $start = now()->startOfMonth()->subMonths(5);

        $counts = Customer::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as aggregate")
            ->groupBy('month')
            ->pluck('aggregate', 'month');

        $series = [];

        for ($i = 0; $i < 6; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $series;
    }
}
