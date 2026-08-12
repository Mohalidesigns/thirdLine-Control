<?php

namespace App\Services\Messaging\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Africa's Talking (https://africastalking.com) — pan-African aggregator. */
class AfricasTalkingDriver implements SmsDriver
{
    public function send(string $to, string $from, string $body): array
    {
        $response = Http::acceptJson()
            ->asForm()
            ->withHeaders(['apiKey' => (string) config('messaging.sms.africastalking.api_key')])
            ->post(rtrim(config('messaging.sms.africastalking.base_url'), '/').'/version1/messaging', [
                'username' => config('messaging.sms.africastalking.username'),
                'to' => $to,
                'from' => $from,
                'message' => $body,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Africa's Talking HTTP ".$response->status().': '.substr($response->body(), 0, 300));
        }

        $recipient = $response->json('SMSMessageData.Recipients.0', []);

        return [
            'id' => $recipient['messageId'] ?? null,
            'segments' => null,
            // Cost arrives as "NGN 4.0000" — normalised by SmsService.
            'cost_minor' => null,
            'cost_currency' => null,
        ];
    }
}
