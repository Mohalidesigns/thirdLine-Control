<?php

namespace App\Notifications;

use App\Models\SubmissionPack;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A regulatory submission pack needs someone (13.4). Deliberately terse about
 * content: the pack itself is confidential, so the notification says which
 * pack and what it needs, never what is in it.
 */
class SubmissionActionNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(public SubmissionPack $pack, public string $action) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Submission {$this->pack->pack_ref} {$this->action}")
            ->line("{$this->pack->pack_type} for {$this->pack->period_label} ({$this->pack->pack_ref}) {$this->action}.")
            ->line("It is {$this->pack->completeness_score}% complete.")
            ->action('Open the pack', url("/submissions/{$this->pack->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'submission_action',
            'pack_id' => $this->pack->id,
            'pack_ref' => $this->pack->pack_ref,
            'pack_type' => $this->pack->pack_type,
            'period_label' => $this->pack->period_label,
            'status' => $this->pack->status,
            'completeness' => $this->pack->completeness_score,
            'action' => $this->action,
        ];
    }
}
