<?php

namespace App\Notifications;

use App\Models\ControlException;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CR2-A: a control your unit co-owns has failed — an exception was
 * raised on it. Sent to co-owner unit heads and named contacts.
 */
class SharedControlExceptionRaisedNotification extends PreferenceRoutedNotification
{
    public function __construct(public ControlException $exception) {}

    public function toMail(object $notifiable): MailMessage
    {
        $control = $this->exception->control;

        return (new MailMessage)
            ->subject("Shared control failed — {$this->exception->reference} [{$this->exception->severity}]")
            ->line('An exception has been raised on a control your unit co-owns.')
            ->line('Control: '.($control ? "{$control->control_ref} — {$control->title}" : '—'))
            ->line("Exception: {$this->exception->reference} · Severity: {$this->exception->severity}")
            ->line('What failed: '.str($this->exception->title)->limit(200))
            ->action('Open the exception', url(route('exceptions.show', $this->exception->id, false)))
            ->line('Your unit shares responsibility for this control — coordinate remediation with the owning unit.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'shared_control_exception_raised',
            'exception_id' => $this->exception->id,
            'reference' => $this->exception->reference,
            'control_id' => $this->exception->control_id,
            'control_ref' => $this->exception->control?->control_ref,
            'severity' => $this->exception->severity,
            'summary' => 'An exception was raised on a control your unit co-owns.',
        ];
    }
}
