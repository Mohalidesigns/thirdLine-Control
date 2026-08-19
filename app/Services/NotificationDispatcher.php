<?php

namespace App\Services;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\PreferenceRoutedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Single dispatch point for every user-facing notification (Phase 7.4):
 * resolves the seeded event definition and the recipient's per-channel
 * preferences, respects quiet hours in the tenant timezone, defers
 * digest-frequency deliveries to the next digest window, and maps
 * logical channels onto Laravel drivers. WhatsApp/SMS/Push (Phase 15)
 * carry a notification and a link only — the channel drivers and
 * OutboundContentGuard enforce that, and each skips quietly until its
 * .env credentials exist.
 */
class NotificationDispatcher
{
    private const CHANNEL_DRIVERS = [
        'in_app' => 'database',
        'email' => 'mail',
        'whatsapp' => WhatsAppChannel::class,
        'sms' => SmsChannel::class,
        'push' => WebPushChannel::class,
    ];

    private const DAILY_DIGEST_TIME = '07:00';

    private const WEEKLY_DIGEST_DAY_TIME = ['Monday', '07:30'];

    /**
     * @param  User|iterable<User>  $recipients
     */
    public function send(User|iterable $recipients, string $eventKey, PreferenceRoutedNotification $notification): void
    {
        $recipients = $recipients instanceof User ? [$recipients] : $recipients;

        foreach ($recipients as $user) {
            $this->sendToUser($user, $eventKey, $notification);
        }
    }

    /**
     * CR-01: an external processor has no account, so it has no preferences
     * and no in-app channel — but its notice still leaves through the single
     * dispatch point rather than a stray Mail::send. Email only, immediate.
     */
    public function sendExternal(string $email, string $eventKey, PreferenceRoutedNotification $notification): void
    {
        $instance = (clone $notification)->viaChannels(['mail']);
        $instance->eventKey = $eventKey;

        Notification::route('mail', $email)->notify($instance);
    }

    private function sendToUser(User $user, string $eventKey, PreferenceRoutedNotification $notification): void
    {
        $event = NotificationEvent::forKey($eventKey, $user->tenant_id);
        $defaultChannels = $event?->default_channels ?? ['in_app'];

        // The recipient is not necessarily the authenticated user, so the
        // tenant global scope (keyed to auth()) must not filter this lookup.
        $preferences = NotificationPreference::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->get()
            ->keyBy('channel');

        $timezone = $user->tenant?->localisation()['timezone'] ?? 'Africa/Lagos';

        // Group the enabled channels by their computed delivery time so a
        // digest-deferred email doesn't also delay the in-app alert.
        $byDeliveryTime = [];

        foreach (self::CHANNEL_DRIVERS as $channel => $driver) {
            $preference = $preferences->get($channel);

            $enabled = ($event?->is_user_configurable ?? true) && $preference
                ? $preference->is_enabled
                : in_array($channel, $defaultChannels, true);

            if (! $enabled) {
                continue;
            }

            $deliverAt = $this->deliveryTime($preference, $timezone);
            $byDeliveryTime[$deliverAt?->timestamp ?? 0][] = $driver;
        }

        foreach ($byDeliveryTime as $timestamp => $drivers) {
            $instance = (clone $notification)->viaChannels(array_values(array_unique($drivers)));
            $instance->eventKey = $eventKey;

            if ($timestamp > 0) {
                $instance->delay(Carbon::createFromTimestamp($timestamp));
            }

            $user->notify($instance);
        }
    }

    /**
     * null = deliver now. Digest frequency defers to the next digest
     * window; quiet hours push any delivery out of the quiet window.
     * Note: Phase 7 defers each digest item individually — Phase 15
     * collapses the deferred items into one combined digest message.
     */
    private function deliveryTime(?NotificationPreference $preference, string $timezone): ?Carbon
    {
        $now = Carbon::now($timezone);

        $candidate = match ($preference?->digest_frequency ?? 'immediate') {
            'daily' => $this->nextDaily($now),
            'weekly' => $this->nextWeekly($now),
            default => $now->copy(),
        };

        if ($preference?->quiet_hours_start && $preference?->quiet_hours_end) {
            $candidate = $this->deferOutOfQuietHours(
                $candidate, $preference->quiet_hours_start, $preference->quiet_hours_end
            );
        }

        return $candidate->equalTo($now) ? null : $candidate->setTimezone(config('app.timezone'));
    }

    private function nextDaily(Carbon $now): Carbon
    {
        $today = $now->copy()->setTimeFromTimeString(self::DAILY_DIGEST_TIME);

        return $now->lessThan($today) ? $today : $today->addDay();
    }

    private function nextWeekly(Carbon $now): Carbon
    {
        [$day, $time] = self::WEEKLY_DIGEST_DAY_TIME;
        $next = $now->copy()->is($day)
            ? $now->copy()->setTimeFromTimeString($time)
            : $now->copy()->next($day)->setTimeFromTimeString($time);

        return $next->greaterThan($now) ? $next : $next->addWeek();
    }

    /**
     * Quiet windows may wrap midnight ("22:00" → "06:30"). If the delivery
     * time falls inside the window, defer to the window's end.
     */
    private function deferOutOfQuietHours(Carbon $candidate, string $start, string $end): Carbon
    {
        $startAt = $candidate->copy()->setTimeFromTimeString($start);
        $endAt = $candidate->copy()->setTimeFromTimeString($end);

        if ($startAt->lessThanOrEqualTo($endAt)) {
            // Same-day window, e.g. 09:00–17:00.
            return $candidate->betweenIncluded($startAt, $endAt) ? $endAt : $candidate;
        }

        // Wrapping window, e.g. 22:00–06:30.
        if ($candidate->greaterThanOrEqualTo($startAt)) {
            return $endAt->addDay();
        }

        return $candidate->lessThan($endAt) ? $endAt : $candidate;
    }
}
