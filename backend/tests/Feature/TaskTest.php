<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\OrganizationService;

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('creates a task appended to the end of its column', function () {
    [, $tenant, $token] = registerUser('mktask@example.test', 'MkTask Org');
    $project = $tenant->run(fn () => Project::factory()->create());

    $first = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/tasks', ['title' => 'First', 'project_id' => $project->id, 'status' => 'todo'])
        ->assertCreated();

    $second = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/tasks', ['title' => 'Second', 'project_id' => $project->id, 'status' => 'todo'])
        ->assertCreated();

    // Appended, so the second sits after the first.
    expect($second->json('data.position'))->toBeGreaterThan($first->json('data.position'));
});

it('creates a task with labels, creating labels that are new', function () {
    [, $tenant, $token] = registerUser('lbltask@example.test', 'LblTask Org');

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/tasks', ['title' => 'Bug fix', 'labels' => ['bug', 'urgent']])
        ->assertCreated();

    expect($response->json('data.labels'))->toHaveCount(2)
        ->and(collect($response->json('data.labels'))->pluck('name')->all())->toContain('bug', 'urgent');
});

it('derives completed_at when a task is marked done', function () {
    [, $tenant, $token] = registerUser('donetask@example.test', 'DoneTask Org');
    $task = $tenant->run(fn () => Task::factory()->todo()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/tasks/{$task->id}", ['status' => 'done'])
        ->assertOk();

    $tenant->run(fn () => expect(Task::find($task->id)->completed_at)->not->toBeNull());
});

it('nests subtasks under a parent and deletes them with it', function () {
    [, $tenant, $token] = registerUser('subtask@example.test', 'Subtask Org');
    $parent = $tenant->run(fn () => Task::factory()->create(['title' => 'Parent']));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/tasks', ['title' => 'Child', 'parent_id' => $parent->id])
        ->assertCreated();

    // A root-only listing must not show the subtask twice.
    $roots = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/tasks')
        ->assertOk();
    expect($roots->json('meta.total'))->toBe(1); // just the parent

    // Soft-deleting the parent soft-deletes the subtask too, so it does not
    // resurface orphaned at the root of the board.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->deleteJson("/api/v1/tasks/{$parent->id}")
        ->assertOk();

    $tenant->run(function () {
        expect(Task::where('title', 'Child')->exists())->toBeFalse()       // hidden
            ->and(Task::withTrashed()->where('title', 'Child')->exists())->toBeTrue(); // recoverable
    });
});

/*
|--------------------------------------------------------------------------
| Kanban board & movement
|--------------------------------------------------------------------------
*/

it('returns every column on the board even when empty', function () {
    [, $tenant, $token] = registerUser('board@example.test', 'Board Org');
    $tenant->run(function () {
        Task::factory()->count(2)->create(['status' => 'todo']);
        Task::factory()->count(1)->create(['status' => 'done']);
    });

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/tasks/board')
        ->assertOk();

    $columns = collect($response->json('data'));

    // All four columns present, in board order, regardless of contents.
    expect($columns->pluck('status')->all())->toBe(['todo', 'in_progress', 'review', 'done'])
        ->and($columns->firstWhere('status', 'todo')['count'])->toBe(2)
        ->and($columns->firstWhere('status', 'in_progress')['count'])->toBe(0)
        ->and($columns->firstWhere('status', 'done')['count'])->toBe(1);
});

it('moves a task into another column and updates its status', function () {
    [, $tenant, $token] = registerUser('move@example.test', 'Move Org');
    $task = $tenant->run(fn () => Task::factory()->todo()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/tasks/{$task->id}/move", ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');
});

it('reorders a task by dropping it above another', function () {
    [, $tenant, $token] = registerUser('reorder@example.test', 'Reorder Org');

    [$a, $b, $c] = $tenant->run(function () {
        return [
            Task::factory()->create(['status' => 'todo', 'position' => 1000]),
            Task::factory()->create(['status' => 'todo', 'position' => 2000]),
            Task::factory()->create(['status' => 'todo', 'position' => 3000]),
        ];
    });

    // Drop C above B: it should land between A and B.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/tasks/{$c->id}/move", ['status' => 'todo', 'before_id' => $b->id])
        ->assertOk();

    $order = $tenant->run(fn () => Task::where('status', 'todo')->orderBy('position')->pluck('id')->all());

    expect($order)->toBe([$a->id, $c->id, $b->id]);
});

it('rejects moving to an invalid column', function () {
    [, $tenant, $token] = registerUser('badmove@example.test', 'BadMove Org');
    $task = $tenant->run(fn () => Task::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/tasks/{$task->id}/move", ['status' => 'archived'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

/*
|--------------------------------------------------------------------------
| Time tracking
|--------------------------------------------------------------------------
*/

it('starts and stops a timer, accumulating tracked time', function () {
    [, $tenant, $token] = registerUser('timer@example.test', 'Timer Org');
    $task = $tenant->run(fn () => Task::factory()->create());

    $start = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$task->id}/time/start")
        ->assertCreated()
        ->assertJsonPath('data.running', true);

    expect($start->json('data.ended_at'))->toBeNull();

    // Backdate the start so stopping records a non-zero duration.
    $tenant->run(fn () => TimeEntry::where('task_id', $task->id)->update(['started_at' => now()->subMinutes(5)]));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$task->id}/time/stop")
        ->assertOk()
        ->assertJsonPath('data.running', false);

    $tenant->run(fn () => expect(Task::find($task->id)->tracked_seconds)->toBeGreaterThan(0));
});

it('keeps only one running timer per user', function () {
    [$user, $tenant, $token] = registerUser('onetimer@example.test', 'OneTimer Org');
    [$taskA, $taskB] = $tenant->run(fn () => [Task::factory()->create(), Task::factory()->create()]);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$taskA->id}/time/start")->assertCreated();

    // Starting a timer on B must stop the one on A — a person works on one thing.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$taskB->id}/time/start")->assertCreated();

    $tenant->run(function () use ($user) {
        expect(TimeEntry::where('user_id', $user->id)->running()->count())->toBe(1);
    });
});

it('logs time after the fact', function () {
    [, $tenant, $token] = registerUser('logtime@example.test', 'LogTime Org');
    $task = $tenant->run(fn () => Task::factory()->create());

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$task->id}/time/log", ['minutes' => 45, 'billable' => true])
        ->assertCreated()
        ->assertJsonPath('data.seconds', 45 * 60);

    $tenant->run(fn () => expect(Task::find($task->id)->tracked_seconds)->toBe(45 * 60));
});

it('reports the running timer for the timer widget', function () {
    [, $tenant, $token] = registerUser('widget@example.test', 'Widget Org');
    $task = $tenant->run(fn () => Task::factory()->create(['title' => 'Focus work']));

    // No timer yet.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/timer/running')
        ->assertOk()
        ->assertJsonPath('data', null);

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/tasks/{$task->id}/time/start")->assertCreated();

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/timer/running')
        ->assertOk()
        ->assertJsonPath('data.task_id', $task->id)
        ->assertJsonPath('data.running', true);
});

it('forbids editing another users time entry unless you can delete tasks', function () {
    [$owner, $tenant, $ownerToken] = registerUser('towner@example.test', 'TOwner Org');

    // A second member logs some time.
    [$member, , $memberToken] = registerUser('tmember@example.test', 'TMember Own');
    app(OrganizationService::class)->addMember($tenant, $member, Role::Employee);
    $task = $tenant->run(fn () => Task::factory()->create());

    app('auth')->forgetGuards();
    $logged = $this->withHeaders(orgHeaders($memberToken, $tenant))
        ->postJson("/api/v1/tasks/{$task->id}/time/log", ['minutes' => 30])
        ->assertCreated();
    $entryId = $logged->json('data.id');

    // Another employee cannot delete it (not their entry, no tasks.delete)...
    [$other, , $otherToken] = registerUser('tother@example.test', 'TOther Own');
    app(OrganizationService::class)->addMember($tenant, $other, Role::Employee);
    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($otherToken, $tenant))
        ->deleteJson("/api/v1/tasks/{$task->id}/time/{$entryId}")
        ->assertStatus(403);

    // ...but the owner (who can delete tasks) may correct any timesheet.
    app('auth')->forgetGuards();
    $this->withHeaders(orgHeaders($ownerToken, $tenant))
        ->deleteJson("/api/v1/tasks/{$task->id}/time/{$entryId}")
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
*/

it('never exposes another organizations tasks', function () {
    [, $tenantA, $tokenA] = registerUser('ta@example.test', 'TA');
    [, $tenantB, $tokenB] = registerUser('tb@example.test', 'TB');

    $tenantA->run(fn () => Task::factory()->count(4)->create());
    $tenantB->run(fn () => Task::factory()->count(2)->create());

    expect(apiAs($tokenA, $tenantA)->getJson('/api/v1/tasks')->json('meta.total'))->toBe(4)
        ->and(apiAs($tokenB, $tenantB)->getJson('/api/v1/tasks')->json('meta.total'))->toBe(2);
});
