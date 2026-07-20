<?php

declare(strict_types=1);

use App\Models\Event;

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('creates a one-off event', function () {
    [, $tenant, $token] = registerUser('cal@example.test', 'Cal Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/events', [
            'title' => 'Kickoff',
            'starts_at' => '2026-09-01T10:00:00Z',
            'ends_at' => '2026-09-01T11:00:00Z',
            'type' => 'meeting',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Kickoff')
        ->assertJsonPath('data.recurrence', null);
});

it('rejects an end before the start', function () {
    [, $tenant, $token] = registerUser('badtime@example.test', 'BadTime Org');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/events', [
            'title' => 'Backwards',
            'starts_at' => '2026-09-01T11:00:00Z',
            'ends_at' => '2026-09-01T10:00:00Z',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');
});

/*
|--------------------------------------------------------------------------
| Recurrence expansion
|--------------------------------------------------------------------------
*/

it('expands a weekly series across a window', function () {
    [, $tenant, $token] = registerUser('weekly@example.test', 'Weekly Org');

    $tenant->run(fn () => Event::factory()->weekly(['MO', 'WE'])->create([
        'title' => 'Standup',
        'starts_at' => '2026-08-03 09:00:00', // a Monday
        'ends_at' => '2026-08-03 09:15:00',
    ]));

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-14')
        ->assertOk();

    // Mondays and Wednesdays across two weeks: Aug 3, 5, 10, 12.
    expect($response->json('meta.count'))->toBe(4)
        ->and(collect($response->json('data'))->every(fn ($o) => $o['is_recurring'] === true))->toBeTrue();
});

it('honours a recurrence count limit', function () {
    [, $tenant, $token] = registerUser('count@example.test', 'Count Org');

    $tenant->run(fn () => Event::factory()->daily()->create([
        'title' => 'Daily x3',
        'starts_at' => '2026-08-03 08:00:00',
        'ends_at' => '2026-08-03 08:30:00',
        'recurrence_count' => 3,
    ]));

    // A wide window, but the series stops after 3 occurrences.
    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    expect($response->json('meta.count'))->toBe(3);
});

it('stops a series at its until date', function () {
    [, $tenant, $token] = registerUser('until@example.test', 'Until Org');

    $tenant->run(fn () => Event::factory()->daily()->create([
        'starts_at' => '2026-08-03 08:00:00',
        'ends_at' => '2026-08-03 08:30:00',
        'recurrence_until' => '2026-08-05 23:59:59',
    ]));

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    // Aug 3, 4, 5 only.
    expect($response->json('meta.count'))->toBe(3);
});

it('bounds the query window to prevent unbounded expansion', function () {
    [, $tenant, $token] = registerUser('bound@example.test', 'Bound Org');

    // A window wider than a year is refused rather than expanding forever.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-01-01&to=2030-01-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

/*
|--------------------------------------------------------------------------
| Exceptions to a series
|--------------------------------------------------------------------------
*/

it('cancels a single occurrence without touching the rest of the series', function () {
    [, $tenant, $token] = registerUser('cancel@example.test', 'Cancel Org');

    $event = $tenant->run(fn () => Event::factory()->daily()->create([
        'starts_at' => '2026-08-03 08:00:00',
        'ends_at' => '2026-08-03 08:30:00',
        'recurrence_count' => 5,
    ]));

    // Cancel the Aug 4 occurrence.
    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/events/{$event->id}/occurrence", [
            'original_starts_at' => '2026-08-04 08:00:00',
            'cancel' => true,
        ])
        ->assertOk();

    $response = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    // 5 occurrences minus the cancelled one = 4, and Aug 4 is gone.
    $dates = collect($response->json('data'))->pluck('starts_at')->map(fn ($d) => substr($d, 0, 10));
    expect($response->json('meta.count'))->toBe(4)
        ->and($dates)->not->toContain('2026-08-04');
});

it('refuses to edit an occurrence of a non-recurring event', function () {
    [, $tenant, $token] = registerUser('nonrec@example.test', 'NonRec Org');
    $event = $tenant->run(fn () => Event::factory()->create()); // no recurrence

    $this->withHeaders(orgHeaders($token, $tenant))
        ->putJson("/api/v1/events/{$event->id}/occurrence", [
            'original_starts_at' => '2026-08-04 08:00:00',
            'cancel' => true,
        ])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Authorization & isolation
|--------------------------------------------------------------------------
*/

it('forbids a viewer from creating events but allows viewing', function () {
    [$user, $tenant, $token] = registerUser('vcal@example.test', 'VCal Org');
    $tenant->run(fn () => Event::factory()->create());
    giveRole($tenant, $user, 'viewer');

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/events?from=2026-01-01&to=2026-02-01')
        ->assertOk();

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/events', ['title' => 'Nope', 'starts_at' => '2026-09-01T10:00:00Z'])
        ->assertStatus(403);
});

it('never exposes another organizations events', function () {
    [, $tenantA, $tokenA] = registerUser('ea@example.test', 'EA');
    [, $tenantB, $tokenB] = registerUser('eb@example.test', 'EB');

    $tenantA->run(fn () => Event::factory()->count(3)->create(['starts_at' => '2026-08-10 10:00:00', 'ends_at' => '2026-08-10 11:00:00']));
    $tenantB->run(fn () => Event::factory()->count(1)->create(['starts_at' => '2026-08-10 10:00:00', 'ends_at' => '2026-08-10 11:00:00']));

    $a = apiAs($tokenA, $tenantA)->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')->assertOk();
    $b = apiAs($tokenB, $tenantB)->getJson('/api/v1/events?from=2026-08-01&to=2026-08-31')->assertOk();

    expect($a->json('meta.count'))->toBe(3)
        ->and($b->json('meta.count'))->toBe(1);
});
