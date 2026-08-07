<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

/**
 * Periodic digest listing a control owner's open and overdue items (FR-8.6).
 */
class OwnerDigestNotification extends PreferenceRoutedNotification
{
    public array $resolvedChannels = ['database', 'mail'];

    public function __construct(public Collection $openExceptions) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your open control exceptions — weekly digest')
            ->line('You have '.$this->openExceptions->count().' open exception(s), of which '
                .$this->openExceptions->where('is_overdue', true)->count().' are overdue.');

        foreach ($this->openExceptions->take(10) as $exception) {
            $mail->line(sprintf(
                '%s [%s] %s — due %s%s',
                $exception->reference,
                $exception->severity,
                str($exception->title)->limit(60),
                $exception->target_closure_date?->format('d M Y') ?? '—',
                $exception->is_overdue ? ' (OVERDUE)' : '',
            ));
        }

        return $mail->action('Open my actions', url('/my-actions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'owner_digest',
            'open' => $this->openExceptions->count(),
            'overdue' => $this->openExceptions->where('is_overdue', true)->count(),
        ];
    }
}
