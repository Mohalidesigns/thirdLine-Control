<?php

namespace App\Notifications;

use App\Models\ControlException;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The approval hand-offs of an exception's lifecycle: the owner asks for
 * more time or reports it remediated (to the control function), and the
 * control function decides an extension (back to the owner).
 */
class ExceptionDecisionNotification extends PreferenceRoutedNotification
{
    public const EXTENSION_REQUESTED = 'extension_requested';

    public const EXTENSION_DECIDED = 'extension_decided';

    public const REMEDIATED = 'remediated';

    public function __construct(
        public ControlException $exception,
        public string $kind,
        public string $actorName,
        public string $note,
        public ?string $newDate = null,
        public ?bool $approved = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->exception->reference;

        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::EXTENSION_REQUESTED => $mail
                ->line("{$this->actorName} has asked to move the target closure date of {$ref}.")
                ->line('Current target: '.($this->exception->target_closure_date?->toFormattedDateString() ?? '—'))
                ->line('Requested date: '.($this->newDate ?? '—'))
                ->line('Reason: '.str($this->note)->limit(300)),
            self::EXTENSION_DECIDED => $mail
                ->line("{$this->actorName} has ".($this->approved ? 'approved' : 'declined')." your extension request on {$ref}.")
                ->line('Target closure: '.($this->exception->target_closure_date?->toFormattedDateString() ?? '—'))
                ->line('Reason: '.str($this->note)->limit(300)),
            default => $mail
                ->line("{$this->actorName} has marked {$ref} remediated. It now needs control function verification before it can close.")
                ->line('Remediation note: '.str($this->note)->limit(300)),
        };

        return $mail
            ->line('Exception: '.str($this->exception->title)->limit(200))
            ->action('Open the exception', url(route('exceptions.show', $this->exception->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'exception_'.$this->kind,
            'title' => $this->headline(),
            'exception_id' => $this->exception->id,
            'reference' => $this->exception->reference,
            'url' => route('exceptions.show', $this->exception->id),
            'summary' => $this->headline().': '.str($this->exception->title)->limit(120),
        ];
    }

    private function headline(): string
    {
        $ref = $this->exception->reference;

        return match ($this->kind) {
            self::EXTENSION_REQUESTED => "Extension requested — {$ref}",
            self::EXTENSION_DECIDED => 'Extension '.($this->approved ? 'approved' : 'declined')." — {$ref}",
            default => "Ready for verification — {$ref}",
        };
    }
}
