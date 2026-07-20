<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Providers\TenancyServiceProvider;
use App\Services\OrganizationService;

/**
 * These tests defend the core promise of the platform: one organization can
 * never read or act on another's data, and a member's authority is scoped to
 * the organization they are acting in.
 */
it('resolves the tenant context from the X-Organization header', function () {
    [, $tenant, $token] = registerUser('ctx@example.test', 'Context Org');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenant->slug,
    ])->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.organization.id', $tenant->id)
        ->assertJsonPath('data.role', 'owner');
});

it('accepts the organization id as well as the slug', function () {
    [, $tenant, $token] = registerUser('byid@example.test', 'ById Org');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenant->id,
    ])->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.organization.slug', $tenant->slug);
});

it('forbids access to an organization the user does not belong to', function () {
    [, , $tokenA] = registerUser('a@example.test', 'Org A');
    [, $tenantB] = registerUser('b@example.test', 'Org B');

    $this->withHeaders([
        'Authorization' => "Bearer {$tokenA}",
        'X-Organization' => $tenantB->slug,
    ])->getJson('/api/v1/context')
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not belong to this organization.');
});

it('requires an organization header on tenant-scoped routes', function () {
    [, , $token] = registerUser('nohdr@example.test', 'NoHeader Org');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/context')
        ->assertStatus(400);
});

it('returns 404 for an unknown organization', function () {
    [, , $token] = registerUser('unknown@example.test', 'Unknown Org');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => 'no-such-org',
    ])->getJson('/api/v1/context')
        ->assertStatus(404);
});

it('blocks access to a suspended organization', function () {
    [, $tenant, $token] = registerUser('susp@example.test', 'Suspended Co');
    $tenant->update(['status' => 'suspended']);

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenant->slug,
    ])->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('scopes a users role to each organization independently', function () {
    [$user, $tenantA, $token] = registerUser('multi@example.test', 'Multi A');
    [, $tenantB] = registerUser('otherowner@example.test', 'Multi B');

    // The same person joins the second organization as a read-only viewer.
    app(OrganizationService::class)->addMember($tenantB, $user, Role::Viewer);

    $contextA = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenantA->slug,
    ])->getJson('/api/v1/context')->assertOk();

    $contextB = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenantB->slug,
    ])->getJson('/api/v1/context')->assertOk();

    expect($contextA->json('data.role'))->toBe('owner')
        ->and($contextA->json('data.permissions'))->toContain('customers.delete')
        ->and($contextB->json('data.role'))->toBe('viewer')
        ->and($contextB->json('data.permissions'))->toContain('customers.view')
        ->and($contextB->json('data.permissions'))->not->toContain('customers.delete');
});

it('keeps each organizations roles in its own database', function () {
    [, $tenantA] = registerUser('iso1@example.test', 'Iso A');
    [, $tenantB] = registerUser('iso2@example.test', 'Iso B');

    expect($tenantA->database()->getName())->not->toBe($tenantB->database()->getName());

    // A role created in one organization must not appear in the other.
    $tenantA->run(function () {
        App\Models\Role::create(['name' => 'org-a-only', 'guard_name' => 'web']);
    });

    $tenantA->run(fn () => expect(App\Models\Role::where('name', 'org-a-only')->exists())->toBeTrue());
    $tenantB->run(fn () => expect(App\Models\Role::where('name', 'org-a-only')->exists())->toBeFalse());
});

it('scopes cache entries per organization even under the same key', function () {
    // Deliberately runs against Redis rather than the suite's array store.
    // The array store keeps data on the store instance, which tenancy replaces
    // on every bootstrap — so values vanish between contexts and every read
    // returns null. That would pass this test even if tagging were broken.
    // Only a persistent, taggable backend can actually demonstrate isolation.
    usingRedisCache();

    [, $tenantA] = registerUser('cachea@example.test', 'Cache A');
    [, $tenantB] = registerUser('cacheb@example.test', 'Cache B');

    // The dashboard caches under a fixed key ('dashboard:stats') for every
    // tenant, so per-tenant tagging is the only thing preventing one
    // organization from reading another's cached figures.
    $tenantA->run(fn () => cache()->put('shared-key', 'value-from-a', 60));
    $tenantB->run(fn () => cache()->put('shared-key', 'value-from-b', 60));

    expect($tenantA->run(fn () => cache()->get('shared-key')))->toBe('value-from-a')
        ->and($tenantB->run(fn () => cache()->get('shared-key')))->toBe('value-from-b');

    // And the central context sees neither.
    expect(cache()->get('shared-key'))->toBeNull();
});

it('refuses to boot on a cache store that cannot tag', function () {
    // Guards the assertion in TenancyServiceProvider: without it, a file/database
    // store fails later and opaquely, from whichever controller happens to cache
    // first inside tenant context.
    config(['cache.default' => 'file']);

    expect(fn () => (new TenancyServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'supports tagging');
});

it('resolves api tokens against the central database while a tenant connection is active', function () {
    [, $tenant, $token] = registerUser('tokens@example.test', 'Token Org');

    // Simulate a container that still has a tenant connection active from an
    // earlier request — exactly what happens under Octane, where the container
    // is reused rather than rebuilt per request.
    tenancy()->initialize($tenant);
    expect(config('database.default'))->toBe('tenant');

    // Sanctum must still find personal_access_tokens in the CENTRAL database.
    // Without pinning the token model, this 500s looking for that table inside
    // the tenant's database.
    apiAs($token, $tenant)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.role', 'owner');
});

it('grants super admins access to any organization', function () {
    [$user, , $token] = registerUser('super@example.test', 'Super Org');
    [, $foreignTenant] = registerUser('foreign@example.test', 'Foreign Org');

    // Not a member of the foreign organization — denied.
    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $foreignTenant->slug,
    ])->getJson('/api/v1/context')->assertStatus(403);

    // `is_super_admin` is intentionally not mass-assignable — that guard is what
    // prevents privilege escalation via a crafted request payload — so granting
    // it in a test requires going around $fillable deliberately.
    $user->forceFill(['is_super_admin' => true])->save();

    // The container persists across requests inside a single test, so the auth
    // guard still holds the user object resolved by the request above. Forget it
    // to force a fresh lookup; in production every request boots a new guard.
    $this->app['auth']->forgetGuards();

    // Platform staff may cross organization boundaries.
    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $foreignTenant->slug,
    ])->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true);
});
