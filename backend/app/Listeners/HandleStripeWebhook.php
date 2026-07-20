<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Reconciles local state with Stripe.
 *
 * Cashier already maintains the `subscriptions` table itself. This listener
 * handles what Cashier does not know about: which of *our* plans a subscription
 * maps to, and the current period end we cache for display.
 *
 * Webhooks are the only way we learn about changes made outside the app — a card
 * expiring, a renewal, a plan changed from Stripe's dashboard, a dunning
 * cancellation. Without them, `tenants.plan_id` drifts and we enforce the wrong
 * limits.
 */
class HandleStripeWebhook
{
    public function __construct(private readonly BillingService $billing) {}

    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? '';

        match ($type) {
            'customer.subscription.created',
            'customer.subscription.updated' => $this->syncSubscription($event->payload),
            'customer.subscription.deleted' => $this->subscriptionEnded($event->payload),
            default => null, // Cashier handles the rest; ignore quietly.
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function syncSubscription(array $payload): void
    {
        $data = $payload['data']['object'] ?? [];
        $stripeId = $data['id'] ?? null;

        if ($stripeId === null) {
            return;
        }

        // Cashier creates the row; we may receive the webhook first, so tolerate
        // it being absent rather than throwing inside a webhook.
        $subscription = Subscription::where('stripe_id', $stripeId)->first();

        if ($subscription === null) {
            return;
        }

        if (isset($data['current_period_end'])) {
            $subscription->forceFill([
                'current_period_end' => now()->setTimestamp((int) $data['current_period_end']),
            ])->save();
        }

        // The price may have been changed in Stripe's dashboard. Stripe is
        // authoritative, so re-derive our plan pointer from it rather than
        // assuming our local value is still right.
        $priceId = $data['items']['data'][0]['price']['id'] ?? null;

        if ($priceId !== null) {
            $plan = Plan::where('stripe_monthly_price_id', $priceId)
                ->orWhere('stripe_annual_price_id', $priceId)
                ->first();

            $tenant = Tenant::find($subscription->tenant_id);

            if ($tenant !== null && $plan !== null && $tenant->plan_id !== $plan->id) {
                Log::info('Stripe changed the plan for an organization; syncing.', [
                    'tenant' => $tenant->id,
                    'from' => $tenant->plan_id,
                    'to' => $plan->id,
                ]);

                $this->billing->syncPlan($tenant, $plan);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function subscriptionEnded(array $payload): void
    {
        $stripeId = $payload['data']['object']['id'] ?? null;
        $subscription = Subscription::where('stripe_id', $stripeId)->first();
        $tenant = $subscription !== null ? Tenant::find($subscription->tenant_id) : null;

        if ($tenant === null) {
            return;
        }

        // Fall back to the free tier rather than leaving the organization on a
        // paid plan's limits after Stripe stopped charging for it — including
        // when the end came from dunning rather than a deliberate cancellation.
        $free = Plan::active()->first();

        $this->billing->syncPlan($tenant, $free);
        $tenant->forceFill(['status' => 'active'])->save();

        Log::info('Subscription ended; organization moved to the free tier.', [
            'tenant' => $tenant->id,
        ]);
    }
}
