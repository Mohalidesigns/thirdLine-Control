<?php

namespace App\Notifications;

use App\Models\ReportRun;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A scheduled report has been generated (13.3). The link carries the run's
 * one-time token and expires — a board pack URL that outlives the meeting is
 * a leak waiting for a forwarded email.
 */
class ReportReadyNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(public ReportRun $run, public string $reportName) {}

    private function url(): string
    {
        return route('reports.runs.download', ['run' => $this->run->id, 'token' => $this->run->download_token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Report ready: {$this->reportName}")
            ->line("{$this->reportName} has been generated ({$this->run->run_ref}).")
            ->line('The download link expires on '.($this->run->expires_at?->format('d M Y H:i') ?? 'the retention date').'.')
            ->action('Download report', $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_ready',
            'run_id' => $this->run->id,
            'run_ref' => $this->run->run_ref,
            'report' => $this->reportName,
            'format' => $this->run->output_format,
            'expires_at' => $this->run->expires_at?->toIso8601String(),
            'url' => $this->url(),
        ];
    }
}
