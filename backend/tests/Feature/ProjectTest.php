<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Project;
use App\Models\Task;

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('lists projects with task progress', function () {
    [, $tenant, $token] = registerUser('proj@example.test', 'Proj Org');

    $tenant->run(function () {
        $p = Project::factory()->active()->create(['name' => 'Website Redesign']);
        Task::factory()->count(3)->done()->create(['project_id' => $p->id]);
        Task::factory()->count(1)->todo()->create(['project_id' => $p->id]);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $project = $response->json('data.0');
    expect($project['tasks_count'])->toBe(4)
        ->and($project['completed_tasks_count'])->toBe(3)
        ->and($project['progress'])->toBe(75); // 3 of 4
});

it('creates a project and defaults ownership to the creator', function () {
    [$user, $tenant, $token] = registerUser('createproj@example.test', 'CreateProj Org');

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/projects', [
            'name' => 'Q3 Launch',
            'status' => 'active',
            'color' => '#ff0000',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Q3 Launch')
        ->assertJsonPath('data.slug', 'q3-launch')
        ->assertJsonPath('data.owner_id', $user->id);

    expect($response->json('data.color'))->toBe('#ff0000');
});

it('derives completed_at from status', function () {
    [, $tenant, $token] = registerUser('complete@example.test', 'Complete Org');
    $project = $tenant->run(fn () => Project::factory()->active()->create());

    // Marking complete stamps the date...
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/projects/{$project->id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $tenant->run(fn () => expect(Project::find($project->id)->completed_at)->not->toBeNull());

    // ...and reopening clears it, so it can never disagree with status.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/projects/{$project->id}", ['status' => 'active'])
        ->assertOk();

    $tenant->run(fn () => expect(Project::find($project->id)->completed_at)->toBeNull());
});

it('links a project to a customer', function () {
    [, $tenant, $token] = registerUser('link@example.test', 'Link Org');
    $customer = $tenant->run(fn () => Customer::factory()->create(['name' => 'Big Client']));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/projects', ['name' => 'Client Work', 'customer_id' => $customer->id])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id);
});

it('rejects a customer id that does not exist in this organization', function () {
    [, $tenantA, $tokenA] = registerUser('xa@example.test', 'X A');
    [, $tenantB] = registerUser('xb@example.test', 'X B');

    // A customer in B must not be attachable to a project in A.
    $foreign = $tenantB->run(fn () => Customer::factory()->create());

    $this->withHeaders(orgHeaders($tokenA, $tenantA))
        ->postJson('/api/v1/projects', ['name' => 'Cross', 'customer_id' => $foreign->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer_id');
});

it('filters projects that are overdue', function () {
    [, $tenant, $token] = registerUser('overdue@example.test', 'Overdue Org');

    $tenant->run(function () {
        Project::factory()->overdue()->create(['name' => 'Late One']);
        Project::factory()->create(['status' => 'active', 'due_on' => now()->addWeek()]);
        // Completed projects are never "overdue" even with a past due date.
        Project::factory()->create(['status' => 'completed', 'due_on' => now()->subWeek()]);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/projects?overdue=1')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.name'))->toBe('Late One')
        ->and($response->json('data.0.is_overdue'))->toBeTrue();
});

it('soft deletes and restores a project', function () {
    [, $tenant, $token] = registerUser('delproj@example.test', 'DelProj Org');
    $project = $tenant->run(fn () => Project::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/projects/{$project->id}")
        ->assertOk();

    $tenant->run(fn () => expect(Project::find($project->id))->toBeNull());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/projects/{$project->id}/restore")
        ->assertOk();

    $tenant->run(fn () => expect(Project::find($project->id))->not->toBeNull());
});

/*
|--------------------------------------------------------------------------
| Isolation & authorization
|--------------------------------------------------------------------------
*/

it('never exposes another organizations projects', function () {
    [, $tenantA, $tokenA] = registerUser('pa@example.test', 'PA');
    [, $tenantB, $tokenB] = registerUser('pb@example.test', 'PB');

    $tenantA->run(fn () => Project::factory()->count(2)->create());
    $tenantB->run(fn () => Project::factory()->count(5)->create());

    $a = apiAs($tokenA, $tenantA)->getJson('/api/v1/projects')->assertOk();
    $b = apiAs($tokenB, $tenantB)->getJson('/api/v1/projects')->assertOk();

    expect($a->json('meta.total'))->toBe(2)
        ->and($b->json('meta.total'))->toBe(5);
});

it('forbids a viewer from creating a project', function () {
    [$user, $tenant, $token] = registerUser('vproj@example.test', 'VProj Org');
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/projects', ['name' => 'Nope'])
        ->assertStatus(403);
});

it('forbids an employee from deleting a project', function () {
    [$user, $tenant, $token] = registerUser('eproj@example.test', 'EProj Org');
    $project = $tenant->run(fn () => Project::factory()->create());
    giveRole($tenant, $user, 'employee');

    // Employees may update but not delete.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/projects/{$project->id}", ['name' => 'Edited'])
        ->assertOk();

    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/projects/{$project->id}")
        ->assertStatus(403);
});
