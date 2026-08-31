<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Shared\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TenantInvitation $invitation,
        public string $invitationUrl,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invitation to {$this->invitation->organization->name}")
            ->greeting('You have been invited')
            ->line("You have been invited to join {$this->invitation->organization->name} as {$this->invitation->tenant_role_key->label()}.")
            ->action('Accept invitation', $this->invitationUrl)
            ->line("This invitation expires {$this->invitation->expires_at->diffForHumans()}.")
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
