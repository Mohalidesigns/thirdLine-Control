<?php

namespace App\Notifications;

class ExceptionAcknowledgementDueNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Acknowledgement outstanding — {$this->escalation->reference}";
    }

    protected function leadLine(): string
    {
        return 'An exception issued to you has not yet been acknowledged and its acknowledgement window is closing.';
    }
}
