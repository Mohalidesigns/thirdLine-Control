<?php

namespace App\Notifications;

class ExceptionResponseReminderNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Response reminder — {$this->escalation->reference} [{$this->escalation->severity}]";
    }

    protected function leadLine(): string
    {
        return 'A departmental response to an issued exception is still outstanding.';
    }
}
