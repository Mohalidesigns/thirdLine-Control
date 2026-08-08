<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * One notification for every Phase 11 deadline — policy reviews, closing
 * regulatory notification windows, complaint acknowledgement clocks. The
 * text is composed by the caller because the deadline itself comes from a
 * seeded obligation or a policy's own review frequency, never from here (R1).
 */
class GovernanceClockNotification extends PreferenceRoutedNotification
{
    public function __construct(
        public string $title,
        public string $summary,
        public ?string $url = null,
    ) {
        $this->resolvedChannels = ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->line($this->summary);

        return $this->url
            ? $message->action('Open SecondLine', $this->url)->line('Please act before the window closes.')
            : $message->line('Please act before the window closes.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'governance-clock',
            'title' => $this->title,
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
