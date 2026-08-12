<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\PreferenceRoutedNotification;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Notifications\Notification;

/**
 * Laravel channel → WhatsAppService (15.3). Delivers only for
 * notifications that expose a template payload via toWhatsapp(), and only
 * to users who have a phone number on file; everything else is skipped
 * without disturbing the other channels.
 */
class WhatsAppChannel
{
    public function __construct(private WhatsAppService $whatsApp) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $payload = $notification instanceof PreferenceRoutedNotification
            ? $notification->toWhatsapp($notifiable)
            : null;

        $phone = method_exists($notifiable, 'routeNotificationForWhatsapp')
            ? $notifiable->routeNotificationForWhatsapp()
            : ($notifiable->phone ?? null);

        if (! $payload || ! $phone) {
            return;
        }

        $this->whatsApp->send(
            tenantId: $notifiable->tenant_id,
            user: $notifiable instanceof User ? $notifiable : null,
            phone: $phone,
            templateKey: $payload['template_key'],
            variables: $payload['variables'] ?? [],
            link: $payload['link'] ?? null,
        );
    }
}
