<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Base for every failure the AI layer raises.
 *
 * `status` is the value written to ai_interactions.status, so a failure is
 * recorded as the specific thing that went wrong rather than a generic
 * error — "we never called the model because the budget was gone" and "the
 * model answered nonsense" are different facts a governance review needs to
 * tell apart.
 *
 * `userMessage` is what a person sees. It never carries a provider
 * response body, a URL or anything derived from configuration, because
 * those are the routes by which an API key ends up in a flash message (R9).
 */
abstract class AiException extends RuntimeException
{
    abstract public function status(): string;

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
