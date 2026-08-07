<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class SsoConfigChangedNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(
        public string $message,
        public string $actorName,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SSO configuration change')
            ->line($this->message)
            ->line("Actioned by: {$this->actorName}.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sso_config_changed',
            'message' => $this->message,
            'actor' => $this->actorName,
        ];
    }
}
