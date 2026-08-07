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

    public function viaChannels(array $channels): static
    {
        $this->resolvedChannels = $channels;

        return $this;
    }

    public function via(object $notifiable): array
    {
        return $this->resolvedChannels;
    }
}
