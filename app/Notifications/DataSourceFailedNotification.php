<?php

namespace App\Notifications;

use App\Models\DataSource;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The circuit breaker tripped on a data source (12.1). Sent to the source
 * owner: a silently dead feed is worse than no feed, because the control
 * looks monitored and is not.
 *
 * The notification names the source and the failure count and stops there
 * — the underlying error can carry a hostname or a driver string, and a
 * mailbox is a less controlled channel than the sync log (R9).
 */
class DataSourceFailedNotification extends PreferenceRoutedNotification
{
    public function __construct(
        public DataSource $source,
        public int $failures,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Data source paused — {$this->source->name}")
            ->error()
            ->line("{$this->source->name} has failed {$this->failures} extraction(s) in a row and has been paused.")
            ->line('Every monitoring rule reading from it is now dormant, so the controls it tests are unmonitored.')
            ->line('Open the source in SecondLine to see the sync log and resume it once the cause is fixed.')
            ->action('Review data sources', route('admin.data-sources.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'data_source_failed',
            'severity' => 'high',
            'data_source_id' => $this->source->id,
            'data_source' => $this->source->name,
            'consecutive_failures' => $this->failures,
            'url' => route('admin.data-sources.show', $this->source),
        ];
    }
}
