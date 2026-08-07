<?php

namespace App\Notifications;

use App\Models\EscalationEvent;
use Illuminate\Notifications\Messages\MailMessage;

class EscalationNotification extends PreferenceRoutedNotification
{
    public function __construct(public EscalationEvent $event)
    {
        // Default routing preserves the pre-dispatcher behaviour; the
        // dispatcher overrides it from the recipient's preferences.
        $this->resolvedChannels = match ($this->event->channel) {
            'in_app' => ['database'],
            'email' => ['mail'],
            default => ['database', 'mail'],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Escalation — '.$this->event->payload_summary)
            ->line('An item has been escalated to you (tier '.$this->event->tier_no.').')
            ->line($this->event->payload_summary)
            ->action('Open SecondLine', url($this->event->exception_id
                ? route('exceptions.show', $this->event->exception_id, false)
                : route('test-instances.show', $this->event->test_instance_id, false)))
            ->line('Please review and act promptly.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'escalation',
            'tier_no' => $this->event->tier_no,
            'summary' => $this->event->payload_summary,
            'exception_id' => $this->event->exception_id,
            'test_instance_id' => $this->event->test_instance_id,
        ];
    }
}
