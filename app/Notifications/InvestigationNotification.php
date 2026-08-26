<?php

namespace App\Notifications;

use App\Models\Investigation;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Base for the CR-04 notifications.
 *
 * One rule governs all of them: a notification about a CONFIDENTIAL
 * investigation carries its reference and nothing else. Not the title, not
 * the category, not the department. A confidential case whose subject
 * matter arrives in an email preview on somebody's lock screen is not
 * confidential, and the notification body is the easiest place in the
 * product to forget that.
 */
abstract class InvestigationNotification extends PreferenceRoutedNotification
{
    public function __construct(public Investigation $investigation) {}

    abstract protected function subjectLine(): string;

    abstract protected function leadLine(): string;

    /** @return array<int, string> */
    protected function detailLines(): array
    {
        return [];
    }

    abstract protected function payloadType(): string;

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subjectLine())
            ->line($this->leadLine());

        if ($this->investigation->is_confidential) {
            $message->line('This is a confidential investigation, so no detail is carried in this message. Open the case file to read it — your opening of it will be recorded.');
        } else {
            $message->line("{$this->investigation->reference} — {$this->investigation->title}");

            foreach ($this->detailLines() as $line) {
                $message->line($line);
            }
        }

        return $message->action('Open the investigation', url(route('investigations.show', $this->investigation->id, false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->payloadType(),
            'investigation_id' => $this->investigation->id,
            'reference' => $this->investigation->reference,
            'is_confidential' => (bool) $this->investigation->is_confidential,
            // Withheld rather than absent: a reader should know there is a
            // title and that they were not sent it.
            'title' => $this->investigation->is_confidential ? null : $this->investigation->title,
            'summary' => $this->leadLine(),
        ];
    }
}
