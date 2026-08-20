<?php

namespace App\Services\Messaging;

use App\Models\MessageLog;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\Messaging\Exceptions\BlockedContentException;
use App\Services\Messaging\Sms\AfricasTalkingDriver;
use App\Services\Messaging\Sms\LogDriver;
use App\Services\Messaging\Sms\SmsDriver;
use App\Services\Messaging\Sms\TermiiDriver;
use Throwable;

/**
 * SMS fallback for recipients without WhatsApp (15.4): templated,
 * receipted and cost-tracked per tenant in minor units (R7). Same
 * notification-and-link-only guard as WhatsApp — an SMS body traverses
 * the aggregator's infrastructure too.
 */
class SmsService
{
    /** GSM-7 single-segment length; concatenated segments carry 153. */
    private const SEGMENT_LENGTH = 160;

    private const CONCAT_SEGMENT_LENGTH = 153;

    public function __construct(private OutboundContentGuard $guard) {}

    public function send(
        int $tenantId,
        ?User $user,
        string $phone,
        string $templateKey,
        array $variables = [],
        ?string $link = null,
    ): MessageLog {
        $log = fn (array $attributes) => MessageLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'channel' => 'sms',
            'direction' => 'out',
            'template_key' => $templateKey,
            'recipient_masked' => MessageLog::mask($phone),
            ...$attributes,
        ]);

        try {
            $this->guard->assert($variables + ($link !== null ? ['link' => $link] : []), $link);
        } catch (BlockedContentException $e) {
            return $log(['status' => 'skipped', 'error' => $e->getMessage()]);
        }

        $template = SmsTemplate::forKey($templateKey, $tenantId);

        if (! $template?->is_active) {
            return $log([
                'status' => 'skipped',
                'error' => $template
                    ? "SMS template '{$templateKey}' is inactive."
                    : "No SMS template found for key '{$templateKey}'.",
            ]);
        }

        $body = $template->render([...$variables, 'link' => $link]);
        $segments = $this->segments($body);

        try {
            $result = $this->driver()->send($phone, (string) config('messaging.sms.sender_id'), $body);
        } catch (Throwable $e) {
            return $log(['status' => 'failed', 'segments' => $segments, 'error' => substr($e->getMessage(), 0, 500)]);
        }

        return $log([
            'status' => 'sent',
            'provider_message_id' => $result['id'],
            'segments' => $result['segments'] ?? $segments,
            // Estimated at the configured per-segment rate; a delivery
            // receipt carrying actual cost overwrites the estimate.
            'cost_minor' => $result['cost_minor']
                ?? ($result['segments'] ?? $segments) * (int) config('messaging.sms.cost_per_segment_minor'),
            'cost_currency' => $result['cost_currency'] ?? config('messaging.sms.cost_currency'),
        ]);
    }

    /** Delivery-receipt webhook applies the aggregator's verdict. */
    public function applyReceipt(string $providerMessageId, string $status, ?int $costMinor = null, ?string $currency = null): bool
    {
        $entry = MessageLog::withoutGlobalScope('tenant')
            ->where('channel', 'sms')
            ->where('provider_message_id', $providerMessageId)
            ->latest('id')
            ->first();

        if (! $entry) {
            return false;
        }

        $entry->update([
            'status' => in_array($status, ['delivered', 'failed'], true) ? $status : $entry->status,
            'delivered_at' => $status === 'delivered' ? now() : $entry->delivered_at,
            'cost_minor' => $costMinor ?? $entry->cost_minor,
            'cost_currency' => $currency ?? $entry->cost_currency,
        ]);

        return true;
    }

    public function segments(string $body): int
    {
        $length = mb_strlen($body);

        return $length <= self::SEGMENT_LENGTH
            ? 1
            : (int) ceil($length / self::CONCAT_SEGMENT_LENGTH);
    }

    private function driver(): SmsDriver
    {
        return match (config('messaging.sms.driver')) {
            'termii' => new TermiiDriver,
            'africastalking' => new AfricasTalkingDriver,
            default => new LogDriver,
        };
    }
}
