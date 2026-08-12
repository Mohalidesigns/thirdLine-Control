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

    /**
     * WhatsApp/SMS carry the title and a link back into the platform,
     * never the summary — the substance stays behind authentication
     * (NDPA; OutboundContentGuard is the backstop).
     */
    public function toWhatsapp(object $notifiable): ?array
    {
        return [
            'template_key' => 'task_reminder',
            'variables' => ['title' => $this->title],
            'link' => $this->url ?? route('dashboard'),
        ];
    }

    public function toSms(object $notifiable): ?array
    {
        return [
            'template_key' => 'task_reminder',
            'variables' => ['title' => $this->title],
            'link' => $this->url ?? route('dashboard'),
        ];
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
