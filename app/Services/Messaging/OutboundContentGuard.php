<?php

namespace App\Services\Messaging;

use App\Services\Messaging\Exceptions\BlockedContentException;

/**
 * The NDPA enforcement point for WhatsApp and SMS (15.3): message content
 * traverses Meta/aggregator infrastructure, so an outbound message may
 * carry a notification and a link — NEVER customer personal data or
 * confidential case content. Architectural, not conventional: every
 * channel driver passes its variables through here before any HTTP call,
 * the same way AiGateway owns R4.
 */
class OutboundContentGuard
{
    /** A template variable longer than this is substance, not a label. */
    private const MAX_VARIABLE_LENGTH = 120;

    /**
     * Digit runs at or above this length (separators collapsed) look like
     * phone numbers (11+), NUBAN accounts (10) or BVN/NIN (11) — an ISO
     * date collapses to 8 and stays deliverable.
     */
    private const MAX_DIGIT_RUN = 9;

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws BlockedContentException
     */
    public function assert(array $variables, ?string $link = null): void
    {
        foreach ($variables as $name => $value) {
            $value = (string) $value;

            if (mb_strlen($value) > self::MAX_VARIABLE_LENGTH) {
                $this->block("variable '{$name}' exceeds ".self::MAX_VARIABLE_LENGTH.' characters — send a link, not the substance');
            }

            if (preg_match('/[^@\s]+@[^@\s]+\.[^@\s]+/', $value)) {
                $this->block("variable '{$name}' contains an email address");
            }

            if (preg_match('/\d(?:[\s\-.]?\d){'.(self::MAX_DIGIT_RUN - 1).',}/', $value)) {
                $this->block("variable '{$name}' contains a long digit sequence (possible phone, account or identity number)");
            }
        }

        if ($link !== null && ! str_starts_with($link, rtrim((string) config('app.url'), '/'))) {
            $this->block('links must point back into the platform');
        }
    }

    private function block(string $reason): never
    {
        throw new BlockedContentException('Outbound message blocked: '.$reason);
    }
}
