<?php

declare(strict_types=1);

use App\Models\AdminActivity;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/**
 * @return array{0: User, 1: string} the super admin and their token
 */
function impersonationAdmin(): array
{
    [$user, , $token] = registerUser('imp-admin@example.test', 'Impersonation HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return [$user, $token];
}

function bearer(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => "Bearer {$token}"]);
}

/**
 * Start an impersonation and return the impersonation token.
 */
function startImpersonation(string $adminToken, Tenant $org, ?int $userId = null): string
{
    return bearer($adminToken)
        ->postJson("/api/v1/admin/organizations/{$org->id}/impersonate", $userId ? ['user_id' => $userId] : [])
        ->assertCreated()
        ->json('data.token');
}

it('issues a token that acts as the organization owner', function () {
    [, $adminToken] = impersonationAdmin();
    [$owner, $acme] = registerUser('owner@acme.test', 'Acme');

    $response = bearer($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/impersonate")->assertCreated();

    expect($response->json('data.user.email'))->toBe('owner@acme.test')
        ->and($response->json('data.expires_at'))->not->toBeNull();

    // The token authenticates as the owner, not the admin.
    bearer($response->json('data.token'))->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'owner@acme.test');
});

it('signals the impersonation on the me endpoint', function () {
    [$admin, $adminToken] = impersonationAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $token = startImpersonation($adminToken, $acme);

    bearer($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('impersonation.active', true)
        ->assertJsonPath('impersonation.impersonator.email', 'imp-admin@example.test')
        ->assertJsonPath('impersonation.organization_id', $acme->id);
});

it('reports no impersonation for an ordinary token', function () {
    [, $acme, $ownerToken] = registerUser('owner@acme.test', 'Acme');

    bearer($ownerToken)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('impersonation', null);
});

it('confines the impersonation token to the one organization', function () {
    [, $adminToken] = impersonationAdmin();
    // A user who belongs to BOTH orgs.
    [$owner, $acme] = registerUser('multi@example.test', 'Acme');
    [, $globex] = registerUser('gowner@example.test', 'Globex');
    $globex->run(fn () => null); // ensure provisioned
    $globex->members()->attach($owner->id, ['is_owner' => false]);

    $token = startImpersonation($adminToken, $acme, $owner->id);

    // Allowed in the impersonated org...
    bearer($token)->withHeaders(['X-Organization' => $acme->slug])->getJson('/api/v1/customers')->assertOk();

    // ...blocked from the target's OTHER org, even though the user is a member.
    bearer($token)->withHeaders(['X-Organization' => $globex->slug])->getJson('/api/v1/customers')->assertForbidden();
});

it('cannot reach the admin surface', function () {
    [, $adminToken] = impersonationAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $token = startImpersonation($adminToken, $acme);

    // The impersonated user is not a super admin, so the admin gate 404s it.
    bearer($token)->getJson('/api/v1/admin/organizations')->assertNotFound();
});

it('refuses to impersonate another super admin', function () {
    [, $adminToken] = impersonationAdmin();
    [$owner, $acme] = registerUser('owner@acme.test', 'Acme');
    $owner->forceFill(['is_super_admin' => true])->save();

    bearer($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/impersonate", ['user_id' => $owner->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

it('refuses to impersonate a non-member', function () {
    [, $adminToken] = impersonationAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');
    [$stranger] = registerUser('stranger@example.test', 'Stranger Org');

    bearer($adminToken)->postJson("/api/v1/admin/organizations/{$acme->id}/impersonate", ['user_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');
});

it('lets the impersonated session stop, revoking the token', function () {
    [, $adminToken] = impersonationAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $token = startImpersonation($adminToken, $acme);

    bearer($token)->postJson('/api/v1/impersonation/stop')->assertOk();

    // The token is dead the moment the session ends, before its expiry.
    bearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('will not stop a non-impersonation session', function () {
    [, $acme, $ownerToken] = registerUser('owner@acme.test', 'Acme');

    // An ordinary login has nothing to stop.
    bearer($ownerToken)->postJson('/api/v1/impersonation/stop')->assertStatus(409);

    // ...and the ordinary token still works afterwards.
    bearer($ownerToken)->getJson('/api/v1/auth/me')->assertOk();
});

it('audits both the start and the stop', function () {
    [$admin, $adminToken] = impersonationAdmin();
    [, $acme] = registerUser('owner@acme.test', 'Acme');

    $token = startImpersonation($adminToken, $acme);
    bearer($token)->postJson('/api/v1/impersonation/stop')->assertOk();

    $actions = AdminActivity::where('target_id', $acme->id)->pluck('action');

    expect($actions)->toContain('organization.impersonation.started')
        ->and($actions)->toContain('organization.impersonation.stopped');

    $start = AdminActivity::where('action', 'organization.impersonation.started')->first();
    expect($start->admin_id)->toBe($admin->id)
        ->and($start->properties['target_email'])->toBe('owner@acme.test');
});

it('stores the impersonation token tagged with actor and org', function () {
    [$admin, $adminToken] = impersonationAdmin();
    [$owner, $acme] = registerUser('owner@acme.test', 'Acme');

    startImpersonation($adminToken, $acme);

    // The token on the target user carries the real actor and the org scope, and
    // has a bounded lifetime.
    $token = PersonalAccessToken::where('tokenable_id', $owner->id)
        ->whereNotNull('impersonator_id')
        ->first();

    expect($token)->not->toBeNull()
        ->and($token->impersonator_id)->toBe($admin->id)
        ->and($token->impersonated_tenant_id)->toBe($acme->id)
        ->and($token->expires_at)->not->toBeNull()
        ->and($token->isImpersonation())->toBeTrue();
});
