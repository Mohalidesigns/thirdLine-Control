<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\PreferenceRoutedNotification;
use App\Services\Messaging\SmsService;
use Illuminate\Notifications\Notification;

/** Laravel channel → SmsService (15.4). Mirrors WhatsAppChannel. */
class SmsChannel
{
    public function __construct(private SmsService $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $payload = $notification instanceof PreferenceRoutedNotification
            ? $notification->toSms($notifiable)
            : null;

        $phone = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms()
            : ($notifiable->phone ?? null);

        if (! $payload || ! $phone) {
            return;
        }

        $this->sms->send(
            tenantId: $notifiable->tenant_id,
            user: $notifiable instanceof User ? $notifiable : null,
            phone: $phone,
            templateKey: $payload['template_key'],
            variables: $payload['variables'] ?? [],
            link: $payload['link'] ?? null,
        );
    }
}
