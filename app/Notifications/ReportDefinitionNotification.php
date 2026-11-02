<?php

namespace App\Notifications;

use App\Models\ReportDefinition;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hand-offs on a report definition (13.3): a new or edited definition needs
 * a second person's approval before it can run, and the owner is told once
 * it has been approved.
 */
class ReportDefinitionNotification extends PreferenceRoutedNotification
{
    public const APPROVAL_REQUESTED = 'approval_requested';

    public const APPROVED = 'approved';

    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(
        public ReportDefinition $definition,
        public string $kind,
        public string $actorName,
        public string $what = 'edited',
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->headline());

        $mail = $this->kind === self::APPROVAL_REQUESTED
            ? $mail
                ->line("{$this->actorName} has {$this->what} the report definition {$this->definition->name} ({$this->definition->code}).")
                ->line('It cannot run until someone other than its author and last editor approves it.')
            : $mail
                ->line("{$this->actorName} has approved your report definition {$this->definition->name} ({$this->definition->code}).")
                ->line('It can now be run and scheduled.');

        return $mail->action('Open the report', url(route('reports.definitions.show', $this->definition->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_definition_'.$this->kind,
            'title' => $this->headline(),
            'report_definition_id' => $this->definition->id,
            'reference' => $this->definition->code,
            'url' => route('reports.definitions.show', $this->definition->id),
            'summary' => $this->headline(),
        ];
    }

    private function headline(): string
    {
        return $this->kind === self::APPROVAL_REQUESTED
            ? "Report definition awaiting your approval — {$this->definition->name}"
            : "Report definition approved — {$this->definition->name}";
    }
}
