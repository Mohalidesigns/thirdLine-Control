<?php

namespace App\Notifications;

class ExceptionEscalationClosedNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Escalation closed — {$this->escalation->reference}";
    }

    protected function leadLine(): string
    {
        return 'The control function has validated the committed actions and closed this escalation.';
    }
}
