<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\OrganizationService;
use App\Services\UsageService;
use Database\Seeders\PlanSeeder;

/**
 * Billing behaviour that does not require a live Stripe key.
 *
 * Subscribing, swapping, and invoices all call Stripe's API, so they are not
 * exercised here — a test that mocked Stripe's responses would only assert that
 * our mock behaves like our mock. What *is* covered is everything Stripe is not
 * responsible for: plan resolution, limit enforcement, permissions, and the
 * fallback behaviour when no subscription exists.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Plans
|--------------------------------------------------------------------------
*/

it('lists active plans with their limits', function () {
    [, $tenant, $token] = registerUser('plans@example.test', 'Plans Org');

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/billing/plans')
        ->assertOk();

    $plans = collect($response->json('data'));

    expect($plans)->toHaveCount(4)
        ->and($plans->pluck('slug')->all())->toBe(['free', 'starter', 'pro', 'enterprise']);

    // null means unlimited — it must survive serialization as null, not 0.
    $enterprise = $plans->firstWhere('slug', 'enterprise');
    expect($enterprise['limits']['customers'])->toBeNull()
        ->and($plans->firstWhere('slug', 'free')['limits']['customers'])->toBe(25);
});

it('never exposes stripe price ids to clients', function () {
    [, $tenant, $token] = registerUser('noprice@example.test', 'NoPrice Org');

    $body = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/billing/plans')
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('stripe_monthly_price_id')
        ->and($body)->not->toContain('stripe_annual_price_id');
});

it('reports billing overview without a subscription and without calling stripe', function () {
    [, $tenant, $token] = registerUser('nosub@example.test', 'NoSub Org');

    // An organization that has never paid has no Stripe customer at all, so the
    // billing page must render entirely from local state.
    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/billing')
        ->assertOk();

    expect($response->json('data.subscription.active'))->toBeFalse()
        ->and($response->json('data.subscription.status'))->toBeNull()
        ->and($response->json('data.payment_method'))->toBeNull()
        // Falls back to the free tier rather than reporting "no limits".
        ->and($response->json('data.subscription.plan.slug'))->toBe('free')
        ->and($response->json('data.usage.customers.limit'))->toBe(25);
});

it('returns no invoices for an organization with no stripe customer', function () {
    [, $tenant, $token] = registerUser('noinv@example.test', 'NoInv Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/billing/invoices')
        ->assertOk()
        ->assertJsonPath('data', []);
});

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

it('forbids an admin from managing billing but allows viewing', function () {
    [$user, $tenant, $token] = registerUser('adminbill@example.test', 'AdminBill Org');
    giveRole($tenant, $user, 'admin');

    // Admins can see billing...
    $this->withHeaders(orgHeaders($token, $tenant))->getJson('/api/v1/billing')->assertOk();

    // ...but money decisions are owner-only.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'monthly'])
        ->assertStatus(403);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson('/api/v1/billing/subscription')
        ->assertStatus(403);
});

it('forbids a viewer from seeing billing at all', function () {
    [$user, $tenant, $token] = registerUser('vbill@example.test', 'ViewBill Org');
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/billing')
        ->assertStatus(403);
});

