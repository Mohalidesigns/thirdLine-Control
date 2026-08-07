<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder driver for channels registered but not yet implemented
 * (whatsapp, sms, push). TODO Phase 15: replace with WhatsApp Cloud API,
 * SMS gateway and Web Push (VAPID) drivers.
 */
class NoopChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        Log::info('Notification channel not yet implemented — dropped (Phase 15 delivers it).', [
            'notification' => $notification::class,
            'notifiable_id' => $notifiable->id ?? null,
        ]);
    }
}
