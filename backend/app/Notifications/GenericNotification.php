<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A database (in-app) notification carrying a domain event and its payload.
 *
 * One flexible notification rather than a class per event type: the in-app inbox
 * renders from `event` + `data`, so a new event needs no new notification class.
 * The channel is database only — email/webhook fan-out is handled separately by
 * EventDispatcher, so a user's inbox preference never silently drops a webhook.
 */
class GenericNotification extends Notification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $event,
        public readonly array $payload,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'payload' => $this->payload,
        ];
    }
}
