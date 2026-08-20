<?php

namespace App\Notifications;

use App\Models\ExceptionEscalation;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CR-01 base for the Exception Manager's nine events. The email carries the
 * reference, control, severity, what is being asked for, the response due
 * date and a single deep link — NO evidence and no Restricted-classification
 * detail ever leaves the platform by mail.
 */
abstract class ExceptionEscalationNotification extends PreferenceRoutedNotification
{
    public function __construct(
        public ExceptionEscalation $escalation,
        public ?string $actionUrl = null,
    ) {}

    abstract protected function subjectLine(): string;

    abstract protected function leadLine(): string;

    protected function notificationType(): string
    {
        return str(class_basename(static::class))->snake()->toString();
    }

    /** The single deep link: the response screen for the addressed party. */
    protected function link(): string
    {
        return $this->actionUrl
            ?? url(route('exception-manager.show', $this->escalation->id, false));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $exception = $this->escalation->exception;

        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->line($this->leadLine())
            ->line("Reference: {$this->escalation->reference} (exception {$exception?->reference})")
            ->line('Control: '.($exception?->control?->control_ref ?? '—'))
            ->line("Severity: {$this->escalation->severity}")
            ->line('What is being asked for: '.str($this->escalation->issue_note)->limit(300));

        if ($this->escalation->response_due_at) {
            $mail->line('Response due: '.$this->escalation->response_due_at->toDayDateTimeString());
        }

        return $mail
            ->action('Open the response screen', $this->link())
            ->line('Please act within the stated deadline.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->notificationType(),
            'escalation_id' => $this->escalation->id,
            'exception_id' => $this->escalation->exception_id,
            'reference' => $this->escalation->reference,
            'severity' => $this->escalation->severity,
            'round_no' => $this->escalation->round_no,
            'status' => $this->escalation->status,
            'response_due_at' => $this->escalation->response_due_at?->toIso8601String(),
            'summary' => $this->leadLine(),
        ];
    }
}
