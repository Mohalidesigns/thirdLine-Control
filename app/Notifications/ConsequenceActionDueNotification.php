<?php

namespace App\Notifications;

use App\Models\ConsequenceAction;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CR-04: an approved consequence — a query letter, a suspension, a
 * recovery — has reached its due date and has not been implemented.
 *
 * Deliberately terse. The action type alone can identify a person to
 * anyone who knows the case, so the subject's name never appears here,
 * confidential investigation or not.
 */
class ConsequenceActionDueNotification extends PreferenceRoutedNotification
{
    public function __construct(public ConsequenceAction $action) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Consequence {$this->action->reference} is due")
            ->line('An approved consequence action has reached its due date and has not been recorded as implemented.')
            ->line("Reference: {$this->action->reference} · Due: ".($this->action->due_date?->format('d M Y') ?? 'unset'))
            ->action('Open the investigation', url(route('investigations.show', $this->action->investigation_id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'investigation_consequence_due',
            'consequence_action_id' => $this->action->id,
            'reference' => $this->action->reference,
            'investigation_id' => $this->action->investigation_id,
            'due_date' => $this->action->due_date?->toDateString(),
            'summary' => 'An approved consequence action is due and not yet implemented.',
        ];
    }
}
