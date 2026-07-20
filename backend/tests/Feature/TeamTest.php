<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Invitation;
use App\Notifications\OrganizationInvitation;
use App\Services\OrganizationService;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Members
|--------------------------------------------------------------------------
*/

it('lists members with the role they hold in this organization', function () {
    [$owner, $tenant, $token] = registerUser('teamowner@example.test', 'Team Org');

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/members')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($owner->id)
        ->and($response->json('data.0.role'))->toBe('owner')
        ->and($response->json('data.0.is_owner'))->toBeTrue();
});

it('reports the same person with a different role in each organization', function () {
    [$user, $tenantA, $token] = registerUser('dual@example.test', 'Dual A');
    [, $tenantB] = registerUser('bowner@example.test', 'Dual B');

    app(OrganizationService::class)
        ->addMember($tenantB, $user, Role::Manager);

    $inA = $this->withHeaders(orgHeaders($token, $tenantA))->getJson('/api/v1/members')->assertOk();
    $inB = $this->withHeaders(orgHeaders($token, $tenantB))->getJson('/api/v1/members')->assertOk();

    $roleInA = collect($inA->json('data'))->firstWhere('id', $user->id)['role'];
    $roleInB = collect($inB->json('data'))->firstWhere('id', $user->id)['role'];

    expect($roleInA)->toBe('owner')->and($roleInB)->toBe('manager');
});

