<?php

namespace App\Notifications;

/**
 * CR-04: the investigation is complete and rated, and its draft report has
 * been generated. Not muteable — a completed investigation carries outcomes
 * recorded against named people, and everyone on the team is answerable for
 * them.
 */
class InvestigationCompletedNotification extends InvestigationNotification
{
    protected function subjectLine(): string
    {
        return "Investigation {$this->investigation->reference} completed — rated {$this->investigation->risk_rating}";
    }

    protected function leadLine(): string
    {
        return "An investigation you are on has been completed and rated {$this->investigation->risk_rating}. A draft report has been generated for review.";
    }

    protected function detailLines(): array
    {
        return [
            'Subjects: '.$this->investigation->subjects()->count()
                .' · Findings: '.$this->investigation->findings()->count()
                .' · Consequences: '.$this->investigation->consequenceActions()->count(),
        ];
    }

    protected function payloadType(): string
    {
        return 'investigation_completed';
    }
}
