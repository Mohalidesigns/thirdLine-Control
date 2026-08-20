<?php

namespace App\Notifications;

/**
 * A formal exception notice addressed to you (CR1.2). Not muteable — a
 * department cannot silence an exception addressed to it.
 */
class ExceptionEscalationIssuedNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Exception issued to your department — {$this->escalation->reference} [{$this->escalation->severity}]";
    }

    protected function leadLine(): string
    {
        return 'The control function has issued a control exception to you for a formal departmental response.';
    }
}
