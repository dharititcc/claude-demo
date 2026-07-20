<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invitation link.
 *
 * Queued: sending is an outbound network call, and the person clicking "Invite"
 * shouldn't wait on the mail server (or see a 500 when it's briefly down).
 */
class OrganizationInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly Tenant $tenant,
        private readonly User $inviter,
        private readonly string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The link points at the SPA, not the API: the recipient completes the
        // flow in the browser (signing in or registering first if needed).
        $url = rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$this->token;

        return (new MailMessage)
            ->subject("{$this->inviter->name} invited you to join {$this->tenant->name}")
            ->greeting('You have been invited')
            ->line("{$this->inviter->name} has invited you to join **{$this->tenant->name}** as {$this->invitation->roleEnum()->label()}.")
            ->action('Accept invitation', $url)
            ->line("This invitation expires {$this->invitation->expires_at->diffForHumans()}.")
            ->line('If you were not expecting this, you can safely ignore this email.');
    }
}
