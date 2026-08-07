<?php

namespace App\Notifications;

use App\Models\ObligationInstance;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Graduated reminder ahead of a regulatory deadline, and the overdue notice
 * once it passes. The penalty exposure travels with the message: a filing
 * deadline is more persuasive when the cost of missing it is on the line.
 */
class ObligationReminderNotification extends PreferenceRoutedNotification
{
    public function __construct(
        public ObligationInstance $instance,
        public int $daysRemaining,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $obligation = $this->instance->obligation;
        $exposure = $this->instance->penaltyExposureMoney();

        $message = (new MailMessage)
            ->subject($this->subject())
            ->line($obligation?->title ?? 'Regulatory obligation')
            ->line('Regulator: '.($obligation?->regulator?->name ?? 'Not recorded'))
            ->line('Period: '.$this->instance->period_label)
            ->line('Due: '.$this->instance->due_at->toDayDateTimeString());

        if ($this->daysRemaining < 0 && $exposure && ! $exposure->isZero()) {
            $message->line('Estimated penalty exposure to date: '.$exposure->format());
        }

        if ($obligation && ! $obligation->isVerified()) {
            $message->line('Note: this obligation is marked "'.$obligation->verification_status
                .'" and is excluded from generated regulatory submissions until verified.');
        }

        return $message
            ->action('Open the obligation', url(route('obligations.instances.show', $this->instance->id, false)))
            ->line('Attach evidence and record the submission reference when filed.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->daysRemaining < 0 ? 'obligation.overdue' : 'obligation.reminder',
            'instance_id' => $this->instance->id,
            'obligation' => $this->instance->obligation?->title,
            'period_label' => $this->instance->period_label,
            'due_at' => $this->instance->due_at?->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
            'penalty_exposure' => $this->instance->penaltyExposureMoney()?->jsonSerialize(),
        ];
    }

    private function subject(): string
    {
        $title = $this->instance->obligation?->title ?? 'Regulatory obligation';

        return match (true) {
            $this->daysRemaining < 0 => "Overdue: {$title}",
            $this->daysRemaining === 0 => "Due today: {$title}",
            $this->daysRemaining === 1 => "Due tomorrow: {$title}",
            default => "Due in {$this->daysRemaining} days: {$title}",
        };
    }
}
