<?php

declare(strict_types=1);

use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Promote a fresh user to super admin and return their bearer token.
 */
function lifecycleAdminToken(): string
{
    [$user, , $token] = registerUser('lifecycle-admin@example.test', 'Lifecycle HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return $token;
}

function asLifecycleAdmin(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

/**
 * Does the given database exist on the server?
 *
 * information_schema accepts bindings; SHOW DATABASES LIKE does not.
 */
function databaseExists(string $name): bool
{
    return DB::connection(config('tenancy.database.central_connection'))
        ->select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$name]) !== [];
}

it('soft-deletes an organization WITHOUT dropping its database', function () {
    $token = lifecycleAdminToken();
    [, $acme, $acmeToken] = registerUser('a@acme.test', 'Acme');
    $dbName = $acme->database()->getName();

    // Put a row in the tenant DB so "data survived" is a real claim.
    $acme->run(fn () => Customer::factory()->create(['name' => 'Kept Customer']));

    expect(databaseExists($dbName))->toBeTrue();

    asLifecycleAdmin($token)->deleteJson("/api/v1/admin/organizations/{$acme->id}")->assertOk();

    // The row is trashed...
    expect(Tenant::withTrashed()->find($acme->id)->trashed())->toBeTrue()
        // ...but the database — and its data — are still there. This is the whole
        // point of Phase 3: a soft delete must be reversible.
        ->and(databaseExists($dbName))->toBeTrue();

    // Access is cut immediately: a deleted org no longer resolves at all, so
    // the tenant middleware returns 404 (unknown org) rather than 403.
    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')->assertNotFound();
});

it('restores a soft-deleted organization with its data intact', function () {
    $token = lifecycleAdminToken();
    [, $acme, $acmeToken] = registerUser('a@acme.test', 'Acme');
    $acme->run(fn () => Customer::factory()->create(['name' => 'Survivor']));

    asLifecycleAdmin($token)->deleteJson("/api/v1/admin/organizations/{$acme->id}")->assertOk();
    asLifecycleAdmin($token)->postJson("/api/v1/admin/organizations/{$acme->id}/restore")->assertOk();

    expect(Tenant::find($acme->id))->not->toBeNull();

    // Members are back online, and their data was never touched.
    apiAs($acmeToken, $acme)->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Survivor']);
});

it('excludes soft-deleted orgs from the list by default and shows them on demand', function () {
    $token = lifecycleAdminToken();
    [, $acme] = registerUser('a@acme.test', 'Acme');

    asLifecycleAdmin($token)->deleteJson("/api/v1/admin/organizations/{$acme->id}")->assertOk();

    $default = collect(asLifecycleAdmin($token)->getJson('/api/v1/admin/organizations')->json('data'))->pluck('name');
    expect($default)->not->toContain('Acme');

    $trashed = collect(asLifecycleAdmin($token)->getJson('/api/v1/admin/organizations?trashed=only')->json('data'))->pluck('name');
    expect($trashed)->toContain('Acme');
});

it('purges only organizations past the retention window, and drops their database', function () {
    lifecycleAdminToken();
    [, $recent] = registerUser('recent@example.test', 'Recent Org');
    [, $old] = registerUser('old@example.test', 'Old Org');
    $oldDb = $old->database()->getName();

    // Both are soft-deleted, but "Old Org" was deleted well before the window.
    $recent->delete();
    $old->delete();
    Tenant::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(45)]);

    // Purge everything deleted more than 30 days ago.
    Artisan::call('tenants:purge', ['--days' => 30, '--force' => true]);

    // Old Org is gone — row hard-deleted, database dropped.
    expect(Tenant::withTrashed()->find($old->id))->toBeNull()
        ->and(databaseExists($oldDb))->toBeFalse()
        // Recent Org is still recoverable — it has not aged out.
        ->and(Tenant::withTrashed()->find($recent->id))->not->toBeNull();
});

it('will not purge a recently deleted org', function () {
    lifecycleAdminToken();
    [, $org] = registerUser('fresh@example.test', 'Fresh Delete');
    $org->delete();

    // Default 30-day window: a just-deleted org is untouched.
    Artisan::call('tenants:purge', ['--force' => true]);

    expect(Tenant::withTrashed()->find($org->id))->not->toBeNull();
});

it('records every mutating admin action in the central audit trail', function () {
    $token = lifecycleAdminToken();
    [, $acme] = registerUser('a@acme.test', 'Acme');

    asLifecycleAdmin($token)->postJson("/api/v1/admin/organizations/{$acme->id}/suspend")->assertOk();
    asLifecycleAdmin($token)->postJson("/api/v1/admin/organizations/{$acme->id}/activate")->assertOk();
    asLifecycleAdmin($token)->putJson("/api/v1/admin/organizations/{$acme->id}", ['name' => 'Acme 2'])->assertOk();
    asLifecycleAdmin($token)->deleteJson("/api/v1/admin/organizations/{$acme->id}")->assertOk();

    $actions = AdminActivity::where('target_id', $acme->id)->pluck('action');

    expect($actions)->toContain('organization.suspended')
        ->and($actions)->toContain('organization.activated')
        ->and($actions)->toContain('organization.updated')
        ->and($actions)->toContain('organization.deleted');

    // The update entry recorded the diff, not the whole model.
    $update = AdminActivity::where('target_id', $acme->id)->where('action', 'organization.updated')->first();
    expect($update->properties['changed'])->toBe(['name'])
        ->and($update->admin_id)->not->toBeNull();
});

it('exposes the audit trail through the admin API, newest first', function () {
    $token = lifecycleAdminToken();
    [, $acme] = registerUser('a@acme.test', 'Acme');

    asLifecycleAdmin($token)->postJson("/api/v1/admin/organizations/{$acme->id}/suspend")->assertOk();

    $response = asLifecycleAdmin($token)->getJson('/api/v1/admin/activity?organization='.$acme->id)->assertOk();

    expect($response->json('data.0.action'))->toBe('organization.suspended')
        ->and($response->json('data.0.target.label'))->toBe('Acme')
        ->and($response->json('data.0.admin.email'))->toBe('lifecycle-admin@example.test');
});

it('keeps the purge audit entry readable after the org itself is gone', function () {
    lifecycleAdminToken();
    [, $org] = registerUser('gone@example.test', 'Doomed Org');
    $org->delete();

    Artisan::call('tenants:purge', ['--days' => 0, '--force' => true]);

    // The org row is destroyed, but the audit log still names it — target_label
    // snapshotted the name before the purge.
    $entry = AdminActivity::where('action', 'organization.purged')->where('target_id', $org->id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->target_label)->toBe('Doomed Org')
        ->and($entry->admin_id)->toBeNull(); // a system action, no acting admin
});
