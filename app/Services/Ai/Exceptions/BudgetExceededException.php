<?php

namespace App\Services\Ai\Exceptions;

/**
 * The tenant's period budget is spent and hard_stop is on. Thrown BEFORE
 * any request leaves the building, so nothing is billed and nothing is
 * sent — the interaction is recorded as Budget Exceeded with no payload.
 */
class BudgetExceededException extends AiException
{
    public function status(): string
    {
        return 'Budget Exceeded';
    }
}
