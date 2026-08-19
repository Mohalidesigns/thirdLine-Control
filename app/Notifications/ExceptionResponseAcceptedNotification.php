<?php

namespace App\Notifications;

class ExceptionResponseAcceptedNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Response accepted — {$this->escalation->reference}";
    }

    protected function leadLine(): string
    {
        return 'The control function has accepted your response. The committed actions are now tracked to completion and validation.';
    }
}
