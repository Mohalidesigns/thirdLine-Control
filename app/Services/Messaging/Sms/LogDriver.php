<?php

namespace App\Services\Messaging\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default until an aggregator account exists: writes to the Laravel
 * log, never the network. Local dev and tests run against this.
 */
class LogDriver implements SmsDriver
{
    public function send(string $to, string $from, string $body): array
    {
        Log::info('SMS (log driver — not transmitted)', [
            'to' => '***'.substr($to, -3),
            'from' => $from,
            'body' => $body,
        ]);

        // Costs stay null so SmsService applies the configured per-segment
        // estimate — the log driver should exercise the same accounting a
        // real aggregator would.
        return [
            'id' => 'log-'.Str::uuid(),
            'segments' => null,
            'cost_minor' => null,
            'cost_currency' => null,
        ];
    }
}