it('keeps billing scoped to the active organization', function () {
    [, $tenantA, $tokenA] = registerUser('billa@example.test', 'Bill A');
    [, $tenantB] = registerUser('billb@example.test', 'Bill B');

    $this->withHeaders(orgHeaders($tokenA, $tenantB))
        ->getJson('/api/v1/billing')
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('rejects an unknown plan or interval', function () {
    [, $tenant, $token] = registerUser('badplan@example.test', 'BadPlan Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'platinum', 'interval' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'weekly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('interval');
});

it('explains rather than crashes when a plan has no configured price id', function () {
    [, $tenant, $token] = registerUser('noprice2@example.test', 'NoPriceId Org');

    // Price ids come from config and are unset in the test environment, so this
    // is exactly the state of a fresh deployment before Stripe is wired up.
    // It must fail with a clear message rather than sending Stripe an empty id.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/billing/subscribe', ['plan' => 'starter', 'interval' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

it('refuses to cancel when there is no subscription', function () {
    [, $tenant, $token] = registerUser('nocancel@example.test', 'NoCancel Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson('/api/v1/billing/subscription')
        ->assertStatus(422)
        ->assertJsonValidationErrors('subscription');
});

/*
|--------------------------------------------------------------------------
| Usage limits
|--------------------------------------------------------------------------
*/

it('blocks creating past the plan limit with 402 rather than 403', function () {
    [, $tenant, $token] = registerUser('limit@example.test', 'Limit Org');

    $free = Plan::where('slug', 'free')->firstOrFail();
    $tenant->forceFill(['plan_id' => $free->id])->save();

    // Fill the plan exactly (free allows 25).
    $tenant->run(fn () => Customer::factory()->count(25)->create());

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'One Too Many'])
        // 402, not 403: this is not an authorization failure, and the client
        // should prompt an upgrade rather than say "access denied".
        ->assertStatus(402);

    expect($response->json('meta.limit_reached'))->toBeTrue()
        ->and($response->json('meta.resource'))->toBe('customers')
        ->and($response->json('meta.limit'))->toBe(25);

    $tenant->run(fn () => expect(Customer::count())->toBe(25));
});

it('still allows reading and deleting at the plan ceiling', function () {
    [, $tenant, $token] = registerUser('ceiling@example.test', 'Ceiling Org');

    $free = Plan::where('slug', 'free')->firstOrFail();
    $tenant->forceFill(['plan_id' => $free->id])->save();
    $tenant->run(fn () => Customer::factory()->count(25)->create());

    $this->withHeaders(orgHeaders($token, $tenant))->getJson('/api/v1/customers')->assertOk();

    // Critical: a full plan must not trap the organization. If delete were
    // blocked too, they could never get back under the limit.
    $id = $tenant->run(fn () => Customer::first()->id);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/customers/{$id}")
        ->assertOk();

    // ...and creating works again once a slot frees up.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'Back Under The Limit'])
        ->assertCreated();
});

it('blocks inviting past the user limit', function () {
    [, $tenant, $token] = registerUser('userlimit@example.test', 'UserLimit Org');

    $free = Plan::where('slug', 'free')->firstOrFail(); // 2 users
    $tenant->forceFill(['plan_id' => $free->id])->save();

    [$second] = registerUser('second@example.test', 'Second Own Org');
    app(OrganizationService::class)->addMember($tenant, $second, Role::Employee);

    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/members/invitations', ['email' => 'third@example.test', 'role' => 'viewer'])
        ->assertStatus(402);
});

it('treats an unlimited plan as unlimited', function () {
    [, $tenant, $token] = registerUser('unlimited@example.test', 'Unlimited Org');

    $enterprise = Plan::where('slug', 'enterprise')->firstOrFail();
    $tenant->forceFill(['plan_id' => $enterprise->id])->save();
    $tenant->run(fn () => Customer::factory()->count(30)->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'No Ceiling Here'])
        ->assertCreated();

    $report = app(UsageService::class)->report($tenant->fresh());

    expect($report['customers']['limit'])->toBeNull()
        ->and($report['customers']['exceeded'])->toBeFalse()
        ->and($report['customers']['remaining'])->toBeNull();
});

it('falls back to the free tier rather than unlimited when no plan is set', function () {
    [, $tenant] = registerUser('noplan@example.test', 'NoPlan Org');

    $tenant->forceFill(['plan_id' => null])->save();

    // Failing open here would let anyone bypass billing by never subscribing.
    $plan = app(UsageService::class)->planFor($tenant->fresh());

    expect($plan?->slug)->toBe('free')
        ->and(app(UsageService::class)->report($tenant->fresh())['customers']['limit'])->toBe(25);
});

it('counts usage per organization, not across them', function () {
    [, $tenantA] = registerUser('usagea@example.test', 'Usage A');
    [, $tenantB] = registerUser('usageb@example.test', 'Usage B');

    $tenantA->run(fn () => Customer::factory()->count(7)->create());
    $tenantB->run(fn () => Customer::factory()->count(3)->create());

    $usage = app(UsageService::class);

    expect($usage->current($tenantA)['customers'])->toBe(7)
        ->and($usage->current($tenantB)['customers'])->toBe(3);
});
