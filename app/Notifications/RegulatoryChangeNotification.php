<?php

namespace App\Notifications;

use App\Models\RegulatoryChange;
use Illuminate\Notifications\Messages\MailMessage;

class RegulatoryChangeNotification extends PreferenceRoutedNotification
{
    public function __construct(public RegulatoryChange $change) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New regulatory publication — '.($this->change->regulator?->name ?? 'Regulator'))
            ->line($this->change->title)
            ->when((bool) $this->change->reference, fn ($m) => $m->line('Reference: '.$this->change->reference))
            ->when((bool) $this->change->summary, fn ($m) => $m->line($this->change->summary))
            ->action('Assess the impact', url(route('regulatory-changes.index', [], false)))
            ->line('Assess the impact on obligations and controls, then record the action taken.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'regulatory-change.published',
            'change_id' => $this->change->id,
            'regulator' => $this->change->regulator?->name,
            'title' => $this->change->title,
            'reference' => $this->change->reference,
            'published_at' => $this->change->published_at?->toIso8601String(),
        ];
    }
}
