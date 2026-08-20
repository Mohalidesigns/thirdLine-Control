<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\PreferenceRoutedNotification;
use App\Services\Messaging\WebPushService;
use Illuminate\Notifications\Notification;

/**
 * Laravel channel → WebPushService (15.5). The push itself carries no
 * payload; the service worker fetches and shows the notification over the
 * authenticated session.
 */
class WebPushChannel
{
    public function __construct(private WebPushService $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $meta = $notification instanceof PreferenceRoutedNotification
            ? ['event' => $notification->eventKey]
            : [];

        $this->push->sendToUser($notifiable, $meta);
    }
}
