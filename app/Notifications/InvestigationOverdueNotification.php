<?php

namespace App\Notifications;

/**
 * CR-04: an investigation has passed the date it said it would finish.
 * Sent to the lead, weekly, until it is completed or suspended.
 */
class InvestigationOverdueNotification extends InvestigationNotification
{
    protected function subjectLine(): string
    {
        return "Investigation {$this->investigation->reference} is past its target date";
    }

    protected function leadLine(): string
    {
        $days = (int) now()->startOfDay()->diffInDays($this->investigation->target_completion_date, absolute: true);

        return "An investigation you lead passed its target completion date {$days} day(s) ago and is still open.";
    }

    protected function payloadType(): string
    {
        return 'investigation_overdue';
    }
}
