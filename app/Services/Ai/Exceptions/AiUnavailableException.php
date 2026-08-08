<?php

namespace App\Services\Ai\Exceptions;

/**
 * No API key configured, or the provider could not be reached after the
 * configured retries.
 *
 * userMessage() is deliberately fixed text. The provider's own error body
 * can echo request headers, and an echoed Authorization header rendered
 * into a flash message is how an API key leaves a system (R9). The detail
 * goes to the interaction's error_message, which AnthropicClient has
 * already scrubbed.
 */
class AiUnavailableException extends AiException
{
    public function status(): string
    {
        return 'Unavailable';
    }

    public function userMessage(): string
    {
        return 'The AI assistant is not available right now. Please try again shortly.';
    }
}
