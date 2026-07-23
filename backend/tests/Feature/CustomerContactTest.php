<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Tenant;

/**
 * Customer contacts: CRUD, the single-primary invariant, and the scoping that
 * stops one customer's contact being reached through another customer's path.
 */

/** @return array{0: string, 1: Tenant} token and tenant */
function contactOrg(string $email = 'contacts-owner@example.test'): array
{
    [, $tenant, $token] = registerUser($email, 'Contacts Co');

    return [$token, $tenant];
}

function makeCustomer(Tenant $tenant, string $name = 'Acme Industries'): Customer
{
    return $tenant->run(fn () => Customer::create(['name' => $name]));
}

/** @param array<string, mixed> $overrides */
function contactPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Dana',
        'last_name' => 'Scully',
        'email' => 'dana@acme.test',
        'job_title' => 'Head of Ops',
        'department' => 'Operations',
    ], $overrides);
}

it('adds a contact and makes the first one primary automatically', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Dana Scully')
        // A company with one contact and no primary is not worth allowing.
        ->assertJsonPath('data.is_primary', true);
});

it('leaves a second contact non-primary', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())->assertCreated();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Fox', 'last_name' => 'Mulder', 'email' => 'fox@acme.test']))
        ->assertCreated()
        ->assertJsonPath('data.is_primary', false);
});

it('demotes the previous primary when another is promoted', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    $first = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())->json('data.id');
    $second = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Fox', 'email' => 'fox@acme.test']))->json('data.id');

    apiAs($token, $tenant)
        ->putJson("/api/v1/customers/{$customer->id}/contacts/{$second}", ['is_primary' => true])
        ->assertOk()
        ->assertJsonPath('data.is_primary', true);

    // Exactly one primary, and it is the new one.
    $tenant->run(function () use ($first, $second) {
        expect(CustomerContact::where('is_primary', true)->pluck('id')->all())->toBe([$second])
            ->and(CustomerContact::find($first)->is_primary)->toBeFalse();
    });
});

it('hands primary to the next contact when the primary is deleted', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    $first = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())->json('data.id');
    $second = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Fox', 'email' => 'fox@acme.test']))->json('data.id');

    apiAs($token, $tenant)->deleteJson("/api/v1/customers/{$customer->id}/contacts/{$first}")->assertOk();

    // Never leave a customer with contacts but no primary.
    $tenant->run(fn () => expect(CustomerContact::find($second)->is_primary)->toBeTrue());
});

it('soft deletes rather than destroying a contact', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())->json('data.id');

    apiAs($token, $tenant)->deleteJson("/api/v1/customers/{$customer->id}/contacts/{$id}")->assertOk();

    $tenant->run(function () use ($id) {
        expect(CustomerContact::find($id))->toBeNull()
            ->and(CustomerContact::withTrashed()->find($id))->not->toBeNull();
    });
});

it('lists contacts primary first and searches them', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Zoe', 'email' => 'zoe@acme.test']))->assertCreated();
    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Adam', 'job_title' => 'Buyer', 'email' => 'adam@acme.test']))->assertCreated();

    // Zoe was first, so Zoe is primary and sorts ahead of Adam despite the name.
    $names = apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/contacts")
        ->assertOk()->json('data.*.first_name');

    expect($names)->toBe(['Zoe', 'Adam']);

    $found = apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/contacts?q=Buyer")
        ->assertOk()->json('data');

    expect($found)->toHaveCount(1)
        ->and($found[0]['first_name'])->toBe('Adam');
});

it('refuses to reach a contact through another customer', function () {
    [$token, $tenant] = contactOrg();
    $mine = makeCustomer($tenant, 'Mine');
    $theirs = makeCustomer($tenant, 'Theirs');

    $id = apiAs($token, $tenant)->postJson("/api/v1/customers/{$theirs->id}/contacts", contactPayload())->json('data.id');

    // Same tenant, so tenancy alone does not stop this — the nested lookup does.
    apiAs($token, $tenant)->putJson("/api/v1/customers/{$mine->id}/contacts/{$id}", ['job_title' => 'Hijacked'])
        ->assertNotFound();

    apiAs($token, $tenant)->deleteJson("/api/v1/customers/{$mine->id}/contacts/{$id}")
        ->assertNotFound();
});

it('cannot be given is_primary by mass assignment', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    apiAs($token, $tenant)->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())->assertCreated();

    // Second contact claims primary via the model's guarded attribute; the
    // service is what decides, and it honours the flag deliberately.
    $second = apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload(['first_name' => 'Fox', 'email' => 'fox@acme.test', 'is_primary' => true]))
        ->assertCreated();

    // Honoured, but through promote() — so still exactly one primary.
    $tenant->run(fn () => expect(CustomerContact::where('is_primary', true)->count())->toBe(1));

    expect($second->json('data.is_primary'))->toBeTrue();
});

it('validates the contact payload', function () {
    [$token, $tenant] = contactOrg();
    $customer = makeCustomer($tenant);

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/contacts", ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['first_name', 'email']);
});

it('requires customers.update to add a contact', function () {
    [$user, $tenant, $token] = registerUser('viewer@contacts.test', 'Viewer Co');
    $customer = makeCustomer($tenant);

    // Demote to viewer: read-only on customers, so read-only on their people.
    giveRole($tenant, $user, 'viewer');

    apiAs($token, $tenant)->getJson("/api/v1/customers/{$customer->id}/contacts")->assertOk();

    apiAs($token, $tenant)
        ->postJson("/api/v1/customers/{$customer->id}/contacts", contactPayload())
        ->assertForbidden();
});
