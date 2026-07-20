<?php

declare(strict_types=1);

use App\Models\AdminActivity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\UsageService;
use Tests\TestCase;

function limitAdminToken(): string
{
    [$user, , $token] = registerUser('limit-admin@example.test', 'Limit HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return $token;
}

function asLimitAdmin(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

/** Set an org's limit overrides through the admin API. */
function setOverrides(string $adminToken, Tenant $org, array $overrides): void
{
    asLimitAdmin($adminToken)
        ->putJson("/api/v1/admin/organizations/{$org->id}/limits", ['overrides' => $overrides])
        ->assertOk();
}

it('an override raises the ceiling and enforcement honours it immediately', function () {
    $adminToken = limitAdminToken();
    [, $acme, $ownerToken] = registerUser('owner@acme.test', 'Acme');

    // Cap customers at 1 for this org specifically.
    setOverrides($adminToken, $acme, ['customers' => 1]);

    // First create is allowed...
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'First'])->assertCreated();
    // ...the second hits the override and is refused with 402 (not 403).
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'Second'])->assertStatus(402);

    // Raise the ceiling; the very next request succeeds — no re-deploy, no plan
    // change.
    setOverrides($adminToken, $acme, ['customers' => 5]);
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'Second'])->assertCreated();
});

it('an unlimited override (null) removes the ceiling entirely', function () {
    $adminToken = limitAdminToken();
    [, $acme, $ownerToken] = registerUser('owner@acme.test', 'Acme');

    setOverrides($adminToken, $acme, ['customers' => 1]);
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'First'])->assertCreated();
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'Second'])->assertStatus(402);

    // null means unlimited — distinct from 0.
    setOverrides($adminToken, $acme, ['customers' => null]);
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'Second'])->assertCreated();
    apiAs($ownerToken, $acme)->postJson('/api/v1/customers', ['name' => 'Third'])->assertCreated();
});

it('clearing overrides falls back to the plan', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    setOverrides($adminToken, $acme, ['customers' => 42]);
    expect(Tenant::find($acme->id)->limit_overrides)->toBe(['customers' => 42]);

    // An empty map clears everything and stores null, not an empty object.
    setOverrides($adminToken, $acme, []);
    expect(Tenant::find($acme->id)->limit_overrides)->toBeNull();
});

it('reports the override breakdown for the admin detail screen', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    setOverrides($adminToken, $acme, ['users' => 99]);

    $limits = asLimitAdmin($adminToken)
        ->getJson("/api/v1/admin/organizations/{$acme->id}")
        ->assertOk()
        ->json('limits');

    expect($limits['users']['has_override'])->toBeTrue()
        ->and($limits['users']['override'])->toBe(99)
        ->and($limits['users']['effective_limit'])->toBe(99)
        // customers was not overridden — no override flag.
        ->and($limits['customers']['has_override'])->toBeFalse();
});

it('rejects unknown override keys and non-integer values', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    asLimitAdmin($adminToken)
        ->putJson("/api/v1/admin/organizations/{$acme->id}/limits", ['overrides' => ['users' => 'lots']])
        ->assertStatus(422);

    // An unknown key is silently dropped, not stored.
    setOverrides($adminToken, $acme, ['projects' => 10, 'users' => 5]);
    expect(Tenant::find($acme->id)->limit_overrides)->toBe(['users' => 5]);
});

it('audits a limit change', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    setOverrides($adminToken, $acme, ['users' => 7]);

    $entry = AdminActivity::where('target_id', $acme->id)
        ->where('action', 'organization.limits.updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['overrides'])->toBe(['users' => 7]);
});

it('derives the billing interval from the subscription price', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    // Attach a plan and a subscription whose price is the plan's annual price.
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.$acme->id, 'monthly_amount' => 1000, 'annual_amount' => 10000,
        'currency' => 'usd', 'trial_days' => 0, 'is_active' => true, 'sort_order' => 1,
        'stripe_monthly_price_id' => 'price_monthly_x', 'stripe_annual_price_id' => 'price_annual_x',
    ]);
    $acme->forceFill(['plan_id' => $plan->id])->save();
    Subscription::create([
        'tenant_id' => $acme->id, 'type' => 'default', 'stripe_id' => 'sub_x',
        'stripe_status' => 'active', 'stripe_price' => 'price_annual_x', 'quantity' => 1,
    ]);

    $sub = asLimitAdmin($adminToken)
        ->getJson("/api/v1/admin/organizations/{$acme->id}")
        ->assertOk()
        ->json('data.subscription');

    expect($sub['interval'])->toBe('annual');
});

it('a raised override is visible to the member-facing usage report', function () {
    $adminToken = limitAdminToken();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    setOverrides($adminToken, $acme, ['customers' => 123]);

    // The org's own billing view (UsageService::report) reflects the override,
    // not just the admin screen. Reload: the in-memory $acme predates the
    // override the HTTP call just wrote. report() manages tenant context itself.
    $report = app(UsageService::class)->report(Tenant::find($acme->id));

    expect($report['customers']['limit'])->toBe(123);
});
