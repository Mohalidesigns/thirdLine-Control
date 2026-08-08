<?php

namespace App\Services\Ai\Exceptions;

/**
 * R4, made structural. Raised when something attempts to apply a draft
 * that no human has accepted, or to apply one twice. This is the exception
 * that turns "AI never decides" from a convention into a wall.
 */
class AiDecisionRequiredException extends AiException
{
    public function status(): string
    {
        return 'Failed';
    }
}
