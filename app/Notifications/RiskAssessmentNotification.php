<?php

namespace App\Notifications;

use App\Models\RiskAssessment;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hand-offs on a risk assessment (10.1): a high-scoring assessment goes to
 * second-line review, the reviewer returns it (to the assessor) or
 * publishes it (to the assessor and the risk owner).
 */
class RiskAssessmentNotification extends PreferenceRoutedNotification
{
    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const PUBLISHED = 'published';

    public function __construct(
        public RiskAssessment $assessment,
        public string $kind,
        public string $actorName,
        public ?string $note = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $code = $this->assessment->risk?->code;
        $position = "{$this->assessment->assessment_type} {$this->assessment->score} ({$this->assessment->rating})";

        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::SUBMITTED => $mail
                ->line("{$this->actorName} has assessed risk {$code} at {$position}, which needs second-line review.")
                ->line('Review the scores and rationale, then publish it or return it to the assessor.'),
            self::RETURNED => $mail
                ->line("{$this->actorName} has returned your assessment of risk {$code}.")
                ->line('Reason: '.str($this->note)->limit(500))
                ->line('Revise the assessment and record it again.'),
            default => $mail
                ->line("{$this->actorName} has published the {$position} assessment of risk {$code}. It is now the risk's position."),
        };

        return $mail
            ->line('Risk: '.str($this->assessment->risk?->title)->limit(200))
            ->action('Open the risk', url(route('risks.show', $this->assessment->risk_id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'risk_assessment_'.$this->kind,
            'title' => $this->headline(),
            'risk_id' => $this->assessment->risk_id,
            'risk_assessment_id' => $this->assessment->id,
            'reference' => $this->assessment->risk?->code,
            'url' => route('risks.show', $this->assessment->risk_id),
            'summary' => $this->headline().': '.str($this->assessment->risk?->title)->limit(120),
        ];
    }

    private function headline(): string
    {
        $code = $this->assessment->risk?->code;

        return match ($this->kind) {
            self::SUBMITTED => "Risk assessment awaiting your review — {$code}",
            self::RETURNED => "Risk assessment returned to you — {$code}",
            default => "Risk assessment published — {$code}",
        };
    }
}
