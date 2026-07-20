<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * Which plan the organization is currently on. Passed in rather than
     * resolved here, so rendering a list of plans costs one lookup instead of
     * one per plan.
     */
    private ?int $currentPlanId = null;

    public function markCurrent(?int $planId): self
    {
        $this->currentPlanId = $planId;

        return $this;
    }

    /**
     * Build a collection with the current plan flagged.
     *
     * @param iterable<int, Plan> $plans
     * @return array<int, self>
     */
    public static function forOrganization(iterable $plans, ?int $currentPlanId): array
    {
        $resources = [];

        foreach ($plans as $plan) {
            $resources[] = (new self($plan))->markCurrent($currentPlanId);
        }

        return $resources;
    }

    /**
     * Stripe price ids are deliberately not exposed: the client has no use for
     * them — Stripe.js works from the publishable key, and the server sends the
     * price id to Stripe itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            // Minor units, for display only. Stripe decides what is charged.
            'monthly_amount' => $this->monthly_amount,
            'annual_amount' => $this->annual_amount,
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,

            'features' => $this->features ?? [],

            // null means unlimited — deliberately distinct from 0 ("none").
            'limits' => [
                'users' => $this->max_users,
                'customers' => $this->max_customers,
                'storage_mb' => $this->max_storage_mb,
            ],

            'is_current' => $this->currentPlanId !== null && $this->id === $this->currentPlanId,
        ];
    }
}
