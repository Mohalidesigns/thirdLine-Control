<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An administrator has triggered a password reset for this user. Reuses the
 * same signed set-password flow as invitations — one code path, one expiry
 * policy, one audit surface.
 */
class PasswordResetLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $setPasswordUrl,
        public string $expires,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting("Hello {$notifiable->name},")
            ->line('A password reset was requested for your account by an administrator.')
            ->line('This link expires on '.$this->expires.'.')
            ->action('Choose a new password', $this->setPasswordUrl)
            ->line('If you did not expect this, contact your administrator — your current password still works until the link is used.');
    }
}
