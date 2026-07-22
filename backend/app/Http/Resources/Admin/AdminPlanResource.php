<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the Super Admin sees it.
 *
 * Deliberately separate from the customer-facing PlanResource, which hides the
 * Stripe price ids and only ever shows active plans. An administrator manages
 * the catalogue, so this exposes the billing wiring, the inactive plans, and
 * how many organizations each plan would affect.
 *
 * @mixin Plan
 */
class AdminPlanResource extends JsonResource
{
    /**
     * How many organizations are on this plan. Passed in rather than counted
     * here so rendering the catalogue costs one grouped query instead of one
     * per plan (the same reasoning as PlanResource::markCurrent).
     */
    private int $organizationsCount = 0;

    public function withOrganizationsCount(int $count): self
    {
        $this->organizationsCount = $count;

        return $this;
    }

    /**
     * Build a collection with subscriber counts attached.
     *
     * @param iterable<int, Plan> $plans
     * @param array<int, int> $counts Keyed by plan id.
     * @return array<int, self>
     */
    public static function collectionWithCounts(iterable $plans, array $counts): array
    {
        $resources = [];

        foreach ($plans as $plan) {
            $resources[] = (new self($plan))->withOrganizationsCount($counts[$plan->id] ?? 0);
        }

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            // Minor units, for display only — Stripe decides what is charged.
            'monthly_amount' => $this->monthly_amount,
            'annual_amount' => $this->annual_amount,
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,

            // null means unlimited — deliberately distinct from 0 ("none allowed").
            'limits' => [
                'users' => $this->max_users,
                'customers' => $this->max_customers,
                'storage_mb' => $this->max_storage_mb,
            ],

            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            /**
             * The billing wiring, and whether each interval is actually usable.
             *
             * A plan with no price id for an interval cannot be subscribed to —
             * BillingService rejects it with "not available on a :interval
             * basis". Surfacing the flag here means an administrator can see
             * that from the catalogue instead of discovering it at checkout.
             */
            'stripe' => [
                'monthly_price_id' => $this->stripe_monthly_price_id,
                'annual_price_id' => $this->stripe_annual_price_id,
                'monthly_ready' => filled($this->stripe_monthly_price_id),
                'annual_ready' => filled($this->stripe_annual_price_id),
            ],

            // Drives the delete guard in the UI: a plan in use cannot be removed.
            'organizations_count' => $this->organizationsCount,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
