<?php

namespace App\Notifications;

/** To the control function: a department has answered on the record. */
class ExceptionResponseSubmittedNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Departmental response received — {$this->escalation->reference} (round {$this->escalation->round_no})";
    }

    protected function leadLine(): string
    {
        return $this->escalation->targetLabel().' has submitted its response and it is awaiting your review.';
    }
}
