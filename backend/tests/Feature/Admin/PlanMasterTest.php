<?php

declare(strict_types=1);

use App\Models\AdminActivity;
use App\Models\Plan;
use App\Models\User;
use Tests\TestCase;

/**
 * @return array{0: User, 1: string} the super admin and their token
 */
function planAdmin(): array
{
    [$user, , $token] = registerUser('plan-admin@example.test', 'Plan HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return [$user, $token];
}

function asPlanAdmin(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

/** @param array<string, mixed> $overrides */
function planPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Growth',
        'currency' => 'USD',
        'monthly_amount' => 4900,
        'annual_amount' => 49000,
        'trial_days' => 14,
        'max_users' => 25,
        'max_customers' => 5000,
        'max_storage_mb' => 10240,
        'features' => ['Priority support'],
    ], $overrides);
}

it('lists the whole catalogue including inactive plans', function () {
    [, $token] = planAdmin();

    Plan::create(['name' => 'Retired', 'slug' => 'retired', 'currency' => 'USD', 'is_active' => false]);

    $response = asPlanAdmin($token)->getJson('/api/v1/admin/plans')->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug');

    expect($slugs)->toContain('retired');
});

it('exposes the stripe wiring and flags an interval with no price id', function () {
    [, $token] = planAdmin();

    $plan = Plan::create([
        'name' => 'Half Wired',
        'slug' => 'half-wired',
        'currency' => 'USD',
        'stripe_monthly_price_id' => 'price_monthly_123',
    ]);

    asPlanAdmin($token)->getJson("/api/v1/admin/plans/{$plan->id}")
        ->assertOk()
        ->assertJsonPath('data.stripe.monthly_price_id', 'price_monthly_123')
        ->assertJsonPath('data.stripe.monthly_ready', true)
        // The reason a subscribe would fail with "not available on an annual basis".
        ->assertJsonPath('data.stripe.annual_ready', false);
});

it('creates a plan and derives the slug from the name', function () {
    [, $token] = planAdmin();

    asPlanAdmin($token)->postJson('/api/v1/admin/plans', planPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'growth')
        ->assertJsonPath('data.limits.users', 25);

    expect(Plan::where('slug', 'growth')->exists())->toBeTrue();
});

it('uniquifies a derived slug rather than colliding', function () {
    [, $token] = planAdmin();

    Plan::create(['name' => 'Growth', 'slug' => 'growth', 'currency' => 'USD']);

    asPlanAdmin($token)->postJson('/api/v1/admin/plans', planPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'growth-2');
});

it('rejects a duplicate slug that was supplied explicitly', function () {
    [, $token] = planAdmin();

    Plan::create(['name' => 'Growth', 'slug' => 'growth', 'currency' => 'USD']);

    asPlanAdmin($token)->postJson('/api/v1/admin/plans', planPayload(['slug' => 'growth']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('keeps null and 0 distinct when setting a limit', function () {
    [, $token] = planAdmin();

    // null = unlimited.
    $unlimited = asPlanAdmin($token)
        ->postJson('/api/v1/admin/plans', planPayload(['slug' => 'unlimited', 'max_users' => null]))
        ->assertCreated();

    expect($unlimited->json('data.limits.users'))->toBeNull();

    // 0 = none allowed. Must not be coerced into "unlimited".
    $none = asPlanAdmin($token)
        ->postJson('/api/v1/admin/plans', planPayload(['name' => 'Locked', 'slug' => 'locked', 'max_users' => 0]))
        ->assertCreated();

    expect($none->json('data.limits.users'))->toBe(0);
});

it('edits a plan partially, leaving absent fields untouched', function () {
    [, $token] = planAdmin();

    $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'currency' => 'USD', 'max_users' => 10, 'trial_days' => 7]);

    asPlanAdmin($token)->putJson("/api/v1/admin/plans/{$plan->id}", ['max_users' => 50])
        ->assertOk()
        ->assertJsonPath('data.limits.users', 50)
        // Untouched.
        ->assertJsonPath('data.trial_days', 7);
});

it('clears a limit back to unlimited when null is sent explicitly', function () {
    [, $token] = planAdmin();

    $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'currency' => 'USD', 'max_users' => 10]);

    asPlanAdmin($token)->putJson("/api/v1/admin/plans/{$plan->id}", ['max_users' => null])
        ->assertOk()
        ->assertJsonPath('data.limits.users', null);
});

