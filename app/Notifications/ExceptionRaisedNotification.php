<?php

namespace App\Notifications;

use App\Models\ControlException;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A control failure has been logged against you — from a failed test check,
 * a spot-check finding or any other source. Sent to the exception's owner
 * and responsible party, never to the person who raised it.
 */
class ExceptionRaisedNotification extends PreferenceRoutedNotification
{
    public function __construct(public ControlException $exception) {}

    public function toMail(object $notifiable): MailMessage
    {
        $control = $this->exception->control;

        return (new MailMessage)
            ->subject("Exception raised — {$this->exception->reference} [{$this->exception->severity}]")
            ->line("A control exception from a {$this->exception->source_type} has been raised and assigned to you.")
            ->line('Control: '.($control ? "{$control->control_ref} — {$control->title}" : '—'))
            ->line("Exception: {$this->exception->reference} · Severity: {$this->exception->severity}")
            ->line('What failed: '.str($this->exception->title)->limit(200))
            ->line('Target closure: '.($this->exception->target_closure_date?->toFormattedDateString() ?? '—'))
            ->action('Open the exception', url(route('exceptions.show', $this->exception->id, false)))
            ->line('Please review it and agree a remediation before the target date.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'exception_raised',
            'title' => "Exception raised — {$this->exception->reference}",
            'exception_id' => $this->exception->id,
            'reference' => $this->exception->reference,
            'control_id' => $this->exception->control_id,
            'severity' => $this->exception->severity,
            'url' => route('exceptions.show', $this->exception->id),
            'summary' => "{$this->exception->source_type} exception: ".str($this->exception->title)->limit(120),
        ];
    }
}
