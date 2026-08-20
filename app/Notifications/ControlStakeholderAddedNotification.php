<?php

namespace App\Notifications;

use App\Models\ControlStakeholder;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CR2-A: your unit was named a stakeholder on a shared control.
 */
class ControlStakeholderAddedNotification extends PreferenceRoutedNotification
{
    public function __construct(public ControlStakeholder $stakeholder) {}

    public function toMail(object $notifiable): MailMessage
    {
        $control = $this->stakeholder->control;

        return (new MailMessage)
            ->subject("Your unit was added as {$this->stakeholder->role} on {$control?->control_ref}")
            ->line("{$this->stakeholder->organisationUnit?->name} has been named a ".str_replace('_', '-', $this->stakeholder->role).' stakeholder on a shared control.')
            ->line('Control: '.($control ? "{$control->control_ref} — {$control->title}" : '—'))
            ->action('Open the control', url(route('controls.show', $this->stakeholder->control_id, false)))
            ->line('Co-owner units are notified whenever a shared control fails.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'control_stakeholder_added',
            'control_id' => $this->stakeholder->control_id,
            'control_ref' => $this->stakeholder->control?->control_ref,
            'unit' => $this->stakeholder->organisationUnit?->name,
            'role' => $this->stakeholder->role,
            'summary' => 'Your unit was added as a stakeholder on a shared control.',
        ];
    }
}
