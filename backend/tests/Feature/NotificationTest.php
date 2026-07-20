<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Jobs\DeliverWebhook;
use App\Models\DatabaseNotification;
use App\Models\WebhookEndpoint;
use App\Services\CustomerService;
use App\Services\EventDispatcher;
use App\Services\OrganizationService;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| In-app notifications
|--------------------------------------------------------------------------
*/

it('writes notifications to the tenant database, not central', function () {
    [$user, $tenant] = registerUser('notif@example.test', 'Notif Org');

    $tenant->run(function () use ($user) {
        app(EventDispatcher::class)->dispatch('customer.created', ['id' => 1], [$user]);

        expect(DatabaseNotification::count())->toBe(1)
            // The pinned model is what keeps this off the central connection.
            ->and((new DatabaseNotification)->getConnectionName())->toBe('tenant');
    });
});

it('gives the same user a separate inbox per organization', function () {
    [$user, $tenantA] = registerUser('multi@example.test', 'Multi A');
    [, $tenantB] = registerUser('bowner@example.test', 'Multi B');
    app(OrganizationService::class)->addMember($tenantB, $user, Role::Manager);

    // A notification in A must not appear in B.
    $tenantA->run(fn () => app(EventDispatcher::class)->dispatch('customer.created', ['id' => 1], [$user]));

    $tenantA->run(fn () => expect(DatabaseNotification::count())->toBe(1));
    $tenantB->run(fn () => expect(DatabaseNotification::count())->toBe(0));
});

it('lists notifications and an unread count through the api', function () {
    [$user, $tenant, $token] = registerUser('inbox@example.test', 'Inbox Org');
    $tenant->run(fn () => app(EventDispatcher::class)->dispatch('task.completed', ['id' => 5], [$user]));

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'task.completed')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread', 1);
});

it('marks a notification as read', function () {
    [$user, $tenant, $token] = registerUser('read@example.test', 'Read Org');
    $id = $tenant->run(function () use ($user) {
        app(EventDispatcher::class)->dispatch('customer.created', ['id' => 1], [$user]);

        return DatabaseNotification::first()->id;
    });

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk();

    $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/notifications')
        ->assertJsonPath('meta.unread', 0);
});

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
*/

it('creates a webhook and returns the secret exactly once', function () {
    [, $tenant, $token] = registerUser('wh@example.test', 'WH Org');

    $create = $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/webhooks', [
            'url' => 'https://example.test/hook',
            'events' => ['customer.created'],
        ])
        ->assertCreated();

    // The secret is present on creation...
    expect($create->json('data.secret'))->toStartWith('whsec_');

    // ...but never again in the list.
    $list = $this->withHeaders(orgHeaders($token, $tenant))
        ->getJson('/api/v1/webhooks')
        ->assertOk();

    expect($list->json('data.0'))->not->toHaveKey('secret');
});

it('dispatches a queued delivery when a subscribed event fires', function () {
    Queue::fake();

    [$user, $tenant] = registerUser('fire@example.test', 'Fire Org');
    $tenant->run(function () use ($user) {
        WebhookEndpoint::create(['url' => 'https://example.test/hook', 'events' => ['customer.created'], 'created_by' => $user->id]);
        app(CustomerService::class)->create(['name' => 'Triggers Hook'], $user);
    });

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->event === 'customer.created');
});

it('does not dispatch to endpoints that did not subscribe to the event', function () {
    Queue::fake();

    [$user, $tenant] = registerUser('nosub@example.test', 'NoSub Org');
    $tenant->run(function () use ($user) {
        // Subscribed to a different event only.
        WebhookEndpoint::create(['url' => 'https://example.test/hook', 'events' => ['task.completed'], 'created_by' => $user->id]);
        app(CustomerService::class)->create(['name' => 'No Hook'], $user);
    });

    Queue::assertNotPushed(DeliverWebhook::class);
});

it('forbids a non-admin from managing webhooks', function () {
    [$user, $tenant, $token] = registerUser('whperm@example.test', 'WHPerm Org');
    giveRole($tenant, $user, 'manager'); // lacks settings.update

    $this->withHeaders(orgHeaders($token, $tenant))
        ->postJson('/api/v1/webhooks', ['url' => 'https://example.test/hook', 'events' => ['*']])
        ->assertStatus(403);
});

it('keeps webhook endpoints scoped to their organization', function () {
    [$userA, $tenantA, $tokenA] = registerUser('wha@example.test', 'WHA');
    [, $tenantB] = registerUser('whb@example.test', 'WHB');

    $tenantA->run(fn () => WebhookEndpoint::create(['url' => 'https://a.test/hook', 'events' => ['*'], 'created_by' => $userA->id]));

    $a = apiAs($tokenA, $tenantA)->getJson('/api/v1/webhooks')->assertOk();
    expect($a->json('data'))->toHaveCount(1);

    $tenantB->run(fn () => expect(WebhookEndpoint::count())->toBe(0));
});
