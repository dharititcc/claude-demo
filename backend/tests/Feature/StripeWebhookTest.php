<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Cashier's webhook endpoint, and the listener that keeps our plan pointer in
 * step with Stripe.
 *
 * Stripe is never called: the payloads are hand-built and signed with a test
 * secret, exactly as Stripe would sign them. The `customer` id deliberately
 * matches no organization, so Cashier's own handler no-ops and what is left
 * under test is our HandleStripeWebhook listener.
 */
const WEBHOOK_SECRET = 'whsec_test_secret_for_signing';

/**
 * POST a payload signed the way Stripe signs it.
 *
 * The raw body is sent verbatim rather than through postJson(), because the
 * signature covers the exact bytes — re-encoding the array could change them
 * and turn a passing test into a signature failure.
 *
 * @param array<string, mixed> $payload
 */
function postStripeWebhook(array $payload, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    config()->set('cashier.webhook.secret', WEBHOOK_SECRET);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? WEBHOOK_SECRET);

    return test()->call(
        'POST',
        '/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

/**
 * A subscription-event payload pointing at $stripeId and priced at $priceId.
 *
 * @return array<string, mixed>
 */
function subscriptionPayload(string $type, string $stripeId, ?string $priceId = null, ?int $periodEnd = null): array
{
    return [
        'type' => $type,
        'data' => [
            'object' => [
                'id' => $stripeId,
                // Matches no organization, so Cashier's own handler skips and
                // only our listener acts.
                'customer' => 'cus_not_a_real_organization',
                'status' => 'active',
                'current_period_end' => $periodEnd ?? now()->addMonth()->timestamp,
                'items' => ['data' => [['price' => ['id' => $priceId ?? 'price_unknown']]]],
            ],
        ],
    ];
}

/**
 * An organization with a subscription row, plus a free and a paid plan.
 *
 * @return array{0: Tenant, 1: Subscription, 2: Plan, 3: Plan} tenant, subscription, free, paid
 */
function orgWithSubscription(string $stripeId = 'sub_test_123'): array
{
    [, $tenant] = registerUser('billing-owner@example.test', 'Billing Co');

    $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'currency' => 'USD', 'sort_order' => 0]);
    $paid = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'currency' => 'USD',
        'sort_order' => 1,
        'stripe_monthly_price_id' => 'price_pro_monthly',
        'stripe_annual_price_id' => 'price_pro_annual',
    ]);

    $tenant->forceFill(['plan_id' => $free->id])->save();

    $subscription = new Subscription;
    $subscription->forceFill([
        'tenant_id' => $tenant->id,
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly',
        'quantity' => 1,
    ])->save();

    return [$tenant, $subscription, $free, $paid];
}

// ─── Signature verification ───

it('refuses an unsigned request', function () {
    config()->set('cashier.webhook.secret', WEBHOOK_SECRET);

    // No Stripe-Signature header at all — what an attacker would send.
    test()->postJson('/stripe/webhook', subscriptionPayload('customer.subscription.updated', 'sub_x'))
        ->assertForbidden();
});

it('refuses a payload tampered with after signing', function () {
    [, $subscription, , $paid] = orgWithSubscription();

    $payload = subscriptionPayload('customer.subscription.updated', $subscription->stripe_id, $paid->stripe_monthly_price_id);

    config()->set('cashier.webhook.secret', WEBHOOK_SECRET);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", WEBHOOK_SECRET);

    // Sign one body, send another.
    $tampered = str_replace('"status":"active"', '"status":"canceled"', $body);

    test()->call(
        'POST', '/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $tampered,
    )->assertForbidden();
});

it('refuses a signature from the wrong secret', function () {
    postStripeWebhook(subscriptionPayload('customer.subscription.updated', 'sub_x'), secret: 'whsec_someone_elses_secret')
        ->assertForbidden();
});

it('refuses a replayed request older than the tolerance window', function () {
    $stale = time() - (int) config('cashier.webhook.tolerance') - 60;

    postStripeWebhook(subscriptionPayload('customer.subscription.updated', 'sub_x'), timestamp: $stale)
        ->assertForbidden();
});

// ─── The listener ───

it('moves the organization onto the plan matching the price stripe sent', function () {
    [$tenant, $subscription, $free, $paid] = orgWithSubscription();

    expect($tenant->fresh()->plan_id)->toBe($free->id);

    postStripeWebhook(subscriptionPayload(
        'customer.subscription.updated',
        $subscription->stripe_id,
        'price_pro_monthly',
    ))->assertOk();

    // This is the whole point: a plan changed in Stripe's dashboard reaches us.
    expect($tenant->fresh()->plan_id)->toBe($paid->id);
});

it('matches an annual price id as well as a monthly one', function () {
    [$tenant, $subscription, , $paid] = orgWithSubscription();

    postStripeWebhook(subscriptionPayload(
        'customer.subscription.updated',
        $subscription->stripe_id,
        'price_pro_annual',
    ))->assertOk();

    expect($tenant->fresh()->plan_id)->toBe($paid->id);
});

it('caches the current period end that stripe reported', function () {
    [, $subscription] = orgWithSubscription();

    $periodEnd = now()->addDays(30)->startOfSecond();

    postStripeWebhook(subscriptionPayload(
        'customer.subscription.updated',
        $subscription->stripe_id,
        'price_pro_monthly',
        $periodEnd->timestamp,
    ))->assertOk();

    expect($subscription->fresh()->current_period_end?->timestamp)->toBe($periodEnd->timestamp);
});

it('drops the organization back to the free tier when the subscription ends', function () {
    [$tenant, $subscription, $free, $paid] = orgWithSubscription();

    $tenant->forceFill(['plan_id' => $paid->id])->save();

    postStripeWebhook(subscriptionPayload('customer.subscription.deleted', $subscription->stripe_id))
        ->assertOk();

    // Including when the end came from dunning rather than a deliberate cancel:
    // leaving them on Pro limits would be giving away the paid tier.
    expect($tenant->fresh()->plan_id)->toBe($free->id);
});

it('ignores a subscription id it does not know, rather than failing', function () {
    [$tenant, , $free] = orgWithSubscription();

    postStripeWebhook(subscriptionPayload('customer.subscription.updated', 'sub_never_seen', 'price_pro_monthly'))
        ->assertOk();

    expect($tenant->fresh()->plan_id)->toBe($free->id);
});

it('leaves the plan alone when the price matches no plan of ours', function () {
    [$tenant, $subscription, $free] = orgWithSubscription();

    postStripeWebhook(subscriptionPayload(
        'customer.subscription.updated',
        $subscription->stripe_id,
        'price_from_some_other_product',
    ))->assertOk();

    expect($tenant->fresh()->plan_id)->toBe($free->id);
});

it('ignores event types it has no opinion about', function () {
    [$tenant, , $free] = orgWithSubscription();

    postStripeWebhook(['type' => 'invoice.created', 'data' => ['object' => ['id' => 'in_123']]])
        ->assertOk();

    expect($tenant->fresh()->plan_id)->toBe($free->id);
});
