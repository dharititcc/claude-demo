<?php

declare(strict_types=1);

use App\Models\Customer;

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('lists customers for the active organization', function () {
    [, $tenant, $token] = registerUser('list@example.test', 'List Org');

    $tenant->run(fn () => Customer::factory()->count(3)->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'status', 'tags']], 'meta' => ['total']]);
});

it('creates a customer and defaults ownership to the creator', function () {
    [$user, $tenant, $token] = registerUser('create@example.test', 'Create Org');

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', [
            'name' => 'Wayne Enterprises',
            'email' => 'contact@wayne.test',
            'company' => 'Wayne Enterprises',
            'status' => 'active',
            'tags' => ['vip', 'enterprise'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Wayne Enterprises')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.owner_id', $user->id);

    expect($response->json('data.tags'))->toHaveCount(2);

    $tenant->run(function () {
        expect(Customer::where('name', 'Wayne Enterprises')->exists())->toBeTrue();
    });
});

it('shows a single customer with its notes and tags', function () {
    [, $tenant, $token] = registerUser('show@example.test', 'Show Org');

    $customer = $tenant->run(fn () => Customer::factory()->create(['name' => 'Acme Corp']));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Corp')
        ->assertJsonStructure(['data' => ['tags', 'notes', 'attachments']]);
});

it('updates a customer', function () {
    [, $tenant, $token] = registerUser('update@example.test', 'Update Org');

    $customer = $tenant->run(fn () => Customer::factory()->lead()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Renamed Ltd',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Ltd')
        ->assertJsonPath('data.status', 'active');
});

it('leaves tags untouched when the update omits them', function () {
    [, $tenant, $token] = registerUser('tagkeep@example.test', 'TagKeep Org');

    $customer = $tenant->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/customers/{$customer->id}", ['tags' => ['keep-me']])
        ->assertOk();

    // No `tags` key at all — the existing tag must survive.
    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Still Tagged'])
        ->assertOk();

    expect($response->json('data.tags'))->toHaveCount(1)
        ->and($response->json('data.tags.0.name'))->toBe('keep-me');
});

it('soft deletes a customer and can restore it', function () {
    [, $tenant, $token] = registerUser('delete@example.test', 'Delete Org');

    $customer = $tenant->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertOk();

    $tenant->run(function () use ($customer) {
        expect(Customer::find($customer->id))->toBeNull()
            ->and(Customer::withTrashed()->find($customer->id))->not->toBeNull();
    });

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/customers/{$customer->id}/restore")
        ->assertOk();

    $tenant->run(fn () => expect(Customer::find($customer->id))->not->toBeNull());
});

/*
|--------------------------------------------------------------------------
| Search, filter, sort
|--------------------------------------------------------------------------
*/

it('searches across name, email, and company', function () {
    [, $tenant, $token] = registerUser('search@example.test', 'Search Org');

    $tenant->run(function () {
        Customer::factory()->create(['name' => 'Findable Person', 'email' => 'a@x.test', 'company' => 'Alpha']);
        Customer::factory()->create(['name' => 'Other Person', 'email' => 'findable@y.test', 'company' => 'Beta']);
        Customer::factory()->create(['name' => 'Nobody', 'email' => 'c@z.test', 'company' => 'Findable Inc']);
        Customer::factory()->create(['name' => 'Unrelated', 'email' => 'd@w.test', 'company' => 'Gamma']);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?q=findable')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(3);
});

it('treats LIKE wildcards in search as literal characters', function () {
    [, $tenant, $token] = registerUser('wild@example.test', 'Wild Org');

    $tenant->run(function () {
        Customer::factory()->create(['name' => 'Normal Customer']);
        Customer::factory()->create(['name' => '100% Cotton Co']);
    });

    // A bare "%" must not match every row.
    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?q=%25')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.name'))->toBe('100% Cotton Co');
});

