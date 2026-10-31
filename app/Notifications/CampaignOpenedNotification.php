<?php

namespace App\Notifications;

use App\Models\CsaResponse;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The invitation to respond (9.3–9.5): a self-assessment, survey or
 * attestation campaign has opened with the recipient in scope. Without it
 * the first a respondent hears is the overdue escalation.
 */
class CampaignOpenedNotification extends PreferenceRoutedNotification
{
    public function __construct(public CsaResponse $response) {}

    public function toMail(object $notifiable): MailMessage
    {
        $campaign = $this->response->campaign;

        $mail = (new MailMessage)
            ->subject($this->headline())
            ->line($this->ask().' — '.$campaign->name.' ('.$campaign->reference.').');

        if ($campaign->closes_at) {
            $mail->line('Please respond by '.$campaign->closes_at->format('d M Y').'.');
        }

        return $mail->action($this->isAttestation() ? 'Open my attestations' : 'Open my response', $this->link());
    }

    public function toArray(object $notifiable): array
    {
        $campaign = $this->response->campaign;

        return [
            'type' => 'campaign_opened',
            'title' => $this->headline(),
            'campaign_id' => $campaign->id,
            'reference' => $campaign->reference,
            'url' => $this->link(),
            'summary' => $this->ask().': '.str($campaign->name)->limit(120),
        ];
    }

    private function isAttestation(): bool
    {
        return $this->response->campaign->campaign_type === 'attestation';
    }

    private function ask(): string
    {
        return match ($this->response->campaign->campaign_type) {
            'attestation' => 'You are asked to read and attest',
            'survey' => 'You are asked to complete a survey',
            default => 'You are asked to complete a control self-assessment',
        };
    }

    private function headline(): string
    {
        $ref = $this->response->campaign->reference;

        return match ($this->response->campaign->campaign_type) {
            'attestation' => "Attestation awaiting your signature — {$ref}",
            'survey' => "Survey awaiting your response — {$ref}",
            default => "Self-assessment awaiting your response — {$ref}",
        };
    }

    private function link(): string
    {
        return url($this->isAttestation()
            ? route('attestations.index', [], false)
            : route('csa-responses.show', $this->response->id, false));
    }
}
