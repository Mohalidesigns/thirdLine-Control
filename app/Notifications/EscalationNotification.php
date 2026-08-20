<?php

namespace App\Notifications;

use App\Models\EscalationEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

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
            ->action('Open SecondLine', $this->deepLink())
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

    /**
     * escalation_events carries one populated subject out of eight;
     * the deep link must resolve for every one of them (DEF-001).
     */
    private function deepLink(): string
    {
        $event = $this->event;

        $path = match (true) {
            $event->exception_escalation_id !== null => route('exception-manager.show', $event->exception_escalation_id, false),
            $event->exception_id !== null => route('exceptions.show', $event->exception_id, false),
            $event->test_instance_id !== null => route('test-instances.show', $event->test_instance_id, false),
            $event->risk_id !== null => route('risks.show', $event->risk_id, false),
            $event->metric_id !== null => route('metrics.show', $event->metric_id, false),
            $event->treatment_id !== null => route('treatments.show', $event->treatment_id, false),
            $event->campaign_id !== null => route('csa-campaigns.show', $event->campaign_id, false),
            default => route('dashboard', absolute: false),
        };

        return url($path);
    }

    /**
     * Channels are queued, so a delivery that fails does so after the
     * dispatcher has returned. Reconcile the escalation register when the
     * queued send is exhausted (DEF-002) — FR-8.7 requires the recorded
     * delivery status to reflect what actually happened.
     */
    public function failed(\Throwable $e): void
    {
        EscalationEvent::withoutGlobalScopes()
            ->whereKey($this->event->id)
            ->update(['delivery_status' => 'Failed']);

        Log::error('Escalation delivery failed in the queue', [
            'event' => $this->event->id,
            'error' => $e->getMessage(),
        ]);
    }
}
