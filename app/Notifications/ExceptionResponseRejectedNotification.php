<?php

namespace App\Notifications;

/** The response was rejected and the escalation re-issued for a new round. */
class ExceptionResponseRejectedNotification extends ExceptionEscalationNotification
{
    protected function subjectLine(): string
    {
        return "Response rejected — {$this->escalation->reference} re-issued (round {$this->escalation->round_no})";
    }

    protected function leadLine(): string
    {
        return 'The control function has rejected your response and re-issued the exception. A fresh response is required by the new deadline.';
    }
}