it('filters by status', function () {
    [, $tenant, $token] = registerUser('filter@example.test', 'Filter Org');

    $tenant->run(function () {
        Customer::factory()->count(2)->active()->create();
        Customer::factory()->count(3)->lead()->create();
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?status=active')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(2);

    $multi = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?status=active,lead')
        ->assertOk();

    expect($multi->json('meta.total'))->toBe(5);
});

it('sorts by an allowed column', function () {
    [, $tenant, $token] = registerUser('sort@example.test', 'Sort Org');

    $tenant->run(function () {
        Customer::factory()->create(['name' => 'Charlie']);
        Customer::factory()->create(['name' => 'Alpha']);
        Customer::factory()->create(['name' => 'Bravo']);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?sort=name&direction=asc')
        ->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('rejects an unknown sort column instead of trusting it', function () {
    [, $tenant, $token] = registerUser('badsort@example.test', 'BadSort Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?sort=password')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('paginates and caps the page size', function () {
    [, $tenant, $token] = registerUser('page@example.test', 'Page Org');

    $tenant->run(fn () => Customer::factory()->count(20)->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.last_page', 4);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers?per_page=5000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

/*
|--------------------------------------------------------------------------
| Tenant isolation — the core guarantee
|--------------------------------------------------------------------------
*/

it('never exposes another organizations customers', function () {
    [, $tenantA, $tokenA] = registerUser('orga@example.test', 'Org Alpha');
    [, $tenantB, $tokenB] = registerUser('orgb@example.test', 'Org Beta');

    $tenantA->run(fn () => Customer::factory()->count(3)->create(['company' => 'Alpha Only']));
    $tenantB->run(fn () => Customer::factory()->count(2)->create(['company' => 'Beta Only']));

    // apiAs(): this test acts as two different users, so each request must
    // resolve its own token rather than reuse the guard's cached user.
    $a = apiAs($tokenA, $tenantA)->getJson('/api/v1/customers')->assertOk();
    $b = apiAs($tokenB, $tenantB)->getJson('/api/v1/customers')->assertOk();

    expect($a->json('meta.total'))->toBe(3)
        ->and($b->json('meta.total'))->toBe(2)
        ->and(collect($a->json('data'))->pluck('company')->unique()->all())->toBe(['Alpha Only'])
        ->and(collect($b->json('data'))->pluck('company')->unique()->all())->toBe(['Beta Only']);
});

it('cannot read a customer by id across organizations', function () {
    [, $tenantA, $tokenA] = registerUser('reader@example.test', 'Reader Org');
    [, $tenantB] = registerUser('victim@example.test', 'Victim Org');

    $victim = $tenantB->run(fn () => Customer::factory()->create(['name' => 'Secret Client']));

    // Same numeric id exists in B; A must not see it even when guessing.
    $this->withHeaders(orgHeaders($tokenA, $tenantA))
        ->getJson("/api/v1/customers/{$victim->id}")
        ->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| Authorization (RBAC)
|--------------------------------------------------------------------------
*/

it('forbids a viewer from creating a customer', function () {
    [$user, $tenant, $token] = registerUser('viewer@example.test', 'Viewer Org');
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'Should Not Exist'])
        ->assertStatus(403);

    $tenant->run(fn () => expect(Customer::where('name', 'Should Not Exist')->exists())->toBeFalse());
});

it('allows a viewer to read customers', function () {
    [$user, $tenant, $token] = registerUser('viewread@example.test', 'ViewRead Org');
    $tenant->run(fn () => Customer::factory()->count(2)->create());
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('forbids an employee from deleting a customer', function () {
    [$user, $tenant, $token] = registerUser('emp@example.test', 'Employee Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());
    giveRole($tenant, $user, 'employee');

    // Employees may update...
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Edited By Employee'])
        ->assertOk();

    // ...but not delete.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertStatus(403);
});

it('allows a manager to delete a customer', function () {
    [$user, $tenant, $token] = registerUser('mgr@example.test', 'Manager Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());
    giveRole($tenant, $user, 'manager');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Validation, notes, export
|--------------------------------------------------------------------------
*/

it('requires a name', function () {
    [, $tenant, $token] = registerUser('valid@example.test', 'Valid Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['email' => 'no-name@example.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects an invalid status', function () {
    [, $tenant, $token] = registerUser('status@example.test', 'Status Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'Bad Status', 'status' => 'exploded'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('rejects an owner_id belonging to another organizations user', function () {
    [, $tenant, $token] = registerUser('owncheck@example.test', 'OwnCheck Org');

    // A user in a different organization: exists in the central users table, but
    // is not a member of this tenant, so must be rejected on owner_id.
    [$foreign] = registerUser('foreign@example.test', 'Foreign Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'Cross Tenant', 'owner_id' => $foreign->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('owner_id');
});

it('accepts an owner_id belonging to a member of this organization', function () {
    [$user, $tenant, $token] = registerUser('ownmember@example.test', 'OwnMember Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/customers', ['name' => 'Own Member', 'owner_id' => $user->id])
        ->assertCreated()
        ->assertJsonPath('data.owner_id', $user->id);
});

it('adds a note to a customer', function () {
    [$user, $tenant, $token] = registerUser('note@example.test', 'Note Org');
    $customer = $tenant->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/customers/{$customer->id}/notes", ['body' => 'Called about renewal.'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Called about renewal.')
        ->assertJsonPath('data.user_id', $user->id);
});

it('exports customers as csv honouring the active filter', function () {
    [, $tenant, $token] = registerUser('export@example.test', 'Export Org');

    $tenant->run(function () {
        Customer::factory()->count(2)->active()->create(['company' => 'Exported Co']);
        Customer::factory()->count(3)->lead()->create();
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->get('/api/v1/customers/export?status=active');

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    $lines = array_filter(explode("\n", trim($csv)));

    // Header + 2 active customers only.
    expect($lines)->toHaveCount(3)
        ->and($csv)->toContain('Exported Co');
});

it('forbids a viewer from exporting', function () {
    [$user, $tenant, $token] = registerUser('noexport@example.test', 'NoExport Org');
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->get('/api/v1/customers/export')
        ->assertStatus(403);
});
