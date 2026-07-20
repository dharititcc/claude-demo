<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/**
 * Promote a freshly registered user to super admin and return their token.
 *
 * @return array{0: User, 1: string}
 */
function superAdmin(string $email = 'super@example.test'): array
{
    [$user, , $token] = registerUser($email, 'Platform HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return [$user, $token];
}

/**
 * A central-context admin request: bearer token only, no X-Organization header.
 */
function admin(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

it('hides the admin surface from a non-super-admin', function () {
    [, , $token] = registerUser('plain@example.test', 'Plain Org');

    // 404, not 403: a plain user should not even learn the admin API exists.
    admin($token)->getJson('/api/v1/admin/organizations')->assertNotFound();
    admin($token)->getJson('/api/v1/admin/organizations/stats')->assertNotFound();
});

it('rejects an unauthenticated caller', function () {
    $this->getJson('/api/v1/admin/organizations')->assertUnauthorized();
});

it('lists every organization, including ones the admin does not belong to', function () {
    [, $token] = superAdmin();
    registerUser('a@acme.test', 'Acme');
    registerUser('g@globex.test', 'Globex');

    $response = admin($token)->getJson('/api/v1/admin/organizations');

    $response->assertOk();

    $names = collect($response->json('data'))->pluck('name');

    // The admin is a member of none of Acme/Globex, yet sees them all.
    expect($names)->toContain('Acme')
        ->and($names)->toContain('Globex')
        ->and($response->json('meta.total'))->toBeGreaterThanOrEqual(3);
});

it('exposes the owner and central user count but not tenant-only metrics yet', function () {
    [, $token] = superAdmin();
    registerUser('owner@row.test', 'Row Org');

    $row = collect(admin($token)->getJson('/api/v1/admin/organizations')->json('data'))
        ->firstWhere('name', 'Row Org');

    expect($row['owner']['email'])->toBe('owner@row.test')
        ->and($row['metrics']['total_users'])->toBe(1)
        // Phase 2: null means not-yet-measured, distinct from a real zero.
        ->and($row['metrics']['total_projects'])->toBeNull();
});

it('searches by owner email', function () {
    [, $token] = superAdmin();
    registerUser('needle@search.test', 'Findable Org');
    registerUser('other@search.test', 'Unrelated Org');

    $data = admin($token)->getJson('/api/v1/admin/organizations?search=needle')->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Findable Org');
});

it('filters by status', function () {
    [$super, $token] = superAdmin();
    [, $acme] = registerUser('a@acme.test', 'Acme');

    admin($token)->postJson("/api/v1/admin/organizations/{$acme->id}/suspend")->assertOk();

    $data = admin($token)->getJson('/api/v1/admin/organizations?status=suspended')->json('data');

    expect(collect($data)->pluck('name'))->toContain('Acme')
        ->and(collect($data)->every(fn ($o) => $o['status'] === 'suspended'))->toBeTrue();
});

it('ignores an unknown sort column instead of trusting it', function () {
    [, $token] = superAdmin();

    // A bogus sort must not error or leak into the query — it falls back.
    admin($token)->getJson('/api/v1/admin/organizations?sort=password')->assertOk();
});

it('reports platform statistics', function () {
    [, $token] = superAdmin();
    registerUser('a@acme.test', 'Acme');
    registerUser('g@globex.test', 'Globex');

    $stats = admin($token)->getJson('/api/v1/admin/organizations/stats')->assertOk()->json('data');

    expect($stats['total'])->toBeGreaterThanOrEqual(3)
        ->and($stats['total_users'])->toBeGreaterThanOrEqual(3)
        // Phase 2 rollup not built — honest null, not a fabricated 0.
        ->and($stats['total_projects'])->toBeNull();
});

it('suspends an organization and cuts its members off immediately', function () {
    [, $adminToken] = superAdmin();
    [, $acme, $acmeToken] = registerUser('a@acme.test', 'Acme');

    // Owner can reach their org before suspension.
    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')->assertOk();

    admin($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    // ...and cannot the moment it is suspended — no token expiry needed.
    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')->assertForbidden();
});

it('reactivates a suspended organization', function () {
    [, $adminToken] = superAdmin();
    [, $acme, $acmeToken] = registerUser('a@acme.test', 'Acme');

    admin($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/suspend")->assertOk();
    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')->assertForbidden();

    admin($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')->assertOk();
});

it('edits the profile but never the slug', function () {
    [, $token] = superAdmin();
    [, $acme] = registerUser('a@acme.test', 'Acme');
    $originalSlug = $acme->slug;

    admin($token)->putJson("/api/v1/admin/organizations/{$acme->id}", [
        'name' => 'Acme Renamed',
        'phone' => '+1 555 0100',
        'slug' => 'hijacked-slug', // not fillable — must be ignored
    ])->assertOk()
        ->assertJsonPath('data.name', 'Acme Renamed')
        ->assertJsonPath('data.phone', '+1 555 0100')
        // The slug is the tenant identifier; editing it would break integrations.
        ->assertJsonPath('data.slug', $originalSlug);

    expect(Tenant::find($acme->id)->slug)->toBe($originalSlug);
});
