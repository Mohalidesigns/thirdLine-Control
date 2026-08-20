<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base class for every notification routed through NotificationDispatcher:
 * the dispatcher resolves the recipient's preferences and injects the
 * final channel list, so subclasses never hard-code via().
 */
abstract class PreferenceRoutedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var array<int, string> Laravel channel identifiers set by the dispatcher. */
    public array $resolvedChannels = ['database'];

    /** Set by the dispatcher so channel drivers know the seeded event. */
    public ?string $eventKey = null;

    public function viaChannels(array $channels): static
    {
        $this->resolvedChannels = $channels;

        return $this;
    }

    /**
     * WhatsApp/SMS payload (15.3/15.4): a template key, short variables and
     * a deep link — never the substance (NDPA; OutboundContentGuard is the
     * backstop). Return null to opt the notification out of that channel.
     *
     * @return ?array{template_key: string, variables?: array<string, string>, link?: ?string}
     */
    public function toWhatsapp(object $notifiable): ?array
    {
        return null;
    }

    /** @return ?array{template_key: string, variables?: array<string, string>, link?: ?string} */
    public function toSms(object $notifiable): ?array
    {
        return null;
    }

    public function via(object $notifiable): array
    {
        return $this->resolvedChannels;
    }
}
