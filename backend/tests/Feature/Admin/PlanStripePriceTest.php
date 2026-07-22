<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Services\StripePriceCatalogue;
use Stripe\Price;
use Tests\TestCase;

/**
 * Verification of Stripe price ids, and the amount auto-fill that follows it.
 *
 * Stripe is never called: the catalogue is swapped for a fake, so these assert
 * our rules rather than Stripe's uptime.
 */

/**
 * @return array{0: User, 1: string} the super admin and their token
 */
function stripePlanAdmin(): array
{
    [$user, , $token] = registerUser('stripe-admin@example.test', 'Stripe HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return [$user, $token];
}

function asStripeAdmin(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

/** @param array<string, mixed> $attrs */
function stripePrice(array $attrs = []): Price
{
    return Price::constructFrom(array_merge([
        'id' => 'price_good',
        'active' => true,
        'currency' => 'usd',
        'unit_amount' => 2900,
        'recurring' => ['interval' => 'month', 'interval_count' => 1],
    ], $attrs));
}

/**
 * Swap the catalogue for one that answers from a map.
 *
 * @param array<string, Price> $map
 */
function fakeStripe(array $map, bool $configured = true): void
{
    app()->instance(StripePriceCatalogue::class, new class($map, $configured) extends StripePriceCatalogue
    {
        /** @param array<string, Price> $map */
        public function __construct(private array $map, private bool $ok) {}

        public function configured(): bool
        {
            return $this->ok;
        }

        public function retrieve(string $priceId): ?Price
        {
            return $this->map[$priceId] ?? null;
        }
    });
}

/** @param array<string, mixed> $overrides */
function stripePlanPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Growth',
        'currency' => 'USD',
        'monthly_amount' => 100, // deliberately wrong; Stripe should win
    ], $overrides);
}

it('rejects a price id Stripe does not have', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe([]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_typo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('rejects an archived price', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_old' => stripePrice(['active' => false])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_old']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('rejects a one-off price, which cannot back a subscription', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_once' => stripePrice(['recurring' => null])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_once']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('rejects an annual price pasted into the monthly field', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_year' => stripePrice(['recurring' => ['interval' => 'year', 'interval_count' => 1]])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_year']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('rejects a price billed every few months rather than monthly', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_q' => stripePrice(['recurring' => ['interval' => 'month', 'interval_count' => 3]])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_q']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('rejects a price in a different currency to the plan', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_eur' => stripePrice(['currency' => 'eur'])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['currency' => 'USD', 'stripe_monthly_price_id' => 'price_eur']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('stripe_monthly_price_id');
});

it('takes the display amount from Stripe rather than the submitted value', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_good' => stripePrice(['unit_amount' => 2900])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload([
            'stripe_monthly_price_id' => 'price_good',
            'monthly_amount' => 100, // a lie: Stripe says 2900
        ]))
        ->assertCreated()
        ->assertJsonPath('data.monthly_amount', 2900);
});

it('reconciles the amount on a partial edit that does not resend the price id', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_good' => stripePrice(['unit_amount' => 3500])]);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'currency' => 'USD',
        'monthly_amount' => 2900, // stale
        'stripe_monthly_price_id' => 'price_good',
    ]);

    asStripeAdmin($token)->putJson("/api/v1/admin/plans/{$plan->id}", ['name' => 'Growth Plus'])
        ->assertOk()
        ->assertJsonPath('data.monthly_amount', 3500);
});

it('leaves a tiered price alone, having no single amount to show', function () {
    [, $token] = stripePlanAdmin();
    fakeStripe(['price_tiered' => stripePrice(['unit_amount' => null])]);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload([
            'stripe_monthly_price_id' => 'price_tiered',
            'monthly_amount' => 4200,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.monthly_amount', 4200);
});

it('skips verification entirely when no stripe secret is configured', function () {
    [, $token] = stripePlanAdmin();
    // Nothing in the map, but unconfigured — a fresh install must still be able
    // to edit the catalogue.
    fakeStripe([], configured: false);

    asStripeAdmin($token)
        ->postJson('/api/v1/admin/plans', stripePlanPayload(['stripe_monthly_price_id' => 'price_unverifiable']))
        ->assertCreated()
        ->assertJsonPath('data.stripe.monthly_price_id', 'price_unverifiable');
});

it('syncs drifted amounts from stripe', function () {
    fakeStripe(['price_good' => stripePrice(['unit_amount' => 3900])]);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'currency' => 'USD',
        'monthly_amount' => 2900, // Stripe now says 3900
        'stripe_monthly_price_id' => 'price_good',
    ]);

    test()->artisan('plans:sync-stripe')->assertExitCode(0);

    expect($plan->fresh()->monthly_amount)->toBe(3900);
});

it('reports drift without writing when run with --dry-run', function () {
    fakeStripe(['price_good' => stripePrice(['unit_amount' => 3900])]);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'currency' => 'USD',
        'monthly_amount' => 2900,
        'stripe_monthly_price_id' => 'price_good',
    ]);

    test()->artisan('plans:sync-stripe --dry-run')->assertExitCode(0);

    expect($plan->fresh()->monthly_amount)->toBe(2900);
});

it('warns about an archived price instead of silently changing the plan', function () {
    fakeStripe(['price_old' => stripePrice(['active' => false, 'unit_amount' => 2900])]);

    $plan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'currency' => 'USD',
        'monthly_amount' => 2900,
        'stripe_monthly_price_id' => 'price_old',
        'is_active' => true,
    ]);

    test()->artisan('plans:sync-stripe')->assertExitCode(0);

    // Reported, not auto-deactivated — that is a human decision.
    expect($plan->fresh()->is_active)->toBeTrue();
});

it('does nothing when stripe is not configured', function () {
    fakeStripe([], configured: false);

    Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'currency' => 'USD',
        'monthly_amount' => 2900,
        'stripe_monthly_price_id' => 'price_good',
    ]);

    test()->artisan('plans:sync-stripe')->assertExitCode(0);
});
