<?php

namespace App\Notifications;

use App\Models\Control;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Maker-checker hand-offs on a control (FR-1.8): a maker submits or amends
 * one (to the control function heads), and the checker returns or
 * approves it (back to the maker and the control's owner).
 */
class ControlApprovalNotification extends PreferenceRoutedNotification
{
    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public function __construct(
        public Control $control,
        public string $kind,
        public string $actorName,
        public ?string $note = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->control->control_ref;

        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::SUBMITTED => $mail
                ->line("{$this->actorName} has submitted control {$ref} (v{$this->control->current_version}) for your approval.")
                ->line('Review it, then approve it or return it to draft with a reason.'),
            self::RETURNED => $mail
                ->line("{$this->actorName} has returned control {$ref} to draft.")
                ->line('Reason: '.str($this->note)->limit(500))
                ->line('Make the changes and submit it again.'),
            default => $mail
                ->line("{$this->actorName} has approved control {$ref}. It is now active."),
        };

        return $mail
            ->line('Control: '.str($this->control->title)->limit(200))
            ->action('Open the control', url(route('controls.show', $this->control->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'control_'.$this->kind,
            'title' => $this->headline(),
            'control_id' => $this->control->id,
            'reference' => $this->control->control_ref,
            'url' => route('controls.show', $this->control->id),
            'summary' => $this->headline().': '.str($this->control->title)->limit(120),
        ];
    }

    private function headline(): string
    {
        $ref = $this->control->control_ref;

        return match ($this->kind) {
            self::SUBMITTED => "Control awaiting your approval — {$ref}",
            self::RETURNED => "Control returned to draft — {$ref}",
            default => "Control approved — {$ref}",
        };
    }
}
