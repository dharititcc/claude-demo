<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Project;

it('records create, update, and delete of a customer', function () {
    [, $tenant, $token] = registerUser('audit@example.test', 'Audit Org');

    $customer = $tenant->run(function () {
        $c = Customer::factory()->create(['name' => 'Logged Co', 'status' => 'lead']);
        $c->update(['status' => 'active']);
        $c->delete();

        return $c;
    });

    $tenant->run(function () use ($customer) {
        $events = Activity::where('subject_id', $customer->id)->pluck('event')->all();
        expect($events)->toContain('created', 'updated', 'deleted');
    });
});

it('records only the attributes that actually changed on update', function () {
    [, $tenant, $token] = registerUser('dirty@example.test', 'Dirty Org');

    $tenant->run(function () {
        $c = Customer::factory()->create(['name' => 'Before', 'status' => 'lead']);
        Activity::query()->delete(); // ignore the create entry
        $c->update(['status' => 'active']); // only status changes

        $update = Activity::where('event', 'updated')->latest()->first();

        // logOnlyDirty: the change set is just status, not the whole row.
        expect(array_keys($update->properties['attributes']))->toBe(['status'])
            ->and($update->properties['old']['status'])->toBe('lead')
            ->and($update->properties['attributes']['status'])->toBe('active');
    });
});

it('serves the audit log through the api, newest first', function () {
    [, $tenant, $token] = registerUser('auditapi@example.test', 'AuditApi Org');

    $tenant->run(function () {
        Customer::factory()->create(['name' => 'First']);
        Project::factory()->create(['name' => 'A Project']);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonStructure(['data' => [['description', 'event', 'subject_type', 'changes', 'created_at']], 'meta' => ['total']]);

    // At least the two creates, most recent first.
    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(2)
        ->and($response->json('data.0.subject_type'))->toBe('Project'); // created last
});

it('filters the audit log by event type', function () {
    [, $tenant, $token] = registerUser('auditfilter@example.test', 'AuditFilter Org');

    $tenant->run(function () {
        $c = Customer::factory()->create();
        $c->update(['status' => 'active']);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/audit-logs?event=updated')
        ->assertOk();

    expect(collect($response->json('data'))->every(fn ($l) => $l['event'] === 'updated'))->toBeTrue();
});

it('forbids anyone without the audit permission from reading it', function () {
    [$user, $tenant, $token] = registerUser('noaudit@example.test', 'NoAudit Org');
    // Manager lacks audit.view (owner/admin only).
    giveRole($tenant, $user, 'manager');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/audit-logs')
        ->assertStatus(403);
});

it('keeps each organizations audit trail in its own database', function () {
    [, $tenantA, $tokenA] = registerUser('auda@example.test', 'AudA');
    [, $tenantB] = registerUser('audb@example.test', 'AudB');

    $tenantA->run(fn () => Customer::factory()->count(3)->create());
    $tenantB->run(fn () => Customer::factory()->count(1)->create());

    // A's trail must show only A's activity.
    $response = apiAs($tokenA, $tenantA)->getJson('/api/v1/audit-logs')->assertOk();

    // Every subject id A sees must exist in A's own database.
    $subjectIds = collect($response->json('data'))->pluck('subject_id')->filter()->unique();
    $tenantA->run(function () use ($subjectIds) {
        expect(Customer::whereIn('id', $subjectIds)->count())->toBe($subjectIds->count());
    });
});
