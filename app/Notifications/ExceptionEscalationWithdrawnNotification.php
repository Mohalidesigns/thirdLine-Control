<?php

namespace App\Notifications;

class ExceptionEscalationWithdrawnNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Escalation withdrawn — {$this->escalation->reference}";
    }

    protected function leadLine(): string
    {
        return 'The control function has withdrawn this escalation. No further response is required from you.';
    }
}
