<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * High-visibility alert: a break-glass password login occurred while SSO
 * is enforced (Phase 7.1 business rule — always audited, always alerted).
 */
class BreakGlassLoginNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(
        public string $userName,
        public string $userEmail,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security alert — break-glass login used')
            ->error()
            ->line("Break-glass account {$this->userName} ({$this->userEmail}) signed in with a password while SSO is enforced.")
            ->line('If this was not expected, disable the account and review the audit log immediately.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'break_glass_login',
            'severity' => 'high',
            'user' => $this->userName,
            'email' => $this->userEmail,
        ];
    }
}