it('lets a plan keep its own slug when edited', function () {
    [, $token] = planAdmin();

    $plan = Plan::create(['name' => 'Growth', 'slug' => 'growth', 'currency' => 'USD']);

    asPlanAdmin($token)->putJson("/api/v1/admin/plans/{$plan->id}", ['slug' => 'growth', 'name' => 'Growth Plus'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Growth Plus');
});

it('deletes an unused plan', function () {
    [, $token] = planAdmin();

    $plan = Plan::create(['name' => 'Unused', 'slug' => 'unused', 'currency' => 'USD']);

    asPlanAdmin($token)->deleteJson("/api/v1/admin/plans/{$plan->id}")->assertOk();

    expect(Plan::find($plan->id))->toBeNull();
});

it('refuses to delete a plan an organization is still on', function () {
    [, $token] = planAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $plan = Plan::create(['name' => 'In Use', 'slug' => 'in-use', 'currency' => 'USD']);
    $acme->forceFill(['plan_id' => $plan->id])->save();

    asPlanAdmin($token)->deleteJson("/api/v1/admin/plans/{$plan->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    // Still there — the organization's limits are intact.
    expect(Plan::find($plan->id))->not->toBeNull();
});

it('counts a soft-deleted organization as still using the plan', function () {
    [, $token] = planAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $plan = Plan::create(['name' => 'In Use', 'slug' => 'in-use', 'currency' => 'USD']);
    $acme->forceFill(['plan_id' => $plan->id])->save();
    $acme->delete(); // soft delete — restorable, so the plan is not free

    asPlanAdmin($token)->deleteJson("/api/v1/admin/plans/{$plan->id}")->assertStatus(422);
});

it('reports how many organizations are on each plan', function () {
    [, $token] = planAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $plan = Plan::create(['name' => 'Counted', 'slug' => 'counted', 'currency' => 'USD']);
    $acme->forceFill(['plan_id' => $plan->id])->save();

    $response = asPlanAdmin($token)->getJson('/api/v1/admin/plans')->assertOk();

    $row = collect($response->json('data'))->firstWhere('slug', 'counted');

    expect($row['organizations_count'])->toBe(1);
});

it('records every mutation in the admin audit trail', function () {
    [$admin, $token] = planAdmin();

    $created = asPlanAdmin($token)->postJson('/api/v1/admin/plans', planPayload())->assertCreated();
    $planId = $created->json('data.id');

    asPlanAdmin($token)->putJson("/api/v1/admin/plans/{$planId}", ['max_users' => 99])->assertOk();
    asPlanAdmin($token)->deleteJson("/api/v1/admin/plans/{$planId}")->assertOk();

    $actions = AdminActivity::where('admin_id', $admin->id)
        ->where('target_type', 'plan')
        ->pluck('action');

    expect($actions)->toContain('plan.created', 'plan.updated', 'plan.deleted');
});

it('hides the plan master from a non-super-admin', function () {
    [, , $token] = registerUser('member@acme.test', 'Acme');

    // 404 rather than 403: the admin surface is not advertised.
    asPlanAdmin($token)->getJson('/api/v1/admin/plans')->assertNotFound();
    asPlanAdmin($token)->postJson('/api/v1/admin/plans', planPayload())->assertNotFound();
});

it('rejects an unauthenticated caller', function () {
    test()->getJson('/api/v1/admin/plans')->assertUnauthorized();
});
