<?php

namespace App\Notifications;

use App\Models\Investigation;

/**
 * CR-04: you are on an investigation team. Worth its own notification
 * because on this module team membership is not only a work assignment —
 * it is what gives you sight of the case file at all.
 */
class InvestigationAssignedNotification extends InvestigationNotification
{
    public function __construct(Investigation $investigation, public string $role = 'investigator')
    {
        parent::__construct($investigation);
    }

    protected function subjectLine(): string
    {
        return "You have been assigned to investigation {$this->investigation->reference}";
    }

    protected function leadLine(): string
    {
        return 'You have been named on an investigation team as '
            .str_replace('_', ' ', $this->role)
            .'. Being on the team is also what gives you sight of the case file.';
    }

    protected function payloadType(): string
    {
        return 'investigation_assigned';
    }
}
