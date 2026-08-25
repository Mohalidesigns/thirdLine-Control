<?php

namespace App\Notifications;

use App\Models\Control;
use App\Models\ControlEntity;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * CR-03 §C.4, step 4 of the ownership chain: nothing resolved to an
 * owner, so the task exists but sits in nobody's queue. The unit head is
 * told the same night rather than discovering it in an overdue report a
 * fortnight later.
 */
class ControlTaskUnassignedNotification extends PreferenceRoutedNotification
{
    public function __construct(public Control $control, public ?ControlEntity $entity = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->entity?->name ?? 'your unit';

        return (new MailMessage)
            ->subject("Control task generated with no officer — {$this->control->control_ref}")
            ->line("A {$this->control->frequency_label} control task was generated for {$where} and could not be assigned.")
            ->line("Function: {$this->control->control_ref} — {$this->control->title}")
            ->line('Name a control officer on the desk or branch, or an owner on the function, and tonight\'s run will assign it.')
            ->action('Open the control function', url(route('control-functions.show', $this->control->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'control_task_unassigned',
            'control_id' => $this->control->id,
            'control_ref' => $this->control->control_ref,
            'control_entity_id' => $this->entity?->id,
            'entity' => $this->entity?->name,
            'summary' => 'A control task was generated with no officer to perform it.',
        ];
    }
}
