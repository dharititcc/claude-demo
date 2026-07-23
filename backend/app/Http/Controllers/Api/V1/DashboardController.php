<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Repositories\CustomerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
                    // What has actually been invoiced and collected, which is a
                    // harder number than lifetime_value — that one is typed in.
                    ...$this->invoiceTotals(),
                ],
                'growth' => $this->growthSeries(),
                'top_customers' => $this->topCustomers(),
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
     * Invoiced, collected and outstanding.
     *
     * Aggregated in SQL rather than by loading invoices: this runs on every
     * dashboard open, and the figures are sums, not rows.
     *
     * Void invoices are excluded everywhere — a cancelled document is not
     * revenue, and counting it would overstate both billed and outstanding.
     *
     * @return array<string, float|int>
     */
    private function invoiceTotals(): array
    {
        $issued = Invoice::whereNot('status', 'void');

        return [
            'invoiced_total' => (float) (clone $issued)->sum('total'),
            'collected_total' => (float) (clone $issued)->sum('amount_paid'),
            // Outstanding is billed minus collected on non-void invoices, which
            // is the same as the sum of their balances.
            'outstanding_total' => (float) (clone $issued)->sum(DB::raw('total - amount_paid')),
            // Overdue is derived from the clock, so it is expressed as a query
            // rather than read from a stored status (see Invoice::scopeOverdue).
            'overdue_total' => (float) Invoice::overdue()->sum(DB::raw('total - amount_paid')),
            'overdue_count' => Invoice::overdue()->count(),
        ];
    }

    /**
     * The customers worth the most, by what they have actually been invoiced.
     *
     * Ranked on invoiced value rather than lifetime_value: the latter is a
     * hand-entered field, so a customer can top the list without ever having
     * been billed. Void invoices are excluded for the same reason as above.
     *
     * @return array<int, array{id: int, name: string, customer_number: string|null, invoiced: float}>
     */
    private function topCustomers(): array
    {
        return Customer::query()
            // The total comes back from the same grouped query. Summing per row
            // afterwards would be five extra round trips for five rows.
            ->selectRaw('customers.id, customers.name, customers.customer_number, SUM(invoices.total) as invoiced')
            ->join('invoices', 'invoices.customer_id', '=', 'customers.id')
            ->whereNot('invoices.status', 'void')
            // Soft-deleted invoices are drafts somebody discarded.
            ->whereNull('invoices.deleted_at')
            ->groupBy('customers.id', 'customers.name', 'customers.customer_number')
            ->orderByDesc('invoiced')
            ->limit(5)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'customer_number' => $c->customer_number,
                'invoiced' => (float) $c->getAttribute('invoiced'),
            ])
            ->all();
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
