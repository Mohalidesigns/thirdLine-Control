<?php

namespace App\Services\Ai\Exceptions;

/**
 * The capability has no ai_configurations row for this tenant, or the row
 * is switched off. Not an error condition — a fresh installation ships
 * with every capability dormant, so this is the normal answer until an
 * administrator turns one on.
 */
class CapabilityDisabledException extends AiException
{
    public function status(): string
    {
        return 'Unavailable';
    }
}
