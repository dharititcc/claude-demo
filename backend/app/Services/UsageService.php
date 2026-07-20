<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Reports and enforces plan usage limits for an organization.
 *
 * Counts come from the tenant's own database (customers, storage) and the
 * central pivot (members), so this class deliberately crosses both.
 */
class UsageService
{
    /**
     * Current usage for an organization.
     *
     * @return array<string, int>
     */
    public function current(Tenant $tenant): array
    {
        // Members live centrally; customers and files live in the tenant DB.
        $users = $tenant->members()->count();

        [$customers, $storageBytes] = $tenant->run(fn () => [
            Customer::count(),
            (int) Attachment::sum('size'),
        ]);

        return [
            'users' => $users,
            'customers' => $customers,
            // Round up: 0.4 MB used against a 1 MB plan is still 1 MB consumed
            // as far as the customer is concerned, and rounding down would let
            // usage sit permanently at "0 of 0".
            'storage_mb' => (int) ceil($storageBytes / 1_048_576),
        ];
    }

    /**
     * Usage, limits, and remaining headroom — what the billing UI renders.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null, exceeded: bool}>
     */
    public function report(Tenant $tenant): array
    {
        $usage = $this->current($tenant);
        $plan = $this->planFor($tenant);

        $report = [];

        foreach ($usage as $key => $used) {
            // Effective limit: a per-org override wins over the plan (see
            // effectiveLimit). The member-facing UI therefore shows the real
            // ceiling this org is held to, not the plan's default.
            $limit = $this->effectiveLimit($tenant, $plan, $key);

            $report[$key] = [
                'used' => $used,
                // null = unlimited. Distinct from 0, which would mean none allowed.
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'exceeded' => $limit !== null && $used >= $limit,
            ];
        }

        return $report;
    }

    /**
     * Whether the organization may add one more of something.
     *
     * Super admins and unlimited plans always pass. An organization with no plan
     * falls back to the free tier's limits rather than being unlimited — failing
     * open here would let anyone bypass billing by simply never subscribing.
     */
    public function allows(Tenant $tenant, string $key, int $additional = 1): bool
    {
        $limit = $this->effectiveLimit($tenant, $this->planFor($tenant), $key);

        if ($limit === null) {
            return true; // unlimited
        }

        return ($this->current($tenant)[$key] ?? 0) + $additional <= $limit;
    }

    /**
     * The limit actually in force for a key: a per-organization override if one
     * is set, otherwise the plan's.
     *
     * A key PRESENT in the overrides map wins even when its value is null — that
     * is an explicit "unlimited for this org". Only an ABSENT key falls through
     * to the plan. Conflating the two would make "unlimited override" impossible
     * to express.
     */
    public function effectiveLimit(Tenant $tenant, ?Plan $plan, string $key): ?int
    {
        $overrides = $tenant->limit_overrides ?? [];

        if (array_key_exists($key, $overrides)) {
            $value = $overrides[$key];

            return $value === null ? null : (int) $value;
        }

        return $plan?->limitFor($key);
    }

    /**
     * A breakdown for the admin screen: usage, the plan default, any override,
     * and the effective ceiling — enough to show "50 (plan 5, overridden)".
     *
     * @return array<string, array{used: int, plan_limit: int|null, has_override: bool, override: int|null, effective_limit: int|null, exceeded: bool}>
     */
    public function adminLimits(Tenant $tenant): array
    {
        $usage = $this->current($tenant);
        $plan = $this->planFor($tenant);
        $overrides = $tenant->limit_overrides ?? [];

        $detail = [];

        foreach ($usage as $key => $used) {
            $hasOverride = array_key_exists($key, $overrides);
            $effective = $this->effectiveLimit($tenant, $plan, $key);

            $detail[$key] = [
                'used' => $used,
                'plan_limit' => $plan?->limitFor($key),
                'has_override' => $hasOverride,
                'override' => $hasOverride ? ($overrides[$key] === null ? null : (int) $overrides[$key]) : null,
                'effective_limit' => $effective,
                'exceeded' => $effective !== null && $used >= $effective,
            ];
        }

        return $detail;
    }

    /**
     * The plan governing this organization's limits.
     *
     * Falls back to the cheapest active plan when the organization has none,
     * so limits are always enforced rather than silently unlimited.
     */
    public function planFor(Tenant $tenant): ?Plan
    {
        if ($tenant->plan_id !== null) {
            $plan = Plan::find($tenant->plan_id);

            if ($plan !== null) {
                return $plan;
            }
        }

        return Plan::active()->first();
    }
}
