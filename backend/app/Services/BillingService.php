<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * Subscription lifecycle for an organization.
 *
 * Stripe is the source of truth for money and subscription state; this class
 * never computes an amount or infers a status locally. `tenants.plan_id` is a
 * local convenience for limit lookups, kept in step with Stripe via the price id
 * on the subscription (and by webhooks for changes made in Stripe's dashboard).
 */
class BillingService
{
    /** Cashier subscription name. Single-product app, so one fixed name. */
    public const SUBSCRIPTION = 'default';

    /**
     * Start a subscription.
     *
     * @throws ValidationException
     */
    public function subscribe(
        Tenant $tenant,
        Plan $plan,
        string $interval,
        ?string $paymentMethod = null,
        ?string $coupon = null,
    ): Subscription {
        $priceId = $this->priceIdOrFail($plan, $interval);

        $builder = $tenant->newSubscription(self::SUBSCRIPTION, $priceId);

        // Preserve remaining trial rather than restarting it, so switching plans
        // mid-trial neither extends nor forfeits the days already used.
        if ($tenant->trial_ends_at?->isFuture()) {
            $builder->trialUntil($tenant->trial_ends_at);
        } elseif ($plan->trial_days > 0 && ! $tenant->subscriptions()->exists()) {
            $builder->trialDays($plan->trial_days);
        } else {
            // An organization that already subscribed once does not get another
            // free trial by cancelling and re-subscribing.
            $builder->skipTrial();
        }

        if ($coupon !== null && $coupon !== '') {
            $builder->withCoupon($coupon);
        }

        $subscription = $this->callStripe(
            fn () => $builder->create($paymentMethod),
            field: 'payment',
        );

        $this->syncPlan($tenant, $plan);

        return $subscription;
    }

    /**
     * Move an existing subscription to another plan/interval.
     *
     * @throws ValidationException
     */
    public function swap(Tenant $tenant, Plan $plan, string $interval): Subscription
    {
        $priceId = $this->priceIdOrFail($plan, $interval);
        $subscription = $this->activeSubscription($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan' => __('There is no active subscription to change. Subscribe first.'),
            ]);
        }

        // swapAndInvoice bills the proration immediately rather than letting it
        // accrue silently onto the next invoice — an upgrade the customer just
        // chose should not surprise them a month later.
        $subscription = $this->callStripe(
            fn () => $subscription->swapAndInvoice($priceId),
            field: 'plan',
        );

        $this->syncPlan($tenant, $plan);

        return $subscription;
    }

    /**
     * Cancel at period end. The customer keeps access they have paid for; that
     * window is the "grace period".
     */
    public function cancel(Tenant $tenant): void
    {
        $subscription = $this->activeSubscription($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'subscription' => __('There is no active subscription to cancel.'),
            ]);
        }

        $subscription->cancel();
    }

    public function resume(Tenant $tenant): void
    {
        $subscription = $tenant->subscription(self::SUBSCRIPTION);

        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => __('This subscription cannot be resumed. Start a new one instead.'),
            ]);
        }

        $subscription->resume();
    }

    /**
     * The organization's current subscription, if any.
     */
    public function activeSubscription(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->subscription(self::SUBSCRIPTION);

        // `valid()` covers active, trialing, and grace period — all states in
        // which the customer still has access.
        return $subscription !== null && $subscription->valid() ? $subscription : null;
    }

    /**
     * Resolve which plan a subscription's Stripe price belongs to.
     *
     * Stripe's price id is authoritative: if the plan was changed in Stripe's
     * dashboard, this is what tells us so.
     */
    public function planForSubscription(?Subscription $subscription): ?Plan
    {
        if ($subscription?->stripe_price === null) {
            return null;
        }

        return Plan::where('stripe_monthly_price_id', $subscription->stripe_price)
            ->orWhere('stripe_annual_price_id', $subscription->stripe_price)
            ->first();
    }

    public function intervalForSubscription(?Subscription $subscription): ?string
    {
        $plan = $this->planForSubscription($subscription);

        if ($plan === null) {
            return null;
        }

        return $subscription->stripe_price === $plan->stripe_annual_price_id ? 'annual' : 'monthly';
    }

    /**
     * Keep the local plan pointer in step. Limits are read from it on every
     * request, so a stale value would grant or deny the wrong quota.
     */
    public function syncPlan(Tenant $tenant, ?Plan $plan): void
    {
        $tenant->forceFill(['plan_id' => $plan?->id])->save();
    }

    /**
     * @throws ValidationException
     */
    private function priceIdOrFail(Plan $plan, string $interval): string
    {
        if (! in_array($interval, Plan::INTERVALS, true)) {
            throw ValidationException::withMessages([
                'interval' => __('Choose either a monthly or annual billing interval.'),
            ]);
        }

        $priceId = $plan->priceIdFor($interval);

        if ($priceId === null || $priceId === '') {
            // Configuration gap rather than user error — say so plainly instead
            // of letting Stripe reject an empty price id.
            throw ValidationException::withMessages([
                'plan' => __('The :plan plan is not available on a :interval basis.', [
                    'plan' => $plan->name,
                    'interval' => $interval,
                ]),
            ]);
        }

        return $priceId;
    }

    /**
     * Run a Stripe call, turning the failures a customer can act on into 422s.
     *
     * Anything not matched here propagates untouched, so genuine bugs still
     * surface as 500s rather than being disguised as validation errors.
     *
     * Funnelling every Stripe call through one closure also keeps this handling
     * in a single place: Cashier does not annotate `@throws` on its subscription
     * methods, so catching ApiErrorException directly around one of them gets
     * reported as an unreachable catch — even though Stripe raises it at runtime.
     *
     * @template T
     *
     * @param callable(): T $call
     * @param string $field Validation key the error is reported under.
     * @return T
     *
     * @throws ValidationException
     */
    private function callStripe(callable $call, string $field)
    {
        try {
            return $call();
        } catch (IncompletePayment) {
            // 3D Secure / SCA — the customer must confirm in the browser.
            throw ValidationException::withMessages([
                'payment' => __('This payment needs confirmation. Complete the authentication and try again.'),
            ]);
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages([$field => $this->readable($e)]);
        }
    }

    /**
     * Stripe's own message is written for end users and is more useful than a
     * generic failure — but only for errors it attributes to the card. Anything
     * without a Stripe code is ours to fix, so it is not echoed back.
     */
    private function readable(ApiErrorException $e): string
    {
        if ($e->getStripeCode() === null) {
            return __('The payment could not be processed. Please try again.');
        }

        return $e->getError()->message ?? __('The payment could not be processed.');
    }
}
