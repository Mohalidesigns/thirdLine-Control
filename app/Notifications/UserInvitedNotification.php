<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new user has been created by an administrator and must choose their own
 * password. The link is a signed URL with a hard expiry — an invitation that
 * outlives a week is a stale credential waiting in an inbox.
 */
class UserInvitedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $setPasswordUrl,
        public string $invitedBy,
        public string $expires,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->invitedBy} has created an account for you on ".config('app.name').'.')
            ->line('Choose your password to activate the account. This link expires on '.$this->expires.'.')
            ->action('Set your password', $this->setPasswordUrl)
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
