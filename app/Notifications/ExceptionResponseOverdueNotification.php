<?php

namespace App\Notifications;

class ExceptionResponseOverdueNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Response OVERDUE — {$this->escalation->reference} [{$this->escalation->severity}]";
    }

    protected function leadLine(): string
    {
        return 'The response deadline on an exception issued to you has passed. Unanswered escalations climb the escalation ladder.';
    }
}
