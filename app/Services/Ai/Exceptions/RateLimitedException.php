<?php

namespace App\Services\Ai\Exceptions;

/**
 * Per-user, per-tenant or per-capability rate limit hit. Transient by
 * definition, so the message tells the person when to try again rather
 * than implying the feature is broken.
 */
class RateLimitedException extends AiException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct($message);
    }

    public function status(): string
    {
        return 'Rate Limited';
    }
}