it('forbids a viewer from seeing team management actions', function () {
    [$user, $tenant, $token] = registerUser('vteam@example.test', 'Viewer Team');
    giveRole($tenant, $user, 'viewer');

    // Viewers may see who is on the team...
    $this->withHeaders(orgHeaders($token, $tenant))->getJson('/api/v1/members')->assertOk();

    // ...but not invite.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/members/invitations', ['email' => 'x@example.test', 'role' => 'viewer'])
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
*/

it('invites someone by email and sends them a link', function () {
    Notification::fake();

    [, $tenant, $token] = registerUser('inviter@example.test', 'Invite Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/members/invitations', ['email' => 'NewPerson@Example.test', 'role' => 'manager'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'newperson@example.test') // normalised
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.pending', true);

    // Invitations live in the central database. assertDatabaseHas() queries the
    // *default* connection, which is still the tenant's after a tenant-scoped
    // request, so the connection must be named explicitly. (The Invitation model
    // below needs no such help — it is pinned to central.)
    $this->assertDatabaseHas(
        'invitations',
        ['email' => 'newperson@example.test', 'tenant_id' => $tenant->id],
        config('tenancy.database.central_connection'),
    );

    // The Invitation model is the notifiable (the invitee may have no account),
    // so this is assertSentTo rather than assertSentOnDemand.
    Notification::assertSentTo(
        Invitation::where('email', 'newperson@example.test')->firstOrFail(),
        OrganizationInvitation::class,
    );
});

it('never stores the invitation token in plaintext', function () {
    Notification::fake();
    [, $tenant, $token] = registerUser('hash@example.test', 'Hash Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/members/invitations', ['email' => 'hashed@example.test', 'role' => 'viewer'])
        ->assertCreated()
        // The response must not leak it either — only the email carries it.
        ->assertJsonMissingPath('data.token');

    $invitation = Invitation::where('email', 'hashed@example.test')->firstOrFail();

    expect($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->token_hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('refuses to invite someone who is already a member', function () {
    Notification::fake();
    [, $tenant, $token] = registerUser('dupe2@example.test', 'Dupe Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/members/invitations', ['email' => 'dupe2@example.test', 'role' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('replaces a previous invitation rather than stacking duplicates', function () {
    Notification::fake();
    [, $tenant, $token] = registerUser('replace@example.test', 'Replace Org');

    foreach (['viewer', 'manager'] as $role) {
        $this->withHeaders(orgHeaders($token, $tenant))
            ->postJson('/api/v1/members/invitations', ['email' => 'twice@example.test', 'role' => $role])
            ->assertCreated();
    }

    expect(Invitation::where('email', 'twice@example.test')->count())->toBe(1)
        ->and(Invitation::where('email', 'twice@example.test')->first()->role)->toBe('manager');
});

it('lets an invited user accept and join with the invited role', function () {
    Notification::fake();
    [$owner, $tenant] = registerUser('host@example.test', 'Host Org');

    // The invitee already has an account (and their own organization).
    [$guest, , $guestToken] = registerUser('guest@example.test', 'Guest Own Org');

    // The token is only ever stored hashed and never returned by the API, so a
    // test can obtain it exactly where a real invitee does: from the service
    // that generated it (which the mail then carries).
    $plain = inviteToOrg($tenant, 'guest@example.test', 'manager', $owner);

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$guestToken}")
        ->postJson("/api/v1/invitations/{$plain}/accept")
        ->assertOk()
        ->assertJsonPath('data.slug', $tenant->slug);

    expect($guest->fresh()->belongsToOrganization($tenant->id))->toBeTrue();

    $tenant->run(function () use ($guest) {
        $guest->unsetRelation('roles');
        expect($guest->hasRole('manager'))->toBeTrue();
    });

    expect(Invitation::where('email', 'guest@example.test')->first()->accepted_at)->not->toBeNull();
});

it('refuses an invitation issued to a different email address', function () {
    Notification::fake();
    [$owner, $tenant] = registerUser('owner3@example.test', 'Owner3 Org');
    [, , $intruderToken] = registerUser('intruder@example.test', 'Intruder Org');

    $plain = inviteToOrg($tenant, 'intended@example.test', 'admin', $owner);

    // Holding the link is not enough — it is bound to the invited address.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$intruderToken}")
        ->postJson("/api/v1/invitations/{$plain}/accept")
        ->assertStatus(422);
});

it('rejects an expired invitation', function () {
    Notification::fake();
    [$owner, $tenant] = registerUser('exp@example.test', 'Expiry Org');
    [, , $guestToken] = registerUser('late@example.test', 'Late Org');

    $plain = inviteToOrg($tenant, 'late@example.test', 'viewer', $owner);

    Invitation::where('email', 'late@example.test')->update(['expires_at' => now()->subDay()]);

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$guestToken}")
        ->postJson("/api/v1/invitations/{$plain}/accept")
        ->assertStatus(422);
});

it('rejects a made-up invitation token', function () {
    [, , $token] = registerUser('fake@example.test', 'Fake Org');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/invitations/totally-made-up/accept')
        ->assertStatus(422);

    $this->getJson('/api/v1/invitations/totally-made-up')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Roles & removal
|--------------------------------------------------------------------------
*/

it('changes a members role', function () {
    [, $tenant, $token] = registerUser('promoter@example.test', 'Promote Org');
    [$member] = registerUser('member@example.test', 'Member Own Org');

    app(OrganizationService::class)
        ->addMember($tenant, $member, Role::Viewer);

    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/members/{$member->id}/role", ['role' => 'admin'])
        ->assertOk();

    $tenant->run(function () use ($member) {
        $member->unsetRelation('roles');
        expect($member->hasRole('admin'))->toBeTrue()
            ->and($member->hasRole('viewer'))->toBeFalse();
    });
});

it('refuses to demote the only owner', function () {
    [$owner, $tenant, $token] = registerUser('lastowner@example.test', 'Last Owner Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/members/{$owner->id}/role", ['role' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user');

    $tenant->run(function () use ($owner) {
        $owner->unsetRelation('roles');
        expect($owner->hasRole('owner'))->toBeTrue();
    });
});

it('refuses to remove yourself', function () {
    [$owner, $tenant, $token] = registerUser('self@example.test', 'Self Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/members/{$owner->id}")
        ->assertStatus(422);
});

it('removes a member and revokes their role in that organization only', function () {
    [, $tenant, $token] = registerUser('remover@example.test', 'Remove Org');
    [$member, $ownOrg] = registerUser('removed@example.test', 'Their Own Org');

    app(OrganizationService::class)
        ->addMember($tenant, $member, Role::Employee);

    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/members/{$member->id}")
        ->assertOk();

    expect($member->fresh()->belongsToOrganization($tenant->id))->toBeFalse()
        // Their own organization is untouched.
        ->and($member->fresh()->belongsToOrganization($ownOrg->id))->toBeTrue();

    $ownOrg->run(function () use ($member) {
        $member->unsetRelation('roles');
        expect($member->hasRole('owner'))->toBeTrue();
    });
});

it('404s when acting on a user who is not a member', function () {
    [, $tenant, $token] = registerUser('nm@example.test', 'NonMember Org');
    [$outsider] = registerUser('outsider@example.test', 'Outsider Org');

    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/members/{$outsider->id}/role", ['role' => 'admin'])
        ->assertStatus(404);
});
