<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Services\StripePriceCatalogue;
use Stripe\Price;
use Stripe\Product;

/**
 * plans:import-stripe — matching Stripe products to plans and filling in the
 * price ids. Stripe is faked throughout; these assert our matching rules.
 */

/** @param array<string, mixed> $attrs */
function importProduct(string $id, string $name, array $metadata = []): Product
{
    return Product::constructFrom(['id' => $id, 'name' => $name, 'metadata' => $metadata]);
}

/** @param array<string, mixed> $attrs */
function importPrice(string $id, Product $product, string $interval = 'month', array $attrs = []): Price
{
    return Price::constructFrom(array_merge([
        'id' => $id,
        'product' => $product,
        'active' => true,
        'currency' => 'usd',
        'unit_amount' => 2900,
        'recurring' => ['interval' => $interval, 'interval_count' => 1],
    ], $attrs));
}

/**
 * Swap the catalogue for one that returns a fixed price list.
 *
 * @param array<int, Price> $prices
 */
function fakeStripeCatalogue(array $prices, bool $configured = true): void
{
    app()->instance(StripePriceCatalogue::class, new class($prices, $configured) extends StripePriceCatalogue
    {
        /** @param array<int, Price> $prices */
        public function __construct(private array $prices, private bool $ok) {}

        public function configured(): bool
        {
            return $this->ok;
        }

        public function activeRecurringPrices(): array
        {
            return $this->prices;
        }

        public function retrieve(string $priceId): ?Price
        {
            foreach ($this->prices as $price) {
                if ($price->id === $priceId) {
                    return $price;
                }
            }

            return null;
        }
    });
}

function starterPlan(array $overrides = []): Plan
{
    return Plan::create(array_merge([
        'name' => 'Starter',
        'slug' => 'starter',
        'currency' => 'USD',
    ], $overrides));
}

it('imports both intervals onto the matching plan', function () {
    $plan = starterPlan();
    $product = importProduct('prod_starter', 'Starter');

    fakeStripeCatalogue([
        importPrice('price_starter_m', $product, 'month', ['unit_amount' => 2900]),
        importPrice('price_starter_y', $product, 'year', ['unit_amount' => 29000]),
    ]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    $plan->refresh();

    expect($plan->stripe_monthly_price_id)->toBe('price_starter_m')
        ->and($plan->stripe_annual_price_id)->toBe('price_starter_y')
        // The amount comes across too — Stripe is the source of truth for money.
        ->and($plan->monthly_amount)->toBe(2900)
        ->and($plan->annual_amount)->toBe(29000);
});

it('matches a product to a plan by slugifying its name', function () {
    $plan = Plan::create(['name' => 'Pro Team', 'slug' => 'pro-team', 'currency' => 'USD']);

    fakeStripeCatalogue([importPrice('price_pt', importProduct('prod_pt', 'Pro Team'))]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBe('price_pt');
});

it('prefers an explicit plan_slug in the product metadata', function () {
    $plan = starterPlan();

    // The Stripe product is named differently on purpose.
    fakeStripeCatalogue([
        importPrice('price_x', importProduct('prod_x', 'Launch Offer', ['plan_slug' => 'starter'])),
    ]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBe('price_x');
});

it('reports a stripe product that matches no plan, and creates nothing', function () {
    fakeStripeCatalogue([importPrice('price_ghost', importProduct('prod_ghost', 'Ghost Tier'))]);

    test()->artisan('plans:import-stripe')
        ->expectsOutputToContain('No plan matches Stripe product')
        ->assertExitCode(0);

    // Inventing a plan would give it null (unlimited) limits by default.
    expect(Plan::where('slug', 'ghost-tier')->exists())->toBeFalse();
});

it('keeps a price id that is already set', function () {
    $plan = starterPlan(['stripe_monthly_price_id' => 'price_chosen_by_hand']);

    fakeStripeCatalogue([importPrice('price_from_stripe', importProduct('prod_starter', 'Starter'))]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBe('price_chosen_by_hand');
});

it('replaces an existing price id when forced', function () {
    $plan = starterPlan(['stripe_monthly_price_id' => 'price_old']);

    fakeStripeCatalogue([importPrice('price_new', importProduct('prod_starter', 'Starter'))]);

    test()->artisan('plans:import-stripe --force')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBe('price_new');
});

it('writes nothing on a dry run', function () {
    $plan = starterPlan();

    fakeStripeCatalogue([importPrice('price_starter_m', importProduct('prod_starter', 'Starter'))]);

    test()->artisan('plans:import-stripe --dry-run')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBeNull();
});

it('refuses to guess when a product has two candidate prices for one interval', function () {
    $plan = starterPlan();
    $product = importProduct('prod_starter', 'Starter');

    fakeStripeCatalogue([
        importPrice('price_a', $product, 'month', ['unit_amount' => 2900]),
        importPrice('price_b', $product, 'month', ['unit_amount' => 3900]),
    ]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    // Picking one would silently decide what customers pay.
    expect($plan->refresh()->stripe_monthly_price_id)->toBeNull();
});

it('skips a price in a different currency to the plan', function () {
    $plan = starterPlan(['currency' => 'USD']);

    fakeStripeCatalogue([
        importPrice('price_eur', importProduct('prod_starter', 'Starter'), 'month', ['currency' => 'eur']),
    ]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBeNull();
});

it('ignores a price that bills every few months rather than monthly', function () {
    $plan = starterPlan();

    fakeStripeCatalogue([
        importPrice('price_q', importProduct('prod_starter', 'Starter'), 'month', [
            'recurring' => ['interval' => 'month', 'interval_count' => 3],
        ]),
    ]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBeNull();
});

it('is idempotent — a second run changes nothing', function () {
    $plan = starterPlan();

    fakeStripeCatalogue([importPrice('price_starter_m', importProduct('prod_starter', 'Starter'))]);

    test()->artisan('plans:import-stripe')->assertExitCode(0);
    test()->artisan('plans:import-stripe')
        ->expectsOutputToContain('already correct')
        ->assertExitCode(0);

    expect($plan->refresh()->stripe_monthly_price_id)->toBe('price_starter_m');
});

it('fails clearly when stripe is not configured', function () {
    fakeStripeCatalogue([], configured: false);

    test()->artisan('plans:import-stripe')->assertExitCode(1);
});

it('says so when stripe has no recurring prices yet', function () {
    fakeStripeCatalogue([]);

    test()->artisan('plans:import-stripe')
        ->expectsOutputToContain('no active recurring prices')
        ->assertExitCode(0);
});
