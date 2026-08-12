<?php

namespace App\Services\Messaging\Sms;

/**
 * One aggregator behind one method (15.4). Implementations throw on
 * transport failure; SmsService turns that into a failed log row.
 */
interface SmsDriver
{
    /**
     * @return array{id: ?string, segments: ?int, cost_minor: ?int, cost_currency: ?string}
     */
    public function send(string $to, string $from, string $body): array;
}
