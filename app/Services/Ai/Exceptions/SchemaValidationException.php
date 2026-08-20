<?php

namespace App\Services\Ai\Exceptions;

/**
 * The model's reply did not match the prompt's output_schema after the
 * configured repair attempt. The draft is discarded rather than shown:
 * a half-parsed suggestion is worse than none, because a reviewer cannot
 * tell which half to trust.
 */
class SchemaValidationException extends AiException
{
    /** @param array<int, string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public function status(): string
    {
        return 'Rejected by Schema';
    }

    public function userMessage(): string
    {
        return 'The assistant returned a response that did not match the expected format. Nothing has been saved — please try again.';
    }
}
