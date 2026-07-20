<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests use DatabaseTruncation rather than RefreshDatabase: provisioning
| a tenant issues CREATE DATABASE, and DDL causes an implicit commit in MySQL,
| which would silently break RefreshDatabase's transaction rollback and leak
| rows between tests.
|
*/

uses(TestCase::class, DatabaseTruncation::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Tenant database cleanup
|--------------------------------------------------------------------------
|
| Tenant databases are real MySQL schemas and outlive a truncation, so drop any
| created during a test. TENANT_DB_PREFIX is `testtenant_` under phpunit.xml,
| which keeps this from ever touching development data.
|
*/

uses()->afterEach(function () {
    if (function_exists('tenancy')) {
        tenancy()->end();
    }

    dropTestTenantDatabases();
})->in('Feature');

function dropTestTenantDatabases(): void
{
    $prefix = config('tenancy.database.prefix');

    // Guard: never run without the test-only prefix in place.
    if (! str_starts_with($prefix, 'testtenant_')) {
        return;
    }

    $connection = DB::connection(config('tenancy.database.central_connection'));

    // SHOW DATABASES does not accept bound parameters, so the pattern is
    // inlined. It comes from config and is pinned to the test-only prefix
    // asserted above, never from user input.
    $databases = $connection->select("SHOW DATABASES LIKE '{$prefix}%'");

    foreach ($databases as $database) {
        $name = array_values((array) $database)[0];
        $connection->statement("DROP DATABASE IF EXISTS `{$name}`");
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Register a user through the API and return [user, organization, token].
 *
 * @return array{0: User, 1: Tenant, 2: string}
 */
function registerUser(string $email = 'owner@example.test', string $org = 'Example Org'): array
{
    $response = test()->postJson('/api/v1/auth/register', [
        'name' => 'Test Owner',
        'email' => $email,
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'organization_name' => $org,
    ]);

    $response->assertCreated();

    return [
        User::where('email', $email)->firstOrFail(),
        Tenant::findOrFail($response->json('data.organization.id')),
        $response->json('data.token'),
    ];
}

/**
 * Headers for a tenant-scoped API call against the given organization.
 *
 * @return array<string, string>
 */
function orgHeaders(string $token, Tenant $tenant): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'X-Organization' => $tenant->slug,
    ];
}

/**
 * Begin a tenant-scoped request as a specific user.
 *
 * Use this instead of `withHeaders(orgHeaders(...))` whenever a test acts as
 * more than one user. A real request resolves its bearer token in a fresh
 * container, but the test harness reuses one, and the auth guard caches the
 * first user it resolves — so a later request would silently keep acting as the
 * earlier user regardless of the token it sends.
 */
function apiAs(string $token, Tenant $tenant): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(orgHeaders($token, $tenant));
}

/**
 * Switch this test to the Redis cache store.
 *
 * The suite defaults to the array store, which is fine for speed but useless for
 * asserting cache isolation: its data lives on the store instance, and tenancy
 * replaces that instance on every bootstrap, so values disappear between
 * contexts and reads return null — indistinguishable from correct isolation.
 *
 * Skips the test when Redis is unavailable rather than failing, so the suite
 * still runs on a machine without it. CI provides a Redis service.
 */
function usingRedisCache(): void
{
    $host = config('database.redis.default.host', '127.0.0.1');
    $port = (int) config('database.redis.default.port', 6379);

    $socket = @fsockopen($host, $port, $errno, $errstr, 1);

    if ($socket === false) {
        test()->markTestSkipped("Redis unavailable at {$host}:{$port}; cache isolation cannot be verified on the array store.");
    }

    fclose($socket);

    config(['cache.default' => 'redis']);

    // Drop the resolved manager so the new default is honoured, and keep this
    // test's keys off any real data sharing the server.
    config(['cache.prefix' => 'pest_'.Str::random(8)]);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    Cache::clearResolvedInstances();
}

/**
 * Create an invitation and return its plaintext token.
 *
 * Goes through the service rather than the HTTP endpoint because the token is
 * only ever stored hashed and is never returned in a response — it exists in the
 * emailed link alone. This is the only way a test can obtain it, and that is the
 * property working as intended.
 */
function inviteToOrg(Tenant $tenant, string $email, string $role, User $inviter): string
{
    [, $token] = app(InvitationService::class)
        ->invite($tenant, $email, Role::from($role), $inviter);

    return $token;
}

/**
 * Re-grant a user's role inside an organization, replacing whatever they had.
 * Used to exercise the permission matrix without a second registration.
 */
function giveRole(Tenant $tenant, User $user, string $role): void
{
    $tenant->run(function () use ($user, $role) {
        $user->unsetRelation('roles');
        $user->syncRoles([$role]);
    });

    // The guard caches the resolved user between requests within one test, so a
    // role change would otherwise not be seen by the next request.
    app('auth')->forgetGuards();
}
