<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization as the Super Admin sees it — profile, owner, plan, a compact
 * subscription summary, and the metrics that are cheap to read centrally.
 *
 * Deliberately distinct from OrganizationResource (the member-facing shape):
 * this one exposes owner contact details and cross-org lifecycle fields that a
 * regular member has no business seeing.
 *
 * @mixin Tenant
 */
class AdminOrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $owner */
        $owner = $this->owners->first();

        $subscription = $this->subscription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'logo' => $this->logo,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'language' => $this->language,

            'owner' => $owner === null ? null : [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ],

            // trial|active|suspended|cancelled, plus the derived "expired" flag
            // for a trial whose clock ran out — the row still reads "trial".
            'status' => $this->status,
            'is_trial_expired' => $this->isTrialExpired(),

            'plan' => $this->plan === null ? null : [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ],

            'subscription' => [
                'status' => $subscription?->stripe_status,
                // Billing cycle, derived by matching the subscription's Stripe
                // price against the plan's monthly/annual price ids — Stripe is
                // the source of truth for which one is actually being charged.
                'interval' => $this->billingInterval($subscription?->stripe_price),
                'on_trial' => $this->isOnTrial(),
                'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
                // Cashier stores the period end in `ends_at` once a cancellation
                // is scheduled; null means an open-ended active subscription.
                'ends_at' => $subscription?->ends_at?->toIso8601String(),
            ],

            'metrics' => [
                // Central pivot count — always available, never from the rollup.
                'total_users' => $this->whenCounted('members'),

                // Denormalised from the tenant database by RefreshOrganizationStats.
                // A missing rollup row (org never refreshed) leaves these null —
                // "not yet measured", which a client must tell apart from a real
                // zero. Present once the hourly job has run.
                'total_customers' => $this->stats?->customers_count,
                'total_projects' => $this->stats?->projects_count,
                'total_tasks' => $this->stats?->tasks_count,
                'total_files' => $this->stats?->files_count,
                'storage_mb' => $this->stats?->storageMb(),
                'last_activity_at' => $this->stats?->last_activity_at?->toIso8601String(),
                'stats_refreshed_at' => $this->stats?->refreshed_at?->toIso8601String(),
            ],

            'registered_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Which billing cycle the given Stripe price corresponds to, using the
     * loaded plan's price ids. Null when there is no subscription, no plan, or
     * the price matches neither (e.g. a legacy price).
     */
    private function billingInterval(?string $stripePrice): ?string
    {
        if ($stripePrice === null || $this->plan === null) {
            return null;
        }

        return match ($stripePrice) {
            $this->plan->stripe_annual_price_id => 'annual',
            $this->plan->stripe_monthly_price_id => 'monthly',
            default => null,
        };
    }
}
