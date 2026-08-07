<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * High-severity alert to every System Administrator when MFA enforcement
 * is removed from a role (Phase 7.2 business rule).
 */
class MfaPolicyChangedNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(
        public array $removedRoles,
        public string $actorName,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security alert — MFA enforcement removed')
            ->error()
            ->line('MFA enforcement was removed for role(s): '.implode(', ', $this->removedRoles).'.')
            ->line("Changed by: {$this->actorName}.")
            ->line('If this change was not expected, restore the policy and review the audit log immediately.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mfa_policy_changed',
            'severity' => 'high',
            'removed_roles' => $this->removedRoles,
            'actor' => $this->actorName,
        ];
    }
}
