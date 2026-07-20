<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('registers a user, provisions their organization, and returns a token', function () {
    Event::fake([Registered::class]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'organization_name' => 'Analytical Engines',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ada@example.test')
        ->assertJsonPath('data.organization.name', 'Analytical Engines')
        ->assertJsonPath('data.organization.slug', 'analytical-engines')
        ->assertJsonPath('data.organization.status', 'trial')
        ->assertJsonStructure(['data' => ['token', 'user' => ['id'], 'organization' => ['id']]]);

    $this->assertDatabaseHas('users', ['email' => 'ada@example.test']);
    $this->assertDatabaseHas('tenants', ['slug' => 'analytical-engines']);

    Event::assertDispatched(Registered::class);
});

it('creates a dedicated database for the new organization', function () {
    [, $tenant] = registerUser('db@example.test', 'DB Org');

    $databaseName = $tenant->database()->getName();

    // SHOW DATABASES does not support bound parameters; the name is generated
    // from the tenant UUID, not user input.
    $exists = DB::select("SHOW DATABASES LIKE '{$databaseName}'");

    expect($exists)->not->toBeEmpty()
        ->and($databaseName)->toStartWith('testtenant_');
});

it('seeds roles and permissions into the new tenant database', function () {
    [, $tenant] = registerUser('roles@example.test', 'Roles Org');

    $tenant->run(function () {
        expect(Role::pluck('name')->sort()->values()->all())
            ->toBe(['admin', 'employee', 'manager', 'owner', 'viewer'])
            ->and(Permission::count())->toBe(count(App\Enums\Permission::cases()));
    });
});

it('makes the registering user the owner of their organization', function () {
    [$user, $tenant] = registerUser('owner2@example.test', 'Owned Org');

    $this->assertDatabaseHas('organization_user', [
        'user_id' => $user->id,
        'tenant_id' => $tenant->id,
        'is_owner' => true,
    ]);

    $tenant->run(function () use ($user) {
        expect($user->fresh()->hasRole('owner'))->toBeTrue()
            ->and($user->fresh()->can('billing.manage'))->toBeTrue();
    });
});

it('ignores an attempt to self-grant super admin at registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'organization_name' => 'Sneaky Org',
        'is_super_admin' => true,
        'status' => 'active',
    ])->assertCreated();

    expect(User::where('email', 'sneaky@example.test')->first()->is_super_admin)->toBeFalse();
});

it('rejects a weak password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Weak',
        'email' => 'weak@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'organization_name' => 'Weak Org',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
    expect(User::where('email', 'weak@example.test')->exists())->toBeFalse();
});

it('rejects a duplicate email address', function () {
    registerUser('dupe@example.test', 'First Org');

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Copycat',
        'email' => 'dupe@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'organization_name' => 'Second Org',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');
});

it('gives each organization a unique slug when names collide', function () {
    registerUser('a@example.test', 'Acme');
    registerUser('b@example.test', 'Acme');

    expect(Tenant::pluck('slug')->sort()->values()->all())->toBe(['acme', 'acme-2']);
});
