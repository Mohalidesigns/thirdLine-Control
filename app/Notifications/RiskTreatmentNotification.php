<?php

namespace App\Notifications;

use App\Models\RiskTreatment;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Hand-offs on a treatment plan (10.4): a proposed plan goes to its
 * approvers, an approved or cancelled plan back to its owner, an
 * implemented plan to the independent verifiers, and a verified plan
 * back to its owner.
 */
class RiskTreatmentNotification extends PreferenceRoutedNotification
{
    public const PROPOSED = 'proposed';

    public const APPROVED = 'approved';

    public const IMPLEMENTED = 'implemented';

    public const VERIFIED = 'verified';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public RiskTreatment $treatment,
        public string $kind,
        public string $actorName,
        public ?string $note = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $ref = $this->treatment->reference;
        $code = $this->treatment->risk?->code;

        $mail = (new MailMessage)->subject($this->headline());

        $mail = match ($this->kind) {
            self::PROPOSED => $mail
                ->line("{$this->actorName} has proposed treatment plan {$ref} ({$this->treatment->strategy}) for risk {$code}.")
                ->line('Review it, then approve it so the owner can start work.'),
            self::APPROVED => $mail
                ->line("{$this->actorName} has approved treatment plan {$ref}, which you own.")
                ->line('Start the plan and record progress against its milestones.'),
            self::IMPLEMENTED => $mail
                ->line("{$this->actorName} has marked treatment plan {$ref} as implemented.")
                ->line('Verify the work independently to close the plan and re-score the risk.'),
            self::VERIFIED => $mail
                ->line("{$this->actorName} has verified treatment plan {$ref}. Risk {$code} has been re-scored."),
            default => $mail
                ->line("{$this->actorName} has cancelled treatment plan {$ref}.")
                ->line('Reason: '.str($this->note)->limit(500)),
        };

        return $mail
            ->line('Plan: '.str($this->treatment->title)->limit(200))
            ->action('Open the plan', url(route('treatments.show', $this->treatment->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'treatment_'.$this->kind,
            'title' => $this->headline(),
            'treatment_id' => $this->treatment->id,
            'reference' => $this->treatment->reference,
            'url' => route('treatments.show', $this->treatment->id),
            'summary' => $this->headline().': '.str($this->treatment->title)->limit(120),
        ];
    }

    private function headline(): string
    {
        $ref = $this->treatment->reference;

        return match ($this->kind) {
            self::PROPOSED => "Treatment plan awaiting your approval — {$ref}",
            self::APPROVED => "Treatment plan approved — {$ref}",
            self::IMPLEMENTED => "Treatment plan awaiting your verification — {$ref}",
            self::VERIFIED => "Treatment plan verified — {$ref}",
            default => "Treatment plan cancelled — {$ref}",
        };
    }
}
