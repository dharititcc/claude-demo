<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\GenericNotification;
use Illuminate\Support\Collection;

/**
 * Fans a domain event out to the notification channels for the active
 * organization: database notifications for chosen users, and webhooks for every
 * subscribed endpoint.
 *
 * Kept separate from the models so firing an event does not couple a controller
 * to how it is delivered — a caller says "customer.created happened", not "write
 * a row and POST to three URLs".
 */
class EventDispatcher
{
    /**
     * @param array<string, mixed> $payload
     * @param Collection<int, User>|array<int, User> $notify Users to notify in-app.
     */
    public function dispatch(string $event, array $payload, iterable $notify = []): void
    {
        $this->notifyUsers($event, $payload, $notify);
        $this->fireWebhooks($event, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @param iterable<int, User> $users
     */
    private function notifyUsers(string $event, array $payload, iterable $users): void
    {
        foreach ($users as $user) {
            // The notification is written to the *tenant* database (that is where
            // the notifications table lives), so each organization has its own
            // inbox for the same central user.
            $user->notify(new GenericNotification($event, $payload));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fireWebhooks(string $event, array $payload): void
    {
        $tenantId = tenant('id');

        if ($tenantId === null) {
            return; // webhooks are an organization concern
        }

        WebhookEndpoint::active()
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => DeliverWebhook::dispatch(
                (string) $tenantId,
                $endpoint->id,
                $event,
                $payload,
            ));
    }
}
