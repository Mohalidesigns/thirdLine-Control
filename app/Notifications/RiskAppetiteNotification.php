<?php

namespace App\Notifications;

use App\Models\Risk;
use App\Models\RiskAppetite;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Risk appetite hand-offs (10.3): a statement submitted for approval goes
 * to the control function heads and the board tier, an approved one back
 * to its author, and a risk newly outside appetite to the risk's owner.
 */
class RiskAppetiteNotification extends PreferenceRoutedNotification
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const BREACHED = 'breached';

    public function __construct(
        public RiskAppetite $appetite,
        public string $kind,
        public string $actorName,
        public ?Risk $risk = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->headline());

        if ($this->kind === self::BREACHED) {
            return $mail
                ->line("Risk {$this->risk->code}, which you own, is now outside risk appetite: score {$this->risk->currentScore()} against a tolerance of {$this->appetite->tolerance_upper}.")
                ->line('Agree a treatment plan to bring it back within tolerance.')
                ->line('Risk: '.str($this->risk->title)->limit(200))
                ->action('Open the risk', url(route('risks.show', $this->risk->id, false)));
        }

        $mail = $this->kind === self::SUBMITTED
            ? $mail
                ->line("{$this->actorName} has submitted appetite statement v{$this->appetite->version_no} for approval.")
                ->line('Review it, then approve it to make it the active statement.')
            : $mail
                ->line("{$this->actorName} has approved appetite statement v{$this->appetite->version_no}. It is now active.");

        return $mail
            ->line('Statement: '.str($this->appetite->statement)->limit(300))
            ->action('Open risk appetite', url(route('appetite.index', [], false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'appetite_'.$this->kind,
            'title' => $this->headline(),
            'risk_appetite_id' => $this->appetite->id,
            'risk_id' => $this->risk?->id,
            'reference' => $this->risk?->code,
            'url' => $this->risk ? route('risks.show', $this->risk->id) : route('appetite.index'),
            'summary' => $this->headline().': '.str($this->risk?->title ?? $this->appetite->statement)->limit(120),
        ];
    }

    private function headline(): string
    {
        return match ($this->kind) {
            self::SUBMITTED => "Appetite statement awaiting your approval — v{$this->appetite->version_no}",
            self::APPROVED => "Appetite statement approved — v{$this->appetite->version_no}",
            default => "Risk outside appetite — {$this->risk?->code}",
        };
    }
}
