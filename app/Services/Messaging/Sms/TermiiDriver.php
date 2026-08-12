<?php

namespace App\Services\Messaging\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Termii (https://termii.com) — Nigerian SMS aggregator. */
class TermiiDriver implements SmsDriver
{
    public function send(string $to, string $from, string $body): array
    {
        $response = Http::acceptJson()
            ->post(rtrim(config('messaging.sms.termii.base_url'), '/').'/api/sms/send', [
                'api_key' => config('messaging.sms.termii.api_key'),
                'to' => $to,
                'from' => $from,
                'sms' => $body,
                'type' => 'plain',
                'channel' => 'generic',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Termii HTTP '.$response->status().': '.substr($response->body(), 0, 300));
        }

        return [
            'id' => $response->json('message_id'),
            'segments' => null,
            'cost_minor' => null,
            'cost_currency' => null,
        ];
    }
}
